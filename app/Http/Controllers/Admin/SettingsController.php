<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        $values = Settings::all();
        $groups = [];

        foreach (Settings::definitions() as $key => $def) {
            $current = $values[$key] ?? null;
            $groups[$def['group']][] = [
                'key' => $key,
                'label' => $def['label'],
                'type' => $def['type'],
                'secret' => $def['secret'],
                // Los secretos nunca viajan al navegador: solo se informa si están configurados.
                'value' => $def['secret'] ? '' : ($current ?? $def['default'] ?? ''),
                'configured' => $current !== null && $current !== '',
            ];
        }

        return Inertia::render('Admin/Settings', ['groups' => $groups]);
    }

    public function update(Request $request): RedirectResponse
    {
        $definitions = Settings::definitions();

        $data = $request->validate([
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($data['values'] as $key => $value) {
            if (! isset($definitions[$key])) {
                continue;
            }
            // Secreto vacío = no modificar el valor actual.
            if ($definitions[$key]['secret'] && ($value === null || $value === '')) {
                continue;
            }
            Settings::set($key, $value);
        }

        activity('settings')->causedBy($request->user())
            ->withProperties(['keys' => array_keys($data['values'])])
            ->log('Configuración del sistema actualizada');

        return back()->with('success', 'Configuración guardada.');
    }
}
