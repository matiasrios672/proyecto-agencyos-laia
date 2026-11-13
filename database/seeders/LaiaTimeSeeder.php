<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Services\LaiaTime\LaiaTimeInstance;
use Illuminate\Database\Seeder;

/** Vincula a los clientes de demostración con negocios ficticios de LaiaTime (solo datos locales, sin llamadas). */
class LaiaTimeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Client::query()->whereNull('laiatime_instance_id')->orderBy('id')->take(5)->get() as $i => $client) {
            $past = $client->account_status->value === 'deuda' && $i % 2 === 0;

            $client->forceFill([
                'laiatime_instance_id' => 'demo-'.str($client->displayName())->ascii()->slug(),
                'laiatime_status' => $past ? LaiaTimeInstance::PAST_DUE : LaiaTimeInstance::ACTIVE,
                'laiatime_synced_at' => now()->subHour(),
                'laiatime_suspended_at' => $past ? now()->subDays(2) : null,
                'laiatime_suspended_by_system' => $past,
            ])->save();
        }
    }
}
