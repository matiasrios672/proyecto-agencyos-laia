<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\Currency;
use App\Enums\Frequency;
use App\Enums\SplitRule;
use App\Enums\TaxCondition;
use App\Enums\VaultCategory;
use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', array_column(AccountStatus::cases(), 'value'))],
        ]);

        $clients = Client::query()
            ->visibleTo($request->user())
            ->withCount('users')
            ->when($filters['q'] ?? null, function ($query, $q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $query->where(fn ($w) => $w
                    ->where('business_name', 'ilike', $like)
                    ->orWhere('trade_name', 'ilike', $like)
                    ->orWhere('tax_id', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like));
            })
            ->when($filters['status'] ?? null, fn ($query, $s) => $query->where('account_status', $s))
            ->orderBy('business_name')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->displayName(),
                'business_name' => $c->business_name,
                'tax_id' => $c->tax_id,
                'email' => $c->email,
                'phone' => $c->phone,
                'account_status' => $c->account_status->value,
                'is_active' => $c->is_active,
                'team_count' => $c->users_count,
            ]);

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
            'statusOptions' => AccountStatus::options(),
            'can' => ['create' => $request->user()->can('create', Client::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Client::class);

        return Inertia::render('Clients/Form', [
            'client' => null,
            'taxConditions' => TaxCondition::options(),
        ]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $client = Client::create($request->validated());

        // Si quien crea no ve todos los clientes, queda asignado para no perder acceso al cliente que creó.
        if (! $request->user()->can('clients.view_all')) {
            $client->users()->attach($request->user()->id);
        }

        activity('clients')->performedOn($client)->causedBy($request->user())->log('Cliente creado');

        return redirect()->route('clients.show', $client)->with('success', 'Cliente creado.');
    }

    public function show(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);
        $user = $request->user();

        $canReveal = $user->can('revealVault', $client);
        $canFinance = $user->can('viewFinance', $client);

        return Inertia::render('Clients/Show', [
            'client' => [
                'id' => $client->id,
                'business_name' => $client->business_name,
                'trade_name' => $client->trade_name,
                'name' => $client->displayName(),
                'tax_id' => $client->tax_id,
                'tax_condition' => $client->tax_condition?->value,
                'tax_condition_label' => $client->tax_condition?->label(),
                'contact_name' => $client->contact_name,
                'email' => $client->email,
                'phone' => $client->phone,
                'whatsapp' => $client->whatsapp,
                'website' => $client->website,
                'address' => $client->address,
                'city' => $client->city,
                'notes' => $client->notes,
                'account_status' => $client->account_status->value,
                'laiatime_instance_id' => $client->laiatime_instance_id,
                'is_active' => $client->is_active,
            ],
            'team' => $client->users()->orderBy('name')->get(['users.id', 'users.name', 'users.job_title'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title]),
            'vault' => $canReveal ? $client->vaultItems()->orderBy('category')->orderBy('label')->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'category' => $i->category->value,
                    'category_label' => $i->category->label(),
                    'label' => $i->label,
                    'url' => $i->url,
                    'username' => $i->username,
                    'has_secret' => $i->getRawOriginal('secret') !== null,
                    'has_notes' => $i->getRawOriginal('notes') !== null,
                ]) : [],
            'vaultCategories' => VaultCategory::options(),
            'statusOptions' => AccountStatus::options(),
            'services' => $client->services()->with('assignee:id,name')->orderBy('status')->orderBy('name')->get()
                ->map(fn (ClientService $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'frequency' => $s->frequency->value,
                    'frequency_label' => $s->frequency->label(),
                    'due_day' => $s->due_day,
                    'status' => $s->status,
                    'assignee' => $s->assignee ? ['id' => $s->assignee->id, 'name' => $s->assignee->name] : null,
                ] + ($canFinance ? [
                    // Montos y reparto solo para quien puede ver finanzas.
                    'amount_label' => Money::format($s->amount, $s->currency),
                    'amount' => Money::toDecimal($s->amount),
                    'currency' => $s->currency->value,
                    'split_rule' => $s->split_rule->value,
                    'split_label' => $this->splitLabel($s),
                    'split_percent' => $s->split_percent !== null ? (float) $s->split_percent : null,
                    'split_amount' => $s->split_amount !== null ? Money::toDecimal($s->split_amount) : null,
                ] : [])),
            // Cobros del cliente: solo para quien puede ver finanzas.
            'invoices' => $canFinance
                ? $client->invoices()->orderByDesc('due_date')->limit(12)->get()->map(fn ($i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'description' => $i->description,
                    'amount_label' => Money::format($i->amount, $i->currency),
                    'due_date' => $i->due_date->toDateString(),
                    'status' => $i->effectiveStatus()->value,
                ])
                : [],
            'serviceOptions' => $canFinance ? [
                'frequencies' => Frequency::options(),
                'splitRules' => SplitRule::options(),
                'currencies' => Currency::options(),
                'statuses' => ClientService::STATUSES,
            ] : null,
            'can' => [
                'viewFinance' => $canFinance,
                'manageServices' => $user->can('manageServices', $client),
                'update' => $user->can('update', $client),
                'delete' => $user->can('delete', $client),
                'assign' => $user->can('assign', $client),
                'revealVault' => $canReveal,
                'manageVault' => $user->can('manageVault', $client),
            ],
            // Candidatos a asignar (solo se envían a quien puede asignar).
            'assignable' => $user->can('assign', $client) || $user->can('manageServices', $client)
                ? User::role(['empleado', 'dueno'])->where('is_active', true)->orderBy('name')
                    ->get(['id', 'name', 'job_title'])
                : [],
        ]);
    }

    private function splitLabel(ClientService $s): string
    {
        if ($s->assigned_user_id === null) {
            return 'Sin empleado asignado';
        }

        return match ($s->split_rule) {
            SplitRule::Porcentaje => rtrim(rtrim(number_format((float) $s->split_percent, 2, ',', '.'), '0'), ',').' % para el empleado',
            SplitRule::MontoFijo => Money::format((int) $s->split_amount, $s->currency).' fijos para el empleado',
            SplitRule::Agencia100 => '100 % agencia',
        };
    }

    public function edit(Client $client): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('Clients/Form', [
            'client' => $client->only([
                'id', 'business_name', 'trade_name', 'tax_id', 'contact_name', 'email', 'phone', 'whatsapp',
                'website', 'address', 'city', 'notes', 'laiatime_instance_id', 'is_active',
            ]) + ['tax_condition' => $client->tax_condition?->value],
            'taxConditions' => TaxCondition::options(),
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('update', $client);

        $client->fill($request->validated());

        // Otra instancia de LaiaTime: el estado guardado de la anterior ya no corresponde.
        if ($client->isDirty('laiatime_instance_id')) {
            $client->forceFill([
                'laiatime_status' => null, 'laiatime_synced_at' => null, 'laiatime_suspended_at' => null,
                'laiatime_suspended_by_system' => false, 'laiatime_override_until' => null, 'laiatime_last_error' => null,
            ]);
        }

        $client->save();
        activity('clients')->performedOn($client)->causedBy($request->user())->log('Cliente actualizado');

        return redirect()->route('clients.show', $client)->with('success', 'Cliente actualizado.');
    }

    public function destroy(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('delete', $client);

        $client->delete();
        activity('clients')->performedOn($client)->causedBy($request->user())->log('Cliente eliminado');

        return redirect()->route('clients.index')->with('success', 'Cliente eliminado.');
    }
}
