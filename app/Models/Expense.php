<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Expense extends Model
{
    protected $fillable = ['name', 'category', 'amount', 'currency', 'frequency', 'starts_on', 'ends_on', 'is_active', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'currency' => Currency::class,
            'category' => ExpenseCategory::class,
            'frequency' => ExpenseFrequency::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** Equivalente mensual en centavos: mensual = monto, anual = monto / 12, pago único = 0 (se imputa en su mes). */
    public function monthlyEquivalent(): int
    {
        return match ($this->frequency) {
            ExpenseFrequency::Mensual => $this->amount,
            ExpenseFrequency::Anual => (int) round($this->amount / 12),
            ExpenseFrequency::Unico => 0,
        };
    }

    /** Lo que este egreso suma en un mes dado (centavos). Los recurrentes cuentan mientras estén vigentes. */
    public function costInMonth(Carbon $month): int
    {
        if (! $this->is_active) {
            return 0;
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        if ($this->frequency === ExpenseFrequency::Unico) {
            return $this->starts_on->between($start, $end) ? $this->amount : 0;
        }

        $active = $this->starts_on->lte($end) && ($this->ends_on === null || $this->ends_on->gte($start));

        return $active ? $this->monthlyEquivalent() : 0;
    }
}
