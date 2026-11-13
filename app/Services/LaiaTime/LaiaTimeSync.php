<?php

namespace App\Services\LaiaTime;

use App\Models\Client;
use App\Models\User;
use App\Notifications\LaiaTimeAlert;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * Sincroniza el estado de pago de los clientes con LaiaTime.
 *
 * Una sola lectura de la lista de negocios por corrida (no una por cliente). Reglas:
 *   - ACTIVE → PAST_DUE: solo con `dunning.auto_suspend` activo, mora fuera de gracia y sin prórroga vigente
 *     (`laiatime_override_until`). TRIAL y CANCELED no se tocan.
 *   - PAST_DUE → ACTIVE: solo si lo había informado el sistema (`laiatime_suspended_by_system`) y el cliente volvió a
 *     estar dentro de la gracia. Lo que marcó una persona a mano no se revierte solo.
 */
class LaiaTimeSync
{
    public const MISSING = 'missing';

    public function __construct(private readonly LaiaTimeApi $api) {}

    /**
     * @return array{checked: int, past_due: int, reactivated: int, missing: int, errors: int}
     *
     * @throws LaiaTimeException si la integración no está configurada o la lista no se pudo leer
     */
    public function run(bool $dryRun = false): array
    {
        $summary = ['checked' => 0, 'past_due' => 0, 'reactivated' => 0, 'missing' => 0, 'errors' => 0];

        $clients = Client::query()->where('is_active', true)->whereNotNull('laiatime_instance_id')->where('laiatime_instance_id', '!=', '')->get();
        if (! $this->api->isConfigured()) {
            throw LaiaTimeException::notConfigured();
        }
        if ($clients->isEmpty()) {
            return $summary;
        }

        $remote = collect($this->api->instances())->keyBy(fn (LaiaTimeInstance $i) => $i->id);

        foreach ($clients as $client) {
            $summary['checked']++;
            $instance = $remote->get($client->laiatime_instance_id);

            if ($instance === null) {
                $summary['missing']++;
                $this->record($client, self::MISSING, 'El negocio no existe en LaiaTime (revisá el ID de instancia del cliente).', $dryRun);

                continue;
            }

            try {
                $outcome = $this->apply($client, $instance, $dryRun);
                if ($outcome !== null) {
                    $summary[$outcome]++;
                }
            } catch (LaiaTimeException $e) {
                $summary['errors']++;
                $this->record($client, $instance->status, $e->friendlyMessage(), $dryRun);
            }
        }

        return $summary;
    }

    /** @return 'past_due'|'reactivated'|null */
    private function apply(Client $client, LaiaTimeInstance $instance, bool $dryRun): ?string
    {
        $overdue = DebtPolicy::overdueDays($client);
        $outcome = null;
        $status = $instance->status;

        $overridden = $client->laiatime_override_until !== null && $client->laiatime_override_until->gte(today());
        $shouldMark = DebtPolicy::autoSuspend() && DebtPolicy::isOutOfGrace($overdue) && ! $overridden && $instance->isActive();
        $shouldReactivate = $instance->isPastDue() && $client->laiatime_suspended_by_system && ! DebtPolicy::isOutOfGrace($overdue);

        if ($shouldMark) {
            if (! $dryRun) {
                $this->api->markPastDue($instance->id);
            }
            $status = LaiaTimeInstance::PAST_DUE;
            $outcome = 'past_due';
        } elseif ($shouldReactivate) {
            if (! $dryRun) {
                $this->api->markActive($instance->id);
            }
            $status = LaiaTimeInstance::ACTIVE;
            $outcome = 'reactivated';
        }

        if ($dryRun) {
            return $outcome;
        }

        $client->forceFill(['laiatime_status' => $status, 'laiatime_synced_at' => now(), 'laiatime_last_error' => null]);

        if ($outcome === 'past_due') {
            $client->forceFill(['laiatime_suspended_at' => now(), 'laiatime_suspended_by_system' => true]);
        } elseif ($outcome === 'reactivated' || ($instance->isActive() && $client->laiatime_suspended_by_system)) {
            // Reactivada por el sistema, o alguien la reactivó directamente en LaiaTime: el rastro ya no aplica.
            $client->forceFill(['laiatime_suspended_at' => null, 'laiatime_suspended_by_system' => false]);
        }

        $client->save();

        if ($outcome === 'past_due') {
            $this->alert("LaiaTime: {$client->displayName()} pasó a mora", "Se informó la mora a LaiaTime: {$overdue} días vencidos (gracia de ".DebtPolicy::graceDays().' días).');
        } elseif ($outcome === 'reactivated') {
            $this->alert("LaiaTime: {$client->displayName()} volvió a estar al día", 'Regularizó sus cobros y se informó a LaiaTime que su suscripción está activa.');
        }

        return $outcome;
    }

    /** Suspensión manual (acción de una persona): informa mora aunque la gracia no haya vencido. */
    public function markPastDue(string $instanceId): void
    {
        $this->api->markPastDue($instanceId);

        $client = Client::where('laiatime_instance_id', $instanceId)->first();
        $client?->forceFill([
            'laiatime_status' => LaiaTimeInstance::PAST_DUE,
            'laiatime_synced_at' => now(),
            'laiatime_suspended_at' => now(),
            'laiatime_suspended_by_system' => false,
            'laiatime_override_until' => null,
            'laiatime_last_error' => null,
        ])->save();
    }

    /** Reactivación manual: si el cliente sigue en mora se le concede un nuevo plazo de gracia. */
    public function markActive(string $instanceId): void
    {
        $this->api->markActive($instanceId);

        $client = Client::where('laiatime_instance_id', $instanceId)->first();
        $client?->forceFill([
            'laiatime_status' => LaiaTimeInstance::ACTIVE,
            'laiatime_synced_at' => now(),
            'laiatime_suspended_at' => null,
            'laiatime_suspended_by_system' => false,
            'laiatime_override_until' => DebtPolicy::overdueDays($client) > 0 ? DebtPolicy::overrideDeadline() : null,
            'laiatime_last_error' => null,
        ])->save();
    }

    private function record(Client $client, string $status, string $error, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $error = Str::limit($this->redact($error), 250, '…');
        $changed = $client->laiatime_last_error !== $error;

        $client->forceFill(['laiatime_status' => $status, 'laiatime_synced_at' => now(), 'laiatime_last_error' => $error])->save();

        // Se avisa una sola vez por falla distinta: el comando corre cada hora.
        if ($changed) {
            $this->alert("LaiaTime: problema con {$client->displayName()}", $error);
        }
    }

    private function redact(string $text): string
    {
        $key = (string) Settings::get('laiatime.api_key', '');
        $secret = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : '';

        return str_replace(array_filter([$key, $secret], fn ($s) => strlen($s) >= 6), '[clave]', $text);
    }

    private function alert(string $title, string $body): void
    {
        User::role(['sysadmin', 'dueno'])->where('is_active', true)->get()
            ->each(fn (User $u) => $u->notify(new LaiaTimeAlert($title, $body)));
    }
}
