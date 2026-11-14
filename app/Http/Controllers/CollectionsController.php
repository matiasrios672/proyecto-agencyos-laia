<?php

namespace App\Http\Controllers;

use App\Actions\Finance\SendPaymentReminders;
use App\Mail\PaymentReminderMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ReminderLog;
use App\Models\TeamPayout;
use App\Support\BankAccount;
use App\Support\MailTemplates;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel guiado de cobranzas: qué avisos mandar, qué cobros esperan pago y qué liquidar al equipo.
 * Ver: finance.view · enviar, pausar y cambiar el modo: finance.manage (rutas).
 */
class CollectionsController extends Controller
{
    private const UPCOMING_DAYS = 7;

    public function index(Request $request, SendPaymentReminders $reminders): Response
    {
        $notices = $reminders->notices()->map(fn (array $n) => ['account' => collect(BankAccount::forInvoice($n['invoice']))->only(['source', 'owner', 'missing_employee'])->all()] + [
            'key' => $n['invoice']->id.'-'.$n['type'],
            'invoice_id' => $n['invoice']->id,
            'type' => $n['type'],
            'state' => $n['state'],
            'client_id' => $n['invoice']->client_id,
            'client' => $n['invoice']->client->displayName(),
            'email' => $n['invoice']->client->email,
            'number' => $n['invoice']->number,
            'amount_label' => Money::format($n['invoice']->amount, $n['invoice']->currency),
            'due_date' => $n['invoice']->due_date->toDateString(),
        ])->values();

        $pending = Invoice::pending()->with(['client:id,business_name,trade_name,email,reminders_paused', 'reminders'])
            ->orderBy('due_date')->get();

        $overdue = $pending->filter(fn (Invoice $i) => $i->isOverdue());
        $upcoming = $pending->filter(fn (Invoice $i) => ! $i->isOverdue() && $i->due_date->lte(today()->addDays(self::UPCOMING_DAYS)));

        $payouts = TeamPayout::where('status', 'pendiente')->with(['user:id,name', 'invoice.client:id,business_name,trade_name'])->get();

        return Inertia::render('Collections/Index', [
            'auto' => SendPaymentReminders::auto(),
            'can' => ['manage' => $request->user()->can('finance.manage')],
            'stats' => [
                'upcoming' => ['count' => $upcoming->count(), 'totals' => $this->totals($upcoming, 'amount')],
                'overdue' => ['count' => $overdue->count(), 'totals' => $this->totals($overdue, 'amount')],
                'to_settle' => ['count' => $payouts->count(), 'totals' => $this->totals($payouts, 'amount')],
                'notices' => $notices->where('state', SendPaymentReminders::READY)->count(),
            ],
            'notices' => $notices,
            'overdue' => $overdue->map(fn (Invoice $i) => $this->invoiceRow($i))->values(),
            'upcoming' => $upcoming->map(fn (Invoice $i) => $this->invoiceRow($i))->values(),
            'payouts' => $payouts->groupBy('user_id')->map(fn (Collection $rows) => [
                'user_id' => $rows->first()->user_id,
                'user' => $rows->first()->user->name,
                'count' => $rows->count(),
                'totals' => $this->totals($rows, 'amount'),
                'ids' => $rows->pluck('id')->values(),
                'items' => $rows->map(fn (TeamPayout $p) => [
                    'id' => $p->id,
                    'client' => $p->invoice->client->displayName(),
                    'invoice' => $p->invoice->number,
                    'amount_label' => Money::format($p->amount, $p->currency),
                ])->values(),
            ])->values(),
            'history' => ReminderLog::with(['sender:id,name', 'invoice.client'])->latest('sent_at')->limit(8)->get()->map(function (ReminderLog $log) {
                $invoice = $log->invoice;

                return [
                    'id' => $log->id,
                    'type' => $log->type,
                    'sent_at' => $log->sent_at->toIso8601String(),
                    'by' => $log->sender?->name ?? 'Automático',
                    'client' => $invoice?->client->displayName(),
                    'number' => $invoice?->number,
                ];
            }),
            'paymentMethods' => \App\Enums\PaymentMethod::options(),
        ]);
    }

