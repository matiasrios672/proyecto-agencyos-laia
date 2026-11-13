<?php

namespace App\Http\Controllers;

use App\Actions\Finance\SettlePayouts;
use App\Models\TeamPayout;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Liquidaciones al equipo. El empleado solo ve las suyas (TeamPayout::visibleTo); Dirección ve y liquida todas. */
class PayoutController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('payouts.view_own') || $user->can('finance.view'), 403);

        $seesAll = $user->can('finance.view');
        $filters = $request->validate([
            'status' => ['nullable', 'in:pendiente,liquidada'],
            'user' => ['nullable', 'integer'],
        ]);

        $base = TeamPayout::query()->visibleTo($user);

        $payouts = (clone $base)->with(['user:id,name', 'invoice.client:id,business_name,trade_name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($seesAll ? ($filters['user'] ?? null) : null, fn ($q, $id) => $q->where('user_id', $id))
            ->orderByRaw("case status when 'pendiente' then 0 else 1 end")->latest('id')
            ->paginate(15)->withQueryString()
            ->through(fn (TeamPayout $p) => [
                'id' => $p->id,
                'user' => $p->user->name,
                'user_id' => $p->user_id,
                'client' => $p->invoice->client->displayName(),
                'invoice' => $p->invoice->number,
                'calculation' => $p->calculation,
                'amount_label' => Money::format($p->amount, $p->currency),
                'status' => $p->status,
                'created_at' => $p->created_at->toIso8601String(),
                'settled_at' => $p->settled_at?->toIso8601String(),
                'note' => $p->settlement_note,
            ]);

        $totals = fn (string $status) => (clone $base)->where('status', $status)
            ->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency')
            ->map(fn ($cents, $code) => Money::format((int) $cents, $code))->values();

        return Inertia::render('Payouts/Index', [
            'payouts' => $payouts,
            'filters' => ['status' => $filters['status'] ?? '', 'user' => $filters['user'] ?? ''],
            'totals' => ['pending' => $totals('pendiente'), 'settled' => $totals('liquidada')],
            'employees' => $seesAll
                ? User::whereIn('id', TeamPayout::select('user_id'))->orderBy('name')->get(['id', 'name'])
                : [],
            'scope' => $seesAll ? 'all' : 'own',
            'can' => ['settle' => $user->can('finance.manage')],
        ]);
    }

    public function settle(Request $request, SettlePayouts $settle): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:team_payouts,id'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $count = $settle->handle($data['ids'], $request->user(), $data['note'] ?? null);

        return back()->with('success', $count === 1 ? 'Liquidación marcada como pagada.' : "{$count} liquidaciones marcadas como pagadas.");
    }
}
