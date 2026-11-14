<?php

use App\Models\CatalogService;
use App\Models\Client;
use Inertia\Testing\AssertableInertia as Assert;

function newService(array $over = []): array
{
    return $over + [
        'name' => 'Sitio web', 'amount' => '500000', 'currency' => 'ARS', 'frequency' => 'unico', 'due_day' => 10,
        'billing_timing' => 'adelantada', 'assigned_user_id' => null, 'split_rule' => '100_agencia', 'status' => 'activo',
    ];
}

describe('elegir servicios del catálogo al contratar', function () {
    it('la ficha del cliente recibe solo los servicios activos del catálogo', function () {
        CatalogService::create(['name' => 'Community Management', 'price' => 28_000_000, 'currency' => 'ARS', 'frequency' => 'mensual', 'is_active' => true]);
        CatalogService::create(['name' => 'Descontinuado', 'price' => 100, 'currency' => 'ARS', 'frequency' => 'unico', 'is_active' => false]);
        $client = Client::factory()->create();

        $this->actingAs(userWithRole('dueno'))->get("/clientes/{$client->id}")->assertInertia(fn (Assert $p) => $p
            ->has('serviceOptions.catalog', 1)
            ->where('serviceOptions.catalog.0.name', 'Community Management')
            ->where('serviceOptions.catalog.0.amount', '280000.00')
            ->where('serviceOptions.catalog.0.frequency', 'mensual')
            ->where('serviceOptions.canSaveToCatalog', true));
    });

    it('un servicio nuevo escrito a mano puede guardarse también en el catálogo', function () {
        $client = Client::factory()->create();

        $this->actingAs(userWithRole('dueno'))->post("/clientes/{$client->id}/servicios", newService(['save_to_catalog' => true]))
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'catálogo'));

        $entry = CatalogService::where('name', 'Sitio web')->first();
        expect($entry)->not->toBeNull()->and($entry->price)->toBe(50_000_000)->and($entry->is_active)->toBeTrue();
        expect($client->services()->count())->toBe(1);
    });

    it('sin el check no toca el catálogo, y no duplica uno que ya existe con ese nombre', function () {
        $client = Client::factory()->create();
        $dueno = userWithRole('dueno');

        $this->actingAs($dueno)->post("/clientes/{$client->id}/servicios", newService())->assertRedirect();
        expect(CatalogService::count())->toBe(0);

        CatalogService::create(['name' => 'sitio WEB', 'price' => 1, 'currency' => 'ARS', 'frequency' => 'unico', 'is_active' => true]);
        $this->actingAs($dueno)->post("/clientes/{$client->id}/servicios", newService(['save_to_catalog' => true]))->assertRedirect();

        expect(CatalogService::count())->toBe(1)->and($client->services()->count())->toBe(2);
    });
});
