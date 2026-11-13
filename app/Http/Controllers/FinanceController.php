<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Models\Invoice;
use App\Services\FinanceReport;
use App\Support\Money;
use Inertia\Inertia;
use Inertia\Response;

/** Dashboard financiero: MRR, cobrado, mora, egresos y Ganancia Neta Real. */
class FinanceController extends Controller
{
    public function __invoke(FinanceReport $report): Response
    {
        $overview = $report->overview();
        $rate = $report->usdRate();

        $labelled = collect($overview)->map(fn (array $row, string $code) => collect($row)->map(fn (int $cents) => [
            'cents' => $cents,
            'label' => Money::format($cents, $code),
        ]));

        // Consolidado en pesos solo si el sysadmin cargó una cotización (si no, se muestran por separado).
        $consolidated = $rate ? collect(['mrr', 'collected', 'net', 'pending', 'overdue', 'expenses', 'payouts', 'payouts_pending'])->mapWithKeys(function (string $key) use ($overview, $rate) {
            $cents = $overview['ARS'][$key] + (int) round($overview['USD'][$key] * $rate);

            return [$key => ['cents' => $cents, 'label' => Money::format($cents, 'ARS')]];
        }) : null;

        return Inertia::render('Finance/Dashboard', [
            'currencies' => collect(Currency::cases())->map(fn (Currency $c) => ['code' => $c->value, 'label' => $c->label()])->values(),
            'overview' => $labelled,
            'consolidated' => $consolidated,
            'rate' => $rate,
            'series' => $report->series(6),
            'monthLabel' => ucfirst(now()->locale('es')->translatedFormat('F Y')),
            'overdue' => Invoice::overdue()->with('client:id,business_name,trade_name')->orderBy('due_date')->limit(6)->get()
                ->map(fn (Invoice $i) => $this->row($i)),
            'upcoming' => Invoice::pending()->whereDate('due_date', '>=', today())->with('client:id,business_name,trade_name')
                ->orderBy('due_date')->limit(6)->get()->map(fn (Invoice $i) => $this->row($i)),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'client' => $i->client->displayName(),
            'client_id' => $i->client_id,
            'description' => $i->description,
            'amount_label' => Money::format($i->amount, $i->currency),
            'due_date' => $i->due_date->toDateString(),
            'days' => (int) today()->diffInDays($i->due_date, false),
        ];
    }
}
