<?php

namespace Database\Seeders;

use App\Actions\Finance\GenerateInvoices;
use App\Actions\Finance\MarkInvoicePaid;
use App\Actions\Finance\SettlePayouts;
use App\Actions\Finance\SyncAccountStatus;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TeamPayout;
use App\Models\User;
use App\Support\Money;
use App\Support\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Cobros históricos, liquidaciones, egresos y comunicados de demostración. */
class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'direccion@laia.test')->firstOrFail();
        $markPaid = app(MarkInvoicePaid::class);

        // El semáforo que dejó ClientSeeder es la "intención" de la demo; después se deriva de los cobros reales.
        $intended = Client::all()->mapWithKeys(fn (Client $c) => [$c->id => $c->account_status->value]);

        // Historial: los 3 meses anteriores de cada servicio mensual, cobrados.
        foreach (ClientService::where('frequency', 'mensual')->with('client')->get() as $service) {
            foreach ([5, 4, 3, 2, 1] as $back) {
                $month = now()->startOfMonth()->subMonthsNoOverflow($back);
                $due = $month->copy()->day(min($service->due_day, $month->daysInMonth));

                $invoice = DB::transaction(fn () => Invoice::create([
                    'number' => Sequence::next('C', 'invoices'),
                    'client_id' => $service->client_id,
                    'client_service_id' => $service->id,
                    'description' => $service->name.' — '.ucfirst($month->locale('es')->translatedFormat('F Y')),
                    'amount' => $service->amount,
                    'currency' => $service->currency,
                    'period' => $month->format('Y-m'),
                    'issue_date' => $due->copy()->subDays(10),
                    'due_date' => $due,
                    'status' => 'pendiente',
                ]));

                $markPaid->handle($invoice, $owner, [
                    'payment_method' => ($service->id + $back) % 3 === 0 ? 'mercadopago' : 'transferencia',
                    'paid_at' => $due->copy()->addDays(($service->id + $back) % 4)->min(today())->toDateString(),
                ]);
            }
        }

        // Las liquidaciones llevan la fecha de su cobro (no la del momento de sembrar).
        DB::statement('update team_payouts set created_at = (select paid_at from invoices where invoices.id = team_payouts.invoice_id)');

        // Los pagos de hace 2 o más meses ya se liquidaron al equipo; lo reciente queda pendiente.
        $old = TeamPayout::whereHas('invoice', fn ($q) => $q->where('paid_at', '<', now()->subMonth()->startOfMonth()))->pluck('id')->all();
        if ($old !== []) {
            app(SettlePayouts::class)->handle($old, $owner, 'Transferencia mensual del equipo');
            // Se liquidaron unos días después del cobro (sin pasar de hoy).
            DB::statement("update team_payouts set settled_at = least(created_at + interval '6 days', now()) where status = 'liquidada'");
        }

        // Mes en curso: se emiten los cobros que corresponden y se acomodan para que el semáforo coincida con la demo.
        app(GenerateInvoices::class)->forAll();

        foreach (Client::with('services')->get() as $client) {
            $pending = $client->invoices()->pending()->orderBy('due_date')->get();

            if ($pending->isEmpty() && $intended[$client->id] !== 'al_dia' && $client->services->isNotEmpty()) {
                $service = $client->services->first();
                $pending = collect([Invoice::create([
                    'number' => DB::transaction(fn () => Sequence::next('C', 'invoices')),
                    'client_id' => $client->id, 'client_service_id' => null,
                    'description' => 'Cobro adicional — '.$service->name, 'amount' => $service->amount, 'currency' => $service->currency,
                    'issue_date' => today()->subDays(15), 'due_date' => today(), 'status' => 'pendiente',
                ])]);
            }

            $keep = match ($intended[$client->id]) {
                'deuda' => $pending->first()?->forceFill(['due_date' => today()->subDays(12)])->save() ? $pending->first() : null,
                'por_vencer' => $pending->first()?->forceFill(['due_date' => today()->addDays(2)])->save() ? $pending->first() : null,
                default => null,
            };

            foreach ($pending as $invoice) {
                if ($keep?->is($invoice)) {
                    continue;
                }
                if ($invoice->due_date->lte(today()->addDays(3))) {
                    $markPaid->handle($invoice, $owner, ['payment_method' => 'transferencia', 'paid_at' => min($invoice->due_date, today())->toDateString()]);
                }
            }
        }

        app(SyncAccountStatus::class)->forAll();

        $this->seedExpenses($owner);
        $this->seedAnnouncements($owner);

        // Las notificaciones generadas al sembrar son ruido de demo: cada usuario empieza con la bandeja limpia.
        DB::table('notifications')->whereIn('type', ['App\\Notifications\\PayoutGenerated', 'App\\Notifications\\PayoutSettled'])->delete();
    }

    private function seedExpenses(User $owner): void
    {
        $firstOfYear = now()->startOfYear();

        foreach ([
            ['Adobe Creative Cloud', 'software', '59.99', 'USD', 'mensual', $firstOfYear],
            ['Canva Pro (equipo)', 'software', '120', 'USD', 'anual', $firstOfYear],
            ['VPS de producción', 'hosting', '24', 'USD', 'mensual', $firstOfYear],
            ['API de inteligencia artificial', 'apis', '20', 'USD', 'mensual', $firstOfYear],
            ['Dominios laia.* (anual)', 'hosting', '28000', 'ARS', 'anual', $firstOfYear],
            ['Contador', 'otro', '90000', 'ARS', 'mensual', $firstOfYear],
            ['Coworking', 'oficina', '150000', 'ARS', 'mensual', $firstOfYear],
            ['Cámara y luces para sesiones', 'otro', '450000', 'ARS', 'unico', now()->subMonths(2)->startOfMonth()->addDays(9)],
        ] as [$name, $category, $amount, $currency, $frequency, $start]) {
            Expense::create([
                'name' => $name, 'category' => $category, 'amount' => Money::toCents($amount), 'currency' => $currency,
                'frequency' => $frequency, 'starts_on' => $start, 'is_active' => true, 'created_by' => $owner->id,
            ]);
        }
    }

    private function seedAnnouncements(User $owner): void
    {
        $sent = Announcement::create([
            'subject' => 'Horarios de atención en las fiestas',
            'body' => "Les avisamos que entre el 24 de diciembre y el 2 de enero atenderemos solo urgencias.\nLa publicación de contenido sigue su calendario habitual.",
            'audience' => 'todos', 'status' => 'enviado', 'sent_at' => now()->subDays(6), 'created_by' => $owner->id,
        ]);
        foreach (Client::where('is_active', true)->get() as $client) {
            $sent->recipients()->create([
                'client_id' => $client->id, 'email' => $client->email, 'status' => $client->email ? 'enviado' : 'sin_email',
                'sent_at' => $client->email ? now()->subDays(6) : null,
            ]);
        }

        Announcement::create([
            'subject' => 'Novedades de nuestros planes 2027',
            'body' => 'Estamos armando nuevos planes de contenido y pauta. Pronto te contamos los detalles.',
            'audience' => 'estado', 'audience_value' => ['status' => 'al_dia'], 'status' => 'borrador', 'created_by' => $owner->id,
        ]);
    }
}
