<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canClients = $user->can('viewAny', Client::class);

        $counts = $canClients
            ? Client::visibleTo($user)->where('is_active', true)
                ->selectRaw('account_status, count(*) as total')->groupBy('account_status')
                ->pluck('total', 'account_status')
            : collect();

        return Inertia::render('Dashboard', [
            'stats' => $canClients ? [
                'total' => (int) $counts->sum(),
                'by_status' => collect(AccountStatus::cases())
                    ->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
                'team' => $user->can('employees.manage')
                    ? User::role(['empleado', 'dueno'])->where('is_active', true)->count()
                    : null,
            ] : null,
            // Clientes que requieren atención primero (deuda, por vencer), dentro de lo que el usuario puede ver.
            'attention' => $canClients
                ? Client::visibleTo($user)->where('is_active', true)
                    ->whereIn('account_status', [AccountStatus::Deuda->value, AccountStatus::PorVencer->value])
                    ->orderByRaw("case account_status when 'deuda' then 0 else 1 end")
                    ->orderBy('business_name')->limit(6)->get()
                    ->map(fn (Client $c) => [
                        'id' => $c->id, 'name' => $c->displayName(), 'account_status' => $c->account_status->value,
                    ])
                : [],
            'recent' => $user->can('employees.manage') || $user->isSysadmin()
                ? Activity::with('causer:id,name')->whereIn('log_name', ['clients', 'employees'])
                    ->latest()->limit(6)->get()
                    ->map(fn (Activity $a) => [
                        'id' => $a->id,
                        'description' => $a->description,
                        'causer' => $a->causer?->name,
                        'at' => $a->created_at?->toIso8601String(),
                    ])
                : [],
        ]);
    }
}
