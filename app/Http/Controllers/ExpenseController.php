<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseFrequency;
use App\Models\Expense;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Egresos y suscripciones de Laia (Adobe, Canva, VPS, APIs…). Ver: finance.view · modificar: finance.manage. */
class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $expenses = Expense::orderByDesc('is_active')->orderBy('name')->get();

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses->map(fn (Expense $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'category' => $e->category->value,
                'category_label' => $e->category->label(),
                'amount' => Money::toDecimal($e->amount),
                'amount_label' => Money::format($e->amount, $e->currency),
                'monthly_label' => $e->frequency === ExpenseFrequency::Unico ? null : Money::format($e->monthlyEquivalent(), $e->currency),
                'currency' => $e->currency->value,
                'frequency' => $e->frequency->value,
                'frequency_label' => $e->frequency->label(),
                'starts_on' => $e->starts_on->toDateString(),
                'ends_on' => $e->ends_on?->toDateString(),
                'is_active' => $e->is_active,
                'notes' => $e->notes,
            ]),
            // Costo fijo mensual por moneda (mensual + anual/12 de lo vigente).
            'monthlyTotals' => collect(Currency::cases())->map(fn (Currency $c) => [
                'currency' => $c->value,
                'label' => Money::format((int) $expenses->where('is_active', true)->where('currency', $c)->sum(fn (Expense $e) => $e->monthlyEquivalent()), $c),
            ])->filter(fn ($t) => $t['label'] !== Money::format(0, $t['currency']))->values(),
            'categories' => ExpenseCategory::options(),
            'frequencies' => ExpenseFrequency::options(),
            'currencies' => Currency::options(),
            'can' => ['manage' => $request->user()->can('finance.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $expense = Expense::create($this->validated($request) + ['created_by' => $request->user()->id]);
        activity('finance')->performedOn($expense)->causedBy($request->user())->log('Egreso agregado');

        return back()->with('success', 'Egreso agregado.');
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $expense->update($this->validated($request));
        activity('finance')->performedOn($expense)->causedBy($request->user())->log('Egreso actualizado');

        return back()->with('success', 'Egreso actualizado.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $expense->delete();
        activity('finance')->performedOn($expense)->causedBy($request->user())->log('Egreso eliminado');

        return back()->with('success', 'Egreso eliminado.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'frequency' => ['required', Rule::enum(ExpenseFrequency::class)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['amount'] = Money::toCents($data['amount']);

        return $data;
    }
}
