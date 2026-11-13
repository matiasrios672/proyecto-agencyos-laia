<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Models\ClientService;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TeamPayout;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Cifras del dashboard financiero. Todo en centavos y separado por moneda: pesos y dólares nunca se suman
 * sin una cotización explícita (finance.usd_ars_rate).
 */
class FinanceReport
{
    /**
     * Foto del mes: MRR, cobrado, pendiente, en mora, egresos y ganancia neta real.
     *
     * @return array<string, array<string, int>>
     */
    public function overview(?Carbon $month = null): array
    {
        $month ??= now();
        $mrr = ClientService::where('status', 'activo')->where('frequency', 'mensual')
            ->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency');
        $pending = Invoice::pending()->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency');
        $overdue = Invoice::overdue()->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency');
        $owed = TeamPayout::pending()->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency');
        $expenses = Expense::where('is_active', true)->get();

        $result = [];
        foreach (Currency::cases() as $currency) {
            $code = $currency->value;
            $month_ = $this->month($month, $currency);

            $result[$code] = [
                'mrr' => (int) ($mrr[$code] ?? 0),
                'collected' => $month_['collected'],
                'payouts' => $month_['payouts'],
                'expenses' => $month_['expenses'],
                'net' => $month_['net'],
                'pending' => (int) ($pending[$code] ?? 0),
                'overdue' => (int) ($overdue[$code] ?? 0),
                'payouts_pending' => (int) ($owed[$code] ?? 0),
                'fixed_costs_monthly' => (int) $expenses->where('currency', $currency)->sum(fn (Expense $e) => $e->monthlyEquivalent()),
            ];
        }

        return $result;
    }

    /**
     * Ganancia neta real de un mes = cobrado − liquidaciones al equipo generadas por esos cobros − egresos del mes.
     *
     * @return array{collected: int, payouts: int, expenses: int, net: int}
     */
    public function month(Carbon $month, Currency $currency): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $paid = Invoice::where('status', InvoiceStatus::Pagada->value)->where('currency', $currency->value)
            ->whereBetween('paid_at', [$start, $end]);

        $collected = (int) (clone $paid)->sum('amount');
        $payouts = (int) TeamPayout::where('currency', $currency->value)
            ->whereIn('invoice_id', (clone $paid)->select('id'))->sum('amount');
        $expenses = (int) Expense::where('currency', $currency->value)->get()->sum(fn (Expense $e) => $e->costInMonth($month));

        return ['collected' => $collected, 'payouts' => $payouts, 'expenses' => $expenses, 'net' => $collected - $payouts - $expenses];
    }

    /**
     * Últimos meses para el gráfico, por moneda.
     *
     * @return array<string, list<array{month: string, label: string, collected: int, payouts: int, expenses: int, net: int}>>
     */
    public function series(int $months = 6): array
    {
        $series = [];
        foreach (Currency::cases() as $currency) {
            for ($i = $months - 1; $i >= 0; $i--) {
                $m = now()->startOfMonth()->subMonthsNoOverflow($i);
                $series[$currency->value][] = ['month' => $m->format('Y-m'), 'label' => ucfirst($m->locale('es')->translatedFormat('M'))] + $this->month($m, $currency);
            }
        }

        return $series;
    }

    /** Cotización configurada USD→ARS (null si no hay: no se consolida). */
    public function usdRate(): ?float
    {
        $rate = (float) Settings::get('finance.usd_ars_rate', '0');

        return $rate > 0 ? $rate : null;
    }
}