    /** Vista previa del correo tal como lo va a recibir el cliente (no envía nada). */
    public function preview(Invoice $invoice, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['antes', 'mora'], true), 404);

        $key = SendPaymentReminders::templateKey($type);
        $vars = (new PaymentReminderMail($invoice->load('client'), $type))->templateVars();

        return response()->json([
            'to' => $invoice->client->email,
            'subject' => MailTemplates::render($key, $vars)['subject'],
            'html' => MailTemplates::html($key, $vars),
        ]);
    }

    public function send(Request $request, SendPaymentReminders $reminders): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'items.*.type' => ['required', 'in:antes,mora'],
        ]);

        $counts = ['enviado' => 0, 'sin_email' => 0, 'pausado' => 0, 'otros' => 0];
        foreach ($data['items'] as $item) {
            $outcome = $reminders->sendNotice(Invoice::with('client')->findOrFail($item['invoice_id']), $item['type'], $request->user());
            $counts[array_key_exists($outcome, $counts) ? $outcome : 'otros']++;
        }

        activity('finance')->causedBy($request->user())->withProperties($counts)->log('Avisos de cobro enviados desde Cobranzas');

        $message = $counts['enviado'] === 1 ? 'Se envió 1 aviso.' : "Se enviaron {$counts['enviado']} avisos.";
        $skipped = array_filter(['sin email' => $counts['sin_email'], 'pausados' => $counts['pausado'], 'que ya no correspondían' => $counts['otros']]);
        if ($skipped) {
            $message .= ' No salieron: '.collect($skipped)->map(fn ($n, $why) => "{$n} {$why}")->implode(', ').'.';
        }

        return back()->with($counts['enviado'] > 0 ? 'success' : 'error', $message);
    }

    public function mode(Request $request): RedirectResponse
    {
        $auto = $request->validate(['auto' => ['required', 'boolean']])['auto'];
        Settings::set('dunning.reminders_auto', $auto ? '1' : '0');
        activity('finance')->causedBy($request->user())->withProperties(['auto' => $auto])->log('Modo de avisos de cobro cambiado');

        return back()->with('success', $auto
            ? 'Avisos automáticos activados: salen solos todos los días a las 9:00.'
            : 'Avisos manuales: los revisás y enviás desde acá.');
    }

    public function pause(Request $request, Client $client): RedirectResponse
    {
        $paused = $request->validate(['paused' => ['required', 'boolean']])['paused'];
        $client->forceFill(['reminders_paused' => $paused])->save();
        activity('finance')->performedOn($client)->causedBy($request->user())->log($paused ? 'Avisos de cobro pausados' : 'Avisos de cobro reanudados');

        return back()->with('success', $paused
            ? "No se le mandarán avisos a {$client->displayName()}."
            : "Se reanudaron los avisos a {$client->displayName()}.");
    }

    /** @return array<string, mixed> */
    private function invoiceRow(Invoice $i): array
    {
        $days = (int) today()->diffInDays($i->due_date->startOfDay(), false);

        return [
            'id' => $i->id,
            'number' => $i->number,
            'client' => $i->client->displayName(),
            'client_id' => $i->client_id,
            'paused' => (bool) $i->client->reminders_paused,
            'has_email' => filled($i->client->email),
            'description' => $i->description,
            'amount_label' => Money::format($i->amount, $i->currency),
            'issue_date' => $i->issue_date->toDateString(),
            'due_date' => $i->due_date->toDateString(),
            'days' => $days,
            'reminders' => $i->reminders->mapWithKeys(fn (ReminderLog $r) => [$r->type => $r->sent_at->toDateString()]),
        ];
    }

    /** @return list<string> totales por moneda, ya formateados (nunca se suman monedas distintas) */
    private function totals(Collection $rows, string $field): array
    {
        return $rows->groupBy(fn ($r) => $r->currency instanceof \BackedEnum ? $r->currency->value : (string) $r->currency)
            ->map(fn (Collection $group, string $code) => Money::format((int) $group->sum($field), $code))
            ->values()->all();
    }
}
