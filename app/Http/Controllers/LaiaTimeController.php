<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\LaiaTime\DebtPolicy;
use App\Services\LaiaTime\LaiaTimeApi;
use App\Services\LaiaTime\LaiaTimeException;
use App\Services\LaiaTime\LaiaTimeInstance;
use App\Services\LaiaTime\LaiaTimeSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Integración con LaiaTime. Ver: finance.view · actuar (sincronizar, marcar mora, reactivar): finance.manage.
 * Agency OS no "suspende" nada en LaiaTime: informa el estado de pago de la suscripción (ACTIVE ↔ PAST_DUE).
 */
class LaiaTimeController extends Controller
{
    public function __construct(private readonly LaiaTimeApi $api, private readonly LaiaTimeSync $sync) {}

    public function index(Request $request): Response
    {
        $error = null;
        $instances = [];

        if ($this->api->isConfigured()) {
            try {
                $instances = $this->api->instances();
            } catch (LaiaTimeException $e) {
                $error = ['kind' => $e->kind(), 'message' => $e->friendlyMessage()];
            }
        }

        $clients = Client::whereNotNull('laiatime_instance_id')->where('laiatime_instance_id', '!=', '')->get()
            ->keyBy('laiatime_instance_id');

        $rows = collect($instances)->map(fn (LaiaTimeInstance $i) => [
            'id' => $i->id,
            'name' => $i->name,
            'status' => $i->status,
            'plan' => $i->plan,
            'renews_at' => $i->renewsAt,
            'client' => ($c = $clients->get($i->id)) ? $this->clientSummary($c) : null,
        ])->values();

        $remoteIds = collect($instances)->pluck('id');
        $dangling = $error === null && $this->api->isConfigured()
            ? $clients->reject(fn (Client $c, string $id) => $remoteIds->contains($id))->map(fn (Client $c) => $this->clientSummary($c))->values()
            : collect();

        return Inertia::render('LaiaTime/Index', [
            'configured' => $this->api->isConfigured(),
            'issue' => $this->api->configurationIssue(),
            'error' => $error,
            'instances' => $rows,
            'dangling' => $dangling,
            'policy' => ['auto_suspend' => DebtPolicy::autoSuspend(), 'grace_days' => DebtPolicy::graceDays()],
            'can' => ['manage' => $request->user()->can('finance.manage')],
        ]);
    }

    public function ping(): RedirectResponse
    {
        try {
            $this->api->ping();
        } catch (LaiaTimeException $e) {
            return back()->with('error', $e->friendlyMessage());
        }

        return back()->with('success', 'Conexión con LaiaTime correcta: credenciales y permisos de lectura en orden.');
    }

    /** Estado del cliente para el panel de su ficha (JSON). Con `?live=1` consulta a LaiaTime y actualiza lo guardado. */
    public function client(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        $error = null;
        if ($client->hasLaiaTime() && $request->boolean('live')) {
            try {
                $instance = $this->api->instance($client->laiatime_instance_id);
                $client->forceFill(['laiatime_status' => $instance->status, 'laiatime_synced_at' => now(), 'laiatime_last_error' => null])->save();
            } catch (LaiaTimeException $e) {
                $error = ['kind' => $e->kind(), 'message' => $e->friendlyMessage()];
            }
        }

        return response()->json([
            'configured' => $this->api->isConfigured(),
            'linked' => $client->hasLaiaTime(),
            'client' => $client->hasLaiaTime() ? $this->clientSummary($client) : null,
            'error' => $error,
            'policy' => ['auto_suspend' => DebtPolicy::autoSuspend(), 'grace_days' => DebtPolicy::graceDays()],
            'can' => ['manage' => $request->user()->can('finance.manage')],
        ]);
    }

    public function sync(): RedirectResponse
    {
        try {
            $r = $this->sync->run();
        } catch (LaiaTimeException $e) {
            return back()->with('error', $e->friendlyMessage());
        }

        $message = "Sincronizado: {$r['checked']} clientes revisados, {$r['past_due']} en mora, {$r['reactivated']} reactivados";
        $message .= $r['missing'] > 0 ? ", {$r['missing']} sin negocio en LaiaTime." : '.';

        return back()->with($r['errors'] > 0 ? 'error' : 'success', $r['errors'] > 0 ? $message." {$r['errors']} con error." : $message);
    }

    public function suspend(Request $request, string $instance): RedirectResponse
    {
        return $this->act($request, $instance, 'mora', fn () => $this->sync->markPastDue($instance), 'Se informó la mora a LaiaTime: la suscripción quedó en PAST_DUE.');
    }

    public function reactivate(Request $request, string $instance): RedirectResponse
    {
        return $this->act($request, $instance, 'reactivación', fn () => $this->sync->markActive($instance), 'Se informó a LaiaTime que la suscripción está activa.');
    }

    private function act(Request $request, string $instance, string $what, \Closure $do, string $ok): RedirectResponse
    {
        try {
            $do();
        } catch (LaiaTimeException $e) {
            return back()->with('error', $e->friendlyMessage());
        }

        activity('laiatime')->causedBy($request->user())->withProperties(['instance' => $instance])->log("LaiaTime: {$what} manual");

        return back()->with('success', $ok);
    }

    /** @return array<string, mixed> */
    private function clientSummary(Client $c): array
    {
        $overdue = $c->hasLaiaTime() ? DebtPolicy::overdueDays($c) : 0;

        return [
            'id' => $c->id,
            'name' => $c->displayName(),
            'instance_id' => $c->laiatime_instance_id,
            'account_status' => $c->account_status->value,
            'laiatime_status' => $c->laiatime_status,
            'synced_at' => $c->laiatime_synced_at?->toIso8601String(),
            'suspended_at' => $c->laiatime_suspended_at?->toIso8601String(),
            'suspended_by_system' => $c->laiatime_suspended_by_system,
            'override_until' => $c->laiatime_override_until?->toDateString(),
            'last_error' => $c->laiatime_last_error,
            'overdue_days' => $overdue,
            'in_grace' => DebtPolicy::isInGrace($overdue),
            'out_of_grace' => DebtPolicy::isOutOfGrace($overdue),
        ];
    }
}
