<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/** Clientes eliminados (soft delete): listado y restauración. Solo con `clients.manage` (o sysadmin). */
class ClientTrashController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewTrashed', Client::class);

        $q = (string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');

        $clients = Client::onlyTrashed()
            ->when($q !== '', function (Builder $query) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $query->where(fn (Builder $w) => $w->where('business_name', 'ilike', $like)->orWhere('trade_name', 'ilike', $like)->orWhere('tax_id', 'ilike', $like));
            })
            ->withCount(['services', 'vaultItems', 'invoices'])
            ->orderByDesc('deleted_at')->paginate(12)->withQueryString();

        // Quién y cuándo lo eliminó, en una sola consulta para la página.
        $deletions = Activity::where('subject_type', Client::class)->whereIn('subject_id', $clients->pluck('id'))
            ->where('description', 'Cliente eliminado')->with('causer:id,name')->latest()->get()->unique('subject_id')->keyBy('subject_id');

        return Inertia::render('Clients/Trashed', [
            'clients' => $clients->through(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->displayName(),
                'business_name' => $c->business_name,
                'tax_id' => $c->tax_id,
                'deleted_at' => $c->deleted_at->toIso8601String(),
                'deleted_by' => $deletions->get($c->id)?->causer?->name,
                'services_count' => $c->services_count,
                'vault_count' => $c->vault_items_count,
                'invoices_count' => $c->invoices_count,
            ]),
            'filters' => ['q' => $q],
        ]);
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $client = Client::onlyTrashed()->findOrFail($id);
        Gate::authorize('restore', $client);

        DB::transaction(function () use ($client, $request) {
            // El CUIT puede haberse reutilizado en un cliente activo mientras este estaba eliminado.
            if ($client->tax_id !== null) {
                $holder = Client::where('tax_id', $client->tax_id)->lockForUpdate()->first();
                if ($holder !== null) {
                    throw ValidationException::withMessages([
                        'restore' => "No se puede restaurar: el CUIT {$client->tax_id} ya lo usa el cliente activo «{$holder->displayName()}». Cambiá uno de los dos antes de restaurar.",
                    ]);
                }
            }

            $client->restore();

            activity('clients')->performedOn($client)->causedBy($request->user())->log('Cliente restaurado');
        });

        return redirect()->route('clients.show', $client)->with('success', "{$client->displayName()} fue restaurado con sus servicios, bóveda y cobros.");
    }
}
