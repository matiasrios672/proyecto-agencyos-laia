<?php

namespace Database\Seeders;

use App\Actions\Quotes\SaveQuote;
use App\Enums\QuoteStatus;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Seeder;

/** Catálogo, servicios contratados (con reparto) y presupuestos de demostración de Laia. */
class SalesSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = collect([
            ['Community Management', 'Gestión de redes, calendario de contenido y reportes mensuales.', 280000, 'ARS', 'mensual'],
            ['Pauta publicitaria (Meta Ads)', 'Gestión y optimización de campañas. No incluye inversión en pauta.', 150000, 'ARS', 'mensual'],
            ['Mantenimiento web', 'Actualizaciones, backups, seguridad y soporte técnico.', 90000, 'ARS', 'mensual'],
            ['SEO mensual', 'Optimización on-page, contenido y reporte de posicionamiento.', 120000, 'ARS', 'mensual'],
            ['Identidad visual', 'Logo, paleta, tipografías y manual de marca.', 450000, 'ARS', 'unico'],
            ['Sitio web institucional', 'Diseño y desarrollo de sitio de hasta 6 secciones, responsive.', 900000, 'ARS', 'unico'],
            ['Sesión de fotos de producto', 'Hasta 20 productos, edición y retoque incluidos.', 220000, 'ARS', 'unico'],
            ['Landing page', 'Página de aterrizaje optimizada para conversión.', 650, 'USD', 'unico'],
            ['Hosting y dominio (anual)', 'Hosting administrado y renovación de dominio .com.ar.', 180, 'USD', 'unico'],
        ])->map(fn ($c) => CatalogService::updateOrCreate(
            ['name' => $c[0]],
            ['description' => $c[1], 'price' => Money::toCents($c[2]), 'currency' => $c[3], 'frequency' => $c[4], 'is_active' => true],
        ))->keyBy('name');

        $this->seedClientServices($catalog);
        $this->seedQuotes($catalog);
    }

    private function seedClientServices($catalog): void
    {
        $plans = [
            ['Community Management', 'porcentaje', 30],
            ['Mantenimiento web', 'monto_fijo', 25000],
            ['Pauta publicitaria (Meta Ads)', 'porcentaje', 20],
        ];

        foreach (Client::with('users')->orderBy('id')->get() as $i => $client) {
            foreach ([$plans[$i % 3], $plans[($i + 1) % 3]] as $n => [$name, $rule, $value]) {
                if ($n === 1 && $i % 2 === 0) {
                    continue; // no todos contratan dos servicios
                }

                $service = $catalog[$name];
                $assignee = $client->users->first();

                ClientService::create([
                    'client_id' => $client->id,
                    'name' => $service->name,
                    'amount' => $service->price,
                    'currency' => $service->currency,
                    'frequency' => $service->frequency,
                    'due_day' => [5, 10, 15, 20][$i % 4],
                    'assigned_user_id' => $assignee?->id,
                    'split_rule' => $assignee ? $rule : '100_agencia',
                    'split_percent' => $assignee && $rule === 'porcentaje' ? $value : null,
                    'split_amount' => $assignee && $rule === 'monto_fijo' ? Money::toCents($value) : null,
                    'status' => 'activo',
                    'started_at' => now()->subMonths(6 + $i)->startOfMonth(),
                ]);
            }
        }
    }

    private function seedQuotes($catalog): void
    {
        $owner = User::where('email', 'direccion@laia.test')->first();
        $save = app(SaveQuote::class);

        $item = fn (string $name, int $qty = 1) => [
            'service_id' => $catalog[$name]->id,
            'description' => $catalog[$name]->name,
            'quantity' => $qty,
            'unit_price' => Money::toDecimal($catalog[$name]->price),
            'frequency' => $catalog[$name]->frequency->value,
        ];
        $valid = now()->addDays(15)->toDateString();

        // Borrador para un prospecto.
        $save->handle($owner, [
            'prospect_name' => 'Vivero Los Aromos', 'prospect_email' => 'hola@losaromos.com.ar', 'prospect_phone' => '+54 9 11 5555-0101',
            'currency' => 'ARS', 'discount_percent' => 10, 'valid_until' => $valid,
            'notes' => "Incluye 2 rondas de ajustes.\nForma de pago: 50 % al aprobar y 50 % contra entrega.",
            'items' => [$item('Identidad visual'), $item('Community Management')],
        ]);

        // Enviado a un cliente existente.
        $quote = $save->handle($owner, [
            'client_id' => Client::where('business_name', 'like', 'Estudio Contable%')->value('id'),
            'currency' => 'ARS', 'valid_until' => $valid, 'items' => [$item('SEO mensual'), $item('Sitio web institucional')],
        ]);
        $quote->forceFill(['status' => QuoteStatus::Enviado, 'sent_at' => now()->subDays(2)])->save();

        // Enviado en dólares a otro prospecto.
        $quote = $save->handle($owner, [
            'prospect_name' => 'Estudio Jurídico Navarro', 'prospect_email' => 'contacto@navarro-abogados.com.ar',
            'currency' => 'USD', 'valid_until' => now()->addDays(10)->toDateString(),
            'items' => [$item('Landing page'), $item('Hosting y dominio (anual)')],
        ]);
        $quote->forceFill(['status' => QuoteStatus::Enviado, 'sent_at' => now()->subDay()])->save();

        // Rechazado.
        $quote = $save->handle($owner, [
            'prospect_name' => 'Café Tupungato', 'currency' => 'ARS', 'valid_until' => $valid,
            'items' => [$item('Sesión de fotos de producto')],
        ]);
        $quote->forceFill(['status' => QuoteStatus::Rechazado, 'rejected_at' => now()->subDays(5)])->save();
    }
}
