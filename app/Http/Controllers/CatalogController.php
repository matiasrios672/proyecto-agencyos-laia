<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Enums\Frequency;
use App\Http\Requests\CatalogServiceRequest;
use App\Models\CatalogService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Catálogo de servicios prearmados para armar presupuestos rápido. */
class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CatalogService::class);

        return Inertia::render('Catalog/Index', [
            'services' => CatalogService::orderByDesc('is_active')->orderBy('name')->get()->map(fn (CatalogService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'price' => Money::toDecimal($s->price),
                'price_label' => Money::format($s->price, $s->currency),
                'currency' => $s->currency->value,
                'frequency' => $s->frequency->value,
                'frequency_label' => $s->frequency->label(),
                'is_active' => $s->is_active,
            ]),
            'currencies' => Currency::options(),
            'frequencies' => Frequency::options(),
            'can' => ['manage' => $request->user()->can('create', CatalogService::class)],
        ]);
    }

    public function store(CatalogServiceRequest $request): RedirectResponse
    {
        Gate::authorize('create', CatalogService::class);

        $service = CatalogService::create($this->attributes($request->validated()));
        activity('catalog')->performedOn($service)->causedBy($request->user())->log('Servicio agregado al catálogo');

        return back()->with('success', 'Servicio agregado al catálogo.');
    }

    public function update(CatalogServiceRequest $request, CatalogService $service): RedirectResponse
    {
        Gate::authorize('update', $service);

        $service->update($this->attributes($request->validated()));
        activity('catalog')->performedOn($service)->causedBy($request->user())->log('Servicio del catálogo actualizado');

        return back()->with('success', 'Servicio actualizado.');
    }

    public function destroy(Request $request, CatalogService $service): RedirectResponse
    {
        Gate::authorize('delete', $service);

        // Los presupuestos ya emitidos conservan su propia copia de la descripción y el precio.
        $service->delete();
        activity('catalog')->performedOn($service)->causedBy($request->user())->log('Servicio eliminado del catálogo');

        return back()->with('success', 'Servicio eliminado.');
    }

    private function attributes(array $data): array
    {
        $data['price'] = Money::toCents($data['price']);

        return $data;
    }
}
