<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Datos bancarios de cada persona: los ve y edita ella misma, y Dirección los de quienes puede gestionar. */
class BankDetailsController extends Controller
{
    public function updateOwn(Request $request): RedirectResponse
    {
        return $this->save($request, $request->user());
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->manageableBy($request->user()), 403);

        return $this->save($request, $user);
    }

    private function save(Request $request, User $user): RedirectResponse
    {
        // Se aceptan espacios y guiones al pegar desde la app del banco; se guardan normalizados.
        $request->merge([
            'bank_cbu' => preg_replace('/\D/', '', (string) $request->input('bank_cbu')),
            'bank_tax_id' => preg_replace('/\D/', '', (string) $request->input('bank_tax_id')),
            'bank_alias' => trim((string) $request->input('bank_alias')),
        ]);

        $data = $request->validate([
            'bank_holder' => ['nullable', 'string', 'max:120'],
            'bank_tax_id' => ['nullable', 'digits:11'],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'bank_cbu' => ['nullable', 'digits:22'],
            'bank_alias' => ['nullable', 'string', 'min:6', 'max:40', 'regex:/^[A-Za-z0-9.\-]+$/'],
        ], [
            'bank_cbu.digits' => 'El CBU/CVU tiene 22 números.',
            'bank_tax_id.digits' => 'El CUIT/CUIL tiene 11 números.',
            'bank_alias.regex' => 'El alias solo puede tener letras, números, puntos y guiones.',
            'bank_alias.min' => 'El alias tiene al menos 6 caracteres.',
        ]);

        $data = array_map(fn ($v) => filled($v) ? $v : null, $data);
        $changed = collect($data)->filter(fn ($v, $k) => $user->{$k} !== $v)->keys()->all();

        $user->forceFill($data)->save();

        // Se audita qué campos cambiaron, nunca sus valores.
        if ($changed) {
            activity('employees')->performedOn($user)->causedBy($request->user())
                ->withProperties(['fields' => $changed])->log('Datos bancarios actualizados');
        }

        return back()->with('success', $user->is($request->user()) ? 'Tus datos bancarios se guardaron.' : "Se guardaron los datos bancarios de {$user->name}.");
    }
}
