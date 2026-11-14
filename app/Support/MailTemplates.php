<?php

namespace App\Support;

use App\Models\MailTemplate;
use Illuminate\Mail\Markdown;

/**
 * Plantillas de correo editables: define qué correos existen (config/mail_templates.php), si están activos y
 * cómo se arma cada uno (asunto + cuerpo Markdown con {variables} y botones) dentro del diseño con la marca de Laia.
 */
class MailTemplates
{
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $all = config('mail_templates.templates');

        try {
            foreach (MailTemplate::where('is_custom', true)->orderBy('label')->get() as $row) {
                $all[$row->key] = self::customDefinition($row);
            }
        } catch (\Throwable) {
            // base aún no disponible (migraciones): solo las plantillas del sistema
        }

        return $all;
    }

    public static function exists(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    public static function isCustom(string $key): bool
    {
        return str_starts_with($key, 'custom.');
    }

    /** Texto inicial de una plantilla nueva. */
    public static function starter(): array
    {
        return config('mail_templates.custom_starter');
    }

    /** Las plantillas propias sirven para comunicados a clientes: mismas variables que «Comunicado a clientes». */
    private static function customDefinition(MailTemplate $row): array
    {
        return [
            'group' => 'Personalizadas',
            'label' => $row->label ?: 'Plantilla sin nombre',
            'description' => $row->description ?: 'Plantilla propia. Se elige al enviar un comunicado a clientes.',
            'audience' => 'cliente',
            'motive' => null,
            'locked' => false,
            'enabled' => true,
            'custom' => true,
            'subject' => $row->subject ?? self::starter()['subject'],
            'body' => $row->body ?? self::starter()['body'],
            'variables' => config('mail_templates.templates')['announcement.sent']['variables'],
        ];
    }

    /** @return array<string, mixed> */
    public static function definition(string $key): array
    {
        $def = self::definitions()[$key] ?? null;
        abort_if($def === null, 404, "Plantilla de correo desconocida: {$key}");

        return $def;
    }

    private static function override(string $key): ?MailTemplate
    {
        return MailTemplate::where('key', $key)->first();
    }

    /** ¿Este correo se envía? Los bloqueados (invitación, restablecer contraseña) siempre se envían. */
    public static function enabled(string $key): bool
    {
        $def = self::definition($key);
        if ($def['locked']) {
            return true;
        }

        try {
            $row = self::override($key);
        } catch (\Throwable) {
            return (bool) $def['enabled']; // base aún no disponible (migraciones): vale el valor por defecto
        }

        return $row?->enabled ?? (bool) $def['enabled'];
    }

    /** @return array{subject: string, body: string, enabled: bool, customized: bool} */
    public static function current(string $key): array
    {
        $def = self::definition($key);
        $row = self::override($key);

        return [
            'subject' => $row?->subject ?? $def['subject'],
            'body' => $row?->body ?? $def['body'],
            'enabled' => self::enabled($key),
            'customized' => $row !== null && ($row->subject !== null || $row->body !== null),
        ];
    }

    /** Variables comunes (marca) + las del correo, con los valores recibidos. */
    /** @param array<string, string|null> $vars */
    private static function values(string $key, array $vars): array
    {
        $contact = Brand::contact();

        return $vars + [
            'empresa' => Brand::shortName(),
            'sitio_web' => $contact['website'] ?? '',
            'email_contacto' => $contact['email'] ?? '',
            'telefono' => $contact['phone'] ?? '',
        ];
    }

    /** @return array<string, string> valores de ejemplo para la vista previa y el correo de prueba */
    public static function sampleVars(string $key): array
    {
        return collect(self::definition($key)['variables'])->map(fn ($v) => $v['sample'])->all();
    }

    /**
     * Reemplaza variables y arma botones/paneles. $subject/$body permiten previsualizar texto sin guardar.
     *
     * @param  array<string, string|null>  $vars
     * @return array{subject: string, body: string, motive: ?string}
     */
    public static function render(string $key, array $vars, ?string $subject = null, ?string $body = null): array
    {
        $def = self::definition($key);
        $current = self::current($key);
        $values = self::values($key, $vars);
        $raw = collect($def['variables'])->filter(fn ($v) => ! empty($v['raw']))->keys()->all();

        $subjectText = self::substitute($subject ?? $current['subject'], $values, $raw, escape: false);
        $subjectText = trim(preg_replace('/\s+/', ' ', strip_tags($subjectText)));

        $bodyText = self::substitute($body ?? $current['body'], $values, $raw, escape: true);
        $bodyText = self::components($bodyText);

        return ['subject' => $subjectText, 'body' => $bodyText, 'motive' => $def['motive']];
    }

    /** HTML final del correo (con estilos en línea), tal como lo recibe la persona. */
    public static function html(string $key, array $vars, ?string $subject = null, ?string $body = null): string
    {
        $rendered = self::render($key, $vars, $subject, $body);

        return (string) app(Markdown::class)->render('mail.template', [
            'bodyMarkdown' => $rendered['body'],
            'motive' => $rendered['motive'],
        ]);
    }

    /** @param array<string, string|null> $values @param array<int, string> $raw */
    private static function substitute(string $text, array $values, array $raw, bool $escape): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values, $raw, $escape) {
            $name = $m[1];
            if (! array_key_exists($name, $values)) {
                return $m[0]; // variable desconocida: se deja visible para que se note en la vista previa
            }
            $value = (string) ($values[$name] ?? '');
            if (! $escape || in_array($name, $raw, true)) {
                return $value;
            }

            // Texto de personas (nombres, mensajes): se escapa y los saltos de línea se respetan.
            return str_replace("\n", "  \n", e($value));
        }, $text);
    }

    /** Convierte [[boton: Texto | URL]] y <x-panel>…</x-panel> en HTML de correo. */
    private static function components(string $text): string
    {
        $text = preg_replace_callback('/\[\[boton:\s*(.+?)\s*\|\s*(.+?)\s*\]\]/u', function ($m) {
            [$label, $url] = [trim($m[1]), trim(html_entity_decode($m[2]))];
            if (! preg_match('#^(https?://|mailto:|tel:)#i', $url)) {
                return e($label); // URL inválida o sin completar: no se arma un enlace roto
            }

            return self::button($label, $url);
        }, $text);

        $text = self::visualComponents($text);

        return preg_replace_callback('/<x-panel>(.*?)<\/x-panel>/s', function ($m) {
            if (trim($m[1]) === '') {
                return ''; // panel vacío (p. ej. sin datos bancarios cargados): no se dibuja la caja
            }
            $inner = trim((string) Markdown::parse(trim($m[1])));
            $inner = preg_replace('/\n\s*\n/', "\n", $inner);

            return "\n\n".'<table class="panel" width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td class="panel-content"><table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td class="panel-item">'
                .$inner.'</td></tr></table></td></tr></table>'."\n\n";
        }, $text);
    }

    /**
     * Componentes visuales de los correos (estilos en línea para que se vean igual en todos los clientes de correo):
     *   <x-estado tono="aviso|alerta|ok">Texto</x-estado>   → etiqueta de color arriba del saludo
     *   <x-detalle>Etiqueta: valor (una por línea)</x-detalle> → tarjeta con filas; la fila "Importe" se destaca
     *   <x-pasos>un paso por línea</x-pasos>                 → lista numerada con círculos
     */
    private static function visualComponents(string $text): string
    {
        $font = "font-family:'Montserrat','Segoe UI',Arial,sans-serif;";

        $text = preg_replace_callback('/<x-estado(?:\s+tono="(\w+)")?\s*>(.*?)<\/x-estado>/s', function ($m) use ($font) {
            [$bg, $fg, $dot] = match ($m[1] ?: 'aviso') {
                'alerta' => ['#fde8e6', '#a3271b', '#dc2626'],
                'ok' => ['#e4f4f8', '#0f5e73', '#6ab9cf'],
                default => ['#fef0e4', '#8f4a12', '#f5a773'],
            };

            return "\n\n".'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;"><tr>'
                .'<td style="background:'.$bg.';border-radius:999px;padding:6px 14px 6px 12px;'.$font.'font-size:12px;font-weight:700;letter-spacing:.4px;color:'.$fg.';">'
                .'<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:'.$dot.';margin-right:8px;"></span>'.trim($m[2])
                .'</td></tr></table>'."\n\n";
        }, $text);

        $text = preg_replace_callback('/<x-detalle>(.*?)<\/x-detalle>/s', function ($m) use ($font) {
            $rows = collect(preg_split('/\R/', trim($m[1])))
                ->map(fn ($line) => array_map('trim', explode(':', $line, 2)))
                ->filter(fn ($p) => count($p) === 2 && $p[1] !== '')
                ->map(function ($p, $i) use ($font) {
                    $big = mb_strtolower($p[0]) === 'importe';
                    $border = $i === 0 ? '' : 'border-top:1px solid #e3e7f1;';

                    return '<tr><td style="padding:12px 18px;'.$border.$font.'font-size:12px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:#8a93ad;width:38%;">'.$p[0].'</td>'
                        .'<td style="padding:12px 18px;'.$border.$font.'text-align:right;color:#162660;'
                        .($big ? 'font-size:22px;font-weight:800;' : 'font-size:14px;font-weight:600;').'">'.$p[1].'</td></tr>';
                })->implode('');

            return $rows === '' ? '' : "\n\n".'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px;background:#f7f8fc;border:1px solid #e3e7f1;border-top:4px solid #162660;border-radius:10px;">'.$rows.'</table>'."\n\n";
        }, $text);

        return preg_replace_callback('/<x-pasos>(.*?)<\/x-pasos>/s', function ($m) use ($font) {
            $rows = collect(preg_split('/\R/', trim($m[1])))->map('trim')->filter()->values()
                ->map(fn ($step, $i) => '<tr><td valign="top" style="padding:0 12px 12px 0;width:28px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" style="width:26px;height:26px;border-radius:50%;background:#162660;color:#ffffff;'.$font.'font-size:12px;font-weight:700;">'.($i + 1).'</td></tr></table></td>'
                    .'<td valign="top" style="padding:3px 0 12px;'.$font.'font-size:14px;line-height:1.5;color:#3b4460;">'.$step.'</td></tr>')
                ->implode('');

            return $rows === '' ? '' : "\n\n".'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 14px;">'.$rows.'</table>'."\n\n";
        }, $text);
    }

    private static function button(string $label, string $url): string
    {
        return "\n\n".'<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center"><table border="0" cellpadding="0" cellspacing="0" role="presentation"><tr><td>'
            .'<a href="'.e($url).'" class="button button-primary" target="_blank" rel="noopener">'.e($label).'</a>'
            .'</td></tr></table></td></tr></table>'."\n\n";
    }
}
