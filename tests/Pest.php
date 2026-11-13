<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Cache::store('redis')->flush();
        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** Crea un usuario activo con el rol indicado. */
function userWithRole(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

/** Payload válido de presupuesto (prospecto, 2 ítems). Subtotal 1.180.001,00 · desc. 10 % · total 1.062.000,90. */
function quotePayload(array $overrides = []): array
{
    return array_replace([
        'prospect_name' => 'Vivero Los Aromos',
        'prospect_email' => 'hola@losaromos.com.ar',
        'prospect_phone' => '+54 9 11 5555-0101',
        'currency' => 'ARS',
        'discount_percent' => 10,
        'valid_until' => now()->addDays(15)->toDateString(),
        'items' => [
            ['description' => 'Community Management', 'quantity' => 1, 'unit_price' => '280000.00', 'frequency' => 'mensual'],
            ['description' => 'Identidad visual', 'quantity' => 2, 'unit_price' => '450000.50', 'frequency' => 'unico'],
        ],
    ], $overrides);
}

/** Crea un presupuesto directamente con la acción (sin pasar por la validación HTTP, para casos límite). */
function makeQuote(User $by, array $overrides = []): \App\Models\Quote
{
    return app(\App\Actions\Quotes\SaveQuote::class)->handle($by, quotePayload($overrides));
}

/** Servicio contratado mensual de $ 280.000 (vence el 20) para un cliente con email. */
function makeService(array $attrs = [], ?\App\Models\Client $client = null): \App\Models\ClientService
{
    $client ??= \App\Models\Client::factory()->create(['email' => fake()->unique()->safeEmail(), 'trade_name' => null]);

    return \App\Models\ClientService::create(array_replace([
        'client_id' => $client->id,
        'name' => 'Community Management',
        'amount' => 28_000_000,
        'currency' => 'ARS',
        'frequency' => 'mensual',
        'due_day' => 20,
        'split_rule' => '100_agencia',
        'status' => 'activo',
        'started_at' => today()->subMonths(2)->startOfMonth()->toDateString(),
    ], $attrs));
}

/** Cobro pendiente (por defecto vence en 5 días). Acepta 'service' para colgarlo de un servicio puntual. */
function makeInvoice(array $attrs = []): \App\Models\Invoice
{
    $service = $attrs['service'] ?? makeService();
    unset($attrs['service']);

    return \Illuminate\Support\Facades\DB::transaction(fn () => \App\Models\Invoice::create(array_replace([
        'number' => \App\Support\Sequence::next('C', 'invoices'),
        'client_id' => $service->client_id,
        'client_service_id' => $service->id,
        'description' => $service->name,
        'amount' => $service->amount,
        'currency' => $service->currency->value,
        'issue_date' => today()->toDateString(),
        'due_date' => today()->addDays(5)->toDateString(),
        'status' => 'pendiente',
    ], $attrs)));
}

/** PDF mínimo pero válido (empieza con %PDF- y finfo lo reconoce como application/pdf). */
function validPdf(string $text = 'Grilla'): string
{
    $stream = "BT /F1 18 Tf 72 760 Td ({$text}) Tj ET";

    return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
        .'4 0 obj<</Length '.strlen($stream)."  >>stream\n{$stream}\nendstream endobj\n"
        ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R/Size 6>>\n%%EOF\n";
}

/** Archivo subido de prueba con contenido real (por defecto un PDF válido). */
function pdfUpload(string $name = 'grilla.pdf', ?string $content = null): \Illuminate\Http\UploadedFile
{
    return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, $content ?? validPdf());
}
