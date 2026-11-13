<?php

namespace App\Http\Controllers;

use App\Actions\Finance\GenerateInvoices;
use App\Actions\Finance\MarkInvoicePaid;
use App\Actions\Finance\SyncAccountStatus;
use App\Actions\Finance\VoidInvoice;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Money;
use App\Support\Sequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Cobros a clientes. Ver: finance.view · registrar pagos, anular y crear: finance.manage (rutas). */
class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_column(InvoiceStatus::cases(), 'value'))],
            'client' => ['nullable', 'integer'],
        ]);

        $invoices = Invoice::query()->with(['client:id,business_name,trade_name', 'payouts.user:id,name'])
            ->when($filters['q'] ?? null, function (Builder $query, string $q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $query->where(fn (Builder $w) => $w->where('number', 'ilike', $like)->orWhere('description', 'ilike', $like)
                    ->orWhereHas('client', fn (Builder $c) => $c->where('business_name', 'ilike', $like)->orWhere('trade_name', 'ilike', $like)));
            })
            ->when($filters['client'] ?? null, fn (Builder $q, $id) => $q->where('client_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => match ($s) {
                'vencida' => $q->overdue(),
                'pendiente' => $q->pending()->whereDate('due_date', '>=', today()),
                default => $q->where('status', $s),
            })
            ->orderByRaw("case status when 'pendiente' then 0 when 'pagada' then 1 else 2 end")
            ->orderBy('due_date')->orderByDesc('id')
            ->paginate(15)->withQueryString()
            ->through(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'client' => $i->client->displayName(),
                'client_id' => $i->client_id,
                'description' => $i->description,
                'amount_label' => Money::format($i->amount, $i->currency),
                'amount' => Money::toDecimal($i->amount),
                'due_date' => $i->due_date->toDateString(),
                'status' => $i->effectiveStatus()->value,
                'paid_at' => $i->paid_at?->toIso8601String(),
                'payment_method' => $i->payment_method?->label(),
                'void_reason' => $i->void_reason,
                'payouts' => $i->payouts->map(fn ($p) => ['user' => $p->user->name, 'amount_label' => Money::format($p->amount, $p->currency), 'status' => $p->status]),
            ]);

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'client' => $filters['client'] ?? ''],
            'statusOptions' => InvoiceStatus::options(),
            'paymentMethods' => PaymentMethod::options(),
            'currencies' => Currency::options(),
            'clients' => Client::where('is_active', true)->orderBy('business_name')->get(['id', 'business_name', 'trade_name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->displayName()]),
            'summary' => [
                'pending' => Invoice::pending()->whereDate('due_date', '>=', today())->count(),
                'overdue' => Invoice::overdue()->count(),
                'paid_month' => Invoice::where('status', 'pagada')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            ],
            'can' => ['manage' => $request->user()->can('finance.manage')],
        ]);
    }

    /** Cobro manual (fuera de los servicios recurrentes: un trabajo puntual, un ajuste, etc.). */
    public function store(Request $request, SyncAccountStatus $sync): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'due_date' => ['required', 'date'],
        ]);

        $invoice = DB::transaction(fn () => Invoice::create([
            'number' => Sequence::next('C', 'invoices'),
            'client_id' => $data['client_id'],
            'description' => $data['description'],
            'amount' => Money::toCents($data['amount']),
            'currency' => $data['currency'],
            'issue_date' => today(),
            'due_date' => $data['due_date'],
            'status' => InvoiceStatus::Pendiente,
            'created_by' => $request->user()->id,
        ]));

        $sync->forClient($invoice->client);
        activity('finance')->performedOn($invoice)->causedBy($request->user())->log('Cobro manual creado');

        return back()->with('success', "Cobro {$invoice->number} creado.");
    }

    public function pay(Request $request, Invoice $invoice, MarkInvoicePaid $markPaid): RedirectResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $paid = $markPaid->handle($invoice, $request->user(), $data);
        $payout = $paid->payouts->first();

        return back()->with('success', $payout
            ? "Pago registrado. Se generó una liquidación de ".Money::format($payout->amount, $payout->currency)." para {$payout->user->name}."
            : 'Pago registrado.');
    }

    public function void(Request $request, Invoice $invoice, VoidInvoice $void): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $void->handle($invoice, $request->user(), $data['reason']);

        return back()->with('success', 'Cobro anulado.');
    }

    public function generate(Request $request, GenerateInvoices $generate): RedirectResponse
    {
        $created = $generate->forAll();
        activity('finance')->causedBy($request->user())->withProperties(['created' => $created])->log('Cobros generados manualmente');

        return back()->with('success', $created > 0 ? "Se generaron {$created} cobros." : 'No había cobros nuevos para generar.');
    }
}
