<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplatePreviewMail;
use App\Models\Announcement;
use App\Models\MailTemplate;
use App\Support\Brand;
use App\Support\MailTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Administración de las plantillas de correo: qué correos se envían y cómo se ven (solo Superadministrador). */
class MailTemplateController extends Controller
{
    public function index(): Response
    {
        $groups = [];
        foreach (MailTemplates::definitions() as $key => $def) {
            $current = MailTemplates::current($key);
            $groups[$def['group']][] = [
                'key' => $key,
                'label' => $def['label'],
                'description' => $def['description'],
                'audience' => $def['audience'],
                'motive' => $def['motive'],
                'locked' => $def['locked'],
                'custom' => $def['custom'] ?? false,
                'enabled' => $current['enabled'],
                'customized' => $current['customized'],
            ];
        }

        // Respeta el orden definido en la configuración.
        $ordered = [];
        foreach (config('mail_templates.groups') as $group) {
            if (isset($groups[$group])) {
                $ordered[$group] = $groups[$group];
            }
        }

        return Inertia::render('Admin/MailTemplates', ['groups' => $ordered]);
    }

    public function edit(string $key): Response
    {
        $def = MailTemplates::definition($key);
        $current = MailTemplates::current($key);

        $variables = collect($def['variables'])->map(fn ($v, $name) => ['name' => $name, 'label' => $v['label']])->values()->all();
        $common = collect(config('mail_templates.common_variables'))->map(fn ($label, $name) => ['name' => $name, 'label' => $label])->values()->all();

        return Inertia::render('Admin/MailTemplateEdit', [
            'template' => [
                'key' => $key,
                'label' => $def['label'],
                'description' => $def['description'],
                'audience' => $def['audience'],
                'motive' => $def['motive'],
                'locked' => $def['locked'],
                'custom' => $def['custom'] ?? false,
                'enabled' => $current['enabled'],
                'customized' => $current['customized'],
                'subject' => $current['subject'],
                'body' => $current['body'],
                'defaultSubject' => ($def['custom'] ?? false) ? MailTemplates::starter()['subject'] : $def['subject'],
                'defaultBody' => ($def['custom'] ?? false) ? MailTemplates::starter()['body'] : $def['body'],
                'variables' => array_merge($variables, $common),
                // ¿Hay un WhatsApp configurado para el bloque de contacto de este correo?
                'whatsappReady' => $def['motive'] === null ? null : Brand::whatsapp($def['motive']) !== null,
            ],
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $def = MailTemplates::definition($key);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'label' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        if ($def['custom'] ?? false) {
            // Plantilla propia: lo que se guarda es la plantilla misma (no hay un original al que volver).
            MailTemplate::where('key', $key)->update([
                'subject' => $data['subject'],
                'body' => $this->normalize($data['body']),
                'label' => $data['label'] ?? $def['label'],
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
                'updated_by' => $request->user()->id,
            ]);
            activity('mail')->causedBy($request->user())->withProperties(['template' => $key])->log("Plantilla de correo editada: {$def['label']}");

            return back()->with('success', 'Plantilla guardada.');
        }

        $row = MailTemplate::firstOrNew(['key' => $key]);
        // Si queda igual al original no se guarda nada: así los cambios futuros del sistema siguen aplicando.
        $row->subject = $data['subject'] === $def['subject'] ? null : $data['subject'];
        $row->body = $this->normalize($data['body']) === $this->normalize($def['body']) ? null : $this->normalize($data['body']);
        $row->updated_by = $request->user()->id;
        $row->save();

        activity('mail')->causedBy($request->user())->withProperties(['template' => $key])->log("Plantilla de correo editada: {$def['label']}");

        return back()->with('success', 'Plantilla guardada.');
    }

    public function toggle(Request $request, string $key): RedirectResponse
    {
        $def = MailTemplates::definition($key);
        if ($def['locked']) {
            throw ValidationException::withMessages(['enabled' => 'Este correo es imprescindible y no se puede desactivar.']);
        }

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $row = MailTemplate::firstOrNew(['key' => $key]);
        $row->enabled = $data['enabled'];
        $row->updated_by = $request->user()->id;
        $row->save();

        activity('mail')->causedBy($request->user())->withProperties(['template' => $key, 'enabled' => $data['enabled']])
            ->log(($data['enabled'] ? 'Correo activado: ' : 'Correo desactivado: ').$def['label']);

        return back()->with('success', $data['enabled'] ? 'Correo activado.' : 'Correo desactivado: no se enviará.');
    }

    /** Crea una plantilla propia con el texto inicial y abre el editor. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        $base = 'custom.'.(Str::slug($data['label']) ?: 'plantilla');
        $key = $base;
        for ($i = 2; MailTemplate::where('key', $key)->exists(); $i++) {
            $key = "{$base}-{$i}";
        }

        MailTemplate::create([
            'key' => $key,
            'is_custom' => true,
            'enabled' => true,
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'subject' => MailTemplates::starter()['subject'],
            'body' => MailTemplates::starter()['body'],
            'updated_by' => $request->user()->id,
        ]);

        activity('mail')->causedBy($request->user())->withProperties(['template' => $key])->log("Plantilla de correo creada: {$data['label']}");

        return redirect()->route('admin.mail.edit', $key)->with('success', 'Plantilla creada. Editá el texto y probala con la vista previa.');
    }

    /** Elimina una plantilla propia; los comunicados que la usaban vuelven a la estándar. */
    public function destroy(Request $request, string $key): RedirectResponse
    {
        abort_unless(MailTemplates::isCustom($key) && MailTemplate::where('key', $key)->where('is_custom', true)->exists(), 404);

        $label = MailTemplates::definition($key)['label'];
        Announcement::where('template_key', $key)->update(['template_key' => null]);
        MailTemplate::where('key', $key)->delete();

        activity('mail')->causedBy($request->user())->withProperties(['template' => $key])->log("Plantilla de correo eliminada: {$label}");

        return redirect()->route('admin.mail')->with('success', 'Plantilla eliminada.');
    }

    /** Vuelve el texto al original (conserva si el correo está activo o no). */
    public function reset(Request $request, string $key): RedirectResponse
    {
        if (MailTemplates::isCustom($key)) {
            throw ValidationException::withMessages(['reset' => 'Las plantillas propias no tienen un original: editá el texto o eliminá la plantilla.']);
        }
        MailTemplates::definition($key);

        MailTemplate::where('key', $key)->update(['subject' => null, 'body' => null, 'updated_by' => $request->user()->id]);

        activity('mail')->causedBy($request->user())->withProperties(['template' => $key])->log('Plantilla de correo restaurada al original');

        return back()->with('success', 'Plantilla restaurada al texto original.');
    }

    /** Vista previa sin guardar: devuelve el HTML tal como lo recibe la persona, con datos de ejemplo. */
    public function preview(Request $request, string $key): JsonResponse
    {
        MailTemplates::definition($key);
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        $vars = MailTemplates::sampleVars($key);

        return response()->json([
            'subject' => MailTemplates::render($key, $vars, $data['subject'] ?? null, $data['body'] ?? null)['subject'],
            'html' => MailTemplates::html($key, $vars, $data['subject'] ?? null, $data['body'] ?? null),
        ]);
    }

    /** Envía la plantilla (con datos de ejemplo) al correo de quien está logueado. */
    public function test(Request $request, string $key): RedirectResponse
    {
        MailTemplates::definition($key);
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        Mail::to($request->user()->email)->send(new TemplatePreviewMail($key, $data['subject'] ?? null, $data['body'] ?? null));

        return back()->with('success', "Correo de prueba enviado a {$request->user()->email}.");
    }

    private function normalize(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
    }
}
