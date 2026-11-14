<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\User;

/**
 * Cuenta donde el cliente debe transferir un cobro: la de la persona a cargo del servicio si cargó
 * sus datos; si no (o si el cobro no tiene servicio/responsable), la de la agencia de Configuración.
 */
class BankAccount
{
    /** Campos en el orden en que se muestran: clave => [etiqueta, columna del empleado, ajuste de la agencia]. */
    public const FIELDS = [
        'holder' => ['Titular', 'bank_holder'],
        'cuit' => ['CUIT / CUIL', 'bank_tax_id'],
        'name' => ['Banco', 'bank_name'],
        'cbu' => ['CBU / CVU', 'bank_cbu'],
        'alias' => ['Alias', 'bank_alias'],
    ];

    /** @return array{source: 'empleado'|'agencia', owner: ?string, fields: array<string, string>, missing_employee: bool} */
    public static function forInvoice(Invoice $invoice): array
    {
        $assignee = $invoice->service?->assignee;

        if ($assignee && self::hasData($assignee)) {
            return ['source' => 'empleado', 'owner' => $assignee->name, 'fields' => self::employeeFields($assignee), 'missing_employee' => false];
        }

        return [
            'source' => 'agencia',
            'owner' => null,
            'fields' => self::agencyFields(),
            // Hay alguien a cargo pero todavía no cargó sus datos: se usa la cuenta de la agencia y se avisa.
            'missing_employee' => $assignee !== null,
        ];
    }

    /** Se considera "cargada" si tiene al menos un medio para transferir (CBU/CVU o alias). */
    public static function hasData(User $user): bool
    {
        return filled($user->bank_cbu) || filled($user->bank_alias);
    }

    /** @return array<string, string> */
    public static function employeeFields(User $user): array
    {
        return collect(self::FIELDS)->map(fn (array $f) => (string) $user->{$f[1]})->filter()->all();
    }

    /** @return array<string, string> */
    public static function agencyFields(): array
    {
        return collect(self::FIELDS)->keys()->mapWithKeys(fn (string $key) => [$key => (string) Settings::get("bank.{$key}")])->filter()->all();
    }
}
