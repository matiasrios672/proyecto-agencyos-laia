<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\TaxCondition;
use App\Enums\VaultCategory;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Clientes de demostración de Laia Estudio Creativo Digital. Las credenciales son ficticias. */
class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $camila = User::where('email', 'camila@laia.test')->first();
        $tomas = User::where('email', 'tomas@laia.test')->first();
        $julian = User::where('email', 'julian@laia.test')->first();

        $clients = [
            ['Panadería La Espiga S.R.L.', 'La Espiga', 'Rosario', AccountStatus::AlDia, [$camila, $tomas]],
            ['Estudio Contable Benítez & Asociados', 'Benítez Contadores', 'Buenos Aires', AccountStatus::PorVencer, [$julian]],
            ['Clínica Odontológica Sonríe S.A.', 'Sonríe Odontología', 'Córdoba', AccountStatus::Deuda, [$camila]],
            ['Bodega Altos del Valle S.A.', 'Altos del Valle', 'Mendoza', AccountStatus::AlDia, [$camila, $julian]],
            ['Gimnasio Fuerza Norte', 'Fuerza Norte', 'Tucumán', AccountStatus::AlDia, [$tomas]],
            ['Inmobiliaria Costa Atlántica', 'Costa Atlántica', 'Mar del Plata', AccountStatus::PorVencer, [$camila, $tomas]],
            ['Taller Mecánico Don Raúl', 'Don Raúl', 'La Plata', AccountStatus::Deuda, []],
            ['Librería El Aleph', 'El Aleph', 'Buenos Aires', AccountStatus::AlDia, [$tomas, $julian]],
        ];

        foreach ($clients as [$business, $trade, $city, $status, $team]) {
            $slug = str($trade)->ascii()->slug('');

            $client = Client::factory()->create([
                'business_name' => $business,
                'trade_name' => $trade,
                'city' => $city,
                'account_status' => $status,
                'tax_condition' => fake()->randomElement([TaxCondition::ResponsableInscripto, TaxCondition::Monotributista]),
                'email' => "contacto@{$slug}.com.ar",
                'website' => "https://{$slug}.com.ar",
            ]);

            $client->users()->sync(collect($team)->filter()->pluck('id'));

            $client->vaultItems()->createMany([
                [
                    'category' => VaultCategory::RedSocial, 'label' => 'Instagram', 'url' => "https://instagram.com/{$slug}",
                    'username' => "@{$slug}", 'secret' => 'Demo-IG-'.fake()->bothify('??##??##'), 'notes' => 'Cuenta verificada con 2FA por SMS.',
                ],
                [
                    'category' => VaultCategory::Hosting, 'label' => 'Hosting web', 'url' => 'https://panel.hosting-demo.test',
                    'username' => $slug.'_admin', 'secret' => 'Demo-HOST-'.fake()->bothify('##??##??'),
                ],
                [
                    'category' => VaultCategory::Respaldo2fa, 'label' => 'Códigos de respaldo Meta', 'username' => null,
                    'secret' => implode("\n", array_map(fn () => fake()->numerify('#### ####'), range(1, 5))),
                ],
            ]);
        }
    }
}
