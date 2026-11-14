<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\ConvertQuoteToClient;
use App\Actions\Quotes\SaveQuote;
use App\Enums\Currency;
use App\Enums\Frequency;
use App\Enums\QuoteStatus;
use App\Enums\SplitRule;
use App\Http\Requests\ConvertQuoteRequest;
use App\Http\Requests\QuoteRequest;
use App\Mail\QuoteMail;
use App\Support\MailTemplates;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuotePdf;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Quote::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', array_column(QuoteStatus::cases(), 'value'))],
        ]);

        $quotes = Quote::query()->with('client:id,business_name,trade_name')
            ->when($filters['q'] ?? null, function (Builder $query, string $q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $query->where(fn (Builder $w) => $w
                    ->where('number', 'ilike', $like)
                    ->orWhere('prospect_name', 'ilike', $like)
                    ->orWhereHas('client', fn (Builder $c) => $c->where('business_name', 'ilike', $like)->orWhere('trade_name', 'ilike', $like)));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $s) => $this->filterByEffectiveStatus($query, $s))
            ->latest('id')->paginate(12)->withQueryString()
            ->through(fn (Quote $q) => [
                'id' => $q->id,
                'number' => $q->number,
                'recipient' => $q->recipientName(),
                'is_prospect' => $q->client_id === null,
                'total_label' => Money::format($q->total, $q->currency),
                'status' => $q->effectiveStatus()->value,
                'valid_until' => $q->valid_until?->toDateString(),
                'created_at' => $q->created_at->toIso8601String(),
            ]);

        $open = Quote::whereIn('status', ['borrador', 'enviado'])
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', today()));

        return Inertia::render('Quotes/Index', [
            'quotes' => $quotes,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
            'statusOptions' => QuoteStatus::options(),
            'summary' => [
                'drafts' => (clone $open)->where('status', 'borrador')->count(),
                'sent' => (clone $open)->where('status', 'enviado')->count(),
                'approved_30d' => Quote::where('status', 'aprobado')->where('approved_at', '>=', now()->subDays(30))->count(),
                // Lo "en la calle" (enviado y vigente) por moneda: no se mezclan pesos con dólares.
                'pipeline' => collect(Currency::cases())->map(fn (Currency $c) => [
                    'currency' => $c->value,
                    'label' => Money::format((int) (clone $open)->where('status', 'enviado')->where('currency', $c->value)->sum('total'), $c),
                ])->filter(fn ($p) => $p['label'] !== Money::format(0, $p['currency']))->values(),
            ],
            'can' => ['create' => $request->user()->can('create', Quote::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Quote::class);

        return Inertia::render('Quotes/Form', $this->formProps(null, $request->integer('client') ?: null));
    }

    public function store(QuoteRequest $request, SaveQuote $save): RedirectResponse
    {
        Gate::authorize('create', Quote::class);

        $quote = $save->handle($request->user(), $request->validated(), null, $request->file('custom_pdf'));
        activity('quotes')->performedOn($quote)->causedBy($request->user())->log('Presupuesto creado');

        return redirect()->route('quotes.show', $quote)->with('success', "Presupuesto {$quote->number} creado.");
    }

    public function show(Request $request, Quote $quote): Response
    {
        Gate::authorize('view', $quote);
        $quote->load('items', 'client', 'creator', 'convertedClient');
        $user = $request->user();

        $phone = preg_replace('/\D/', '', (string) $quote->recipientPhone());

        return Inertia::render('Quotes/Show', [
            'quote' => [
                'id' => $quote->id,
                'number' => $quote->number,
                'status' => $quote->effectiveStatus()->value,
                'currency' => $quote->currency->value,
                'recipient' => [
                    'name' => $quote->recipientName(),
                    'is_prospect' => $quote->client_id === null,
                    'client_id' => $quote->client_id,
                    'email' => $quote->recipientEmail(),
                    'phone' => $quote->recipientPhone(),
                    'tax_id' => $quote->client?->tax_id ?? $quote->prospect_tax_id,
                ],
                'items' => $quote->items->map(fn ($i) => [
                    'id' => $i->id,
                    'description' => $i->description,
                    'quantity' => $i->quantity,
                    'frequency' => $i->frequency->value,
                    'frequency_label' => $i->frequency->label(),
                    'unit_price_label' => Money::format($i->unit_price, $quote->currency),
                    'total_label' => Money::format($i->lineTotal(), $quote->currency),
                    'total' => Money::toDecimal($i->lineTotal()),
                ]),
                'monthly_label' => Money::format($quote->subtotalFor(Frequency::Mensual), $quote->currency),
                'once_label' => Money::format($quote->subtotalFor(Frequency::Unico), $quote->currency),
                'discount_percent' => (float) $quote->discount_percent,
                'discount_label' => Money::format($quote->discount, $quote->currency),
                'total_label' => Money::format($quote->total, $quote->currency),
                'valid_until' => $quote->valid_until?->toDateString(),
                'notes' => $quote->notes,
                'custom_pdf' => $quote->hasCustomPdf() ? $quote->custom_pdf_name : null,
                'created_at' => $quote->created_at->toIso8601String(),
                'sent_at' => $quote->sent_at?->toIso8601String(),
                'approved_at' => $quote->approved_at?->toIso8601String(),
                'rejected_at' => $quote->rejected_at?->toIso8601String(),
                'creator' => $quote->creator?->name,
                'converted_client' => $quote->convertedClient ? ['id' => $quote->convertedClient->id, 'name' => $quote->convertedClient->displayName()] : null,
            ],
            'share' => [
                'whatsapp_url' => $phone !== '' ? 'https://wa.me/'.$phone.'?text='.rawurlencode(
                    "¡Hola {$quote->recipientName()}! Te comparto el presupuesto {$quote->number} de Laia Estudio Creativo Digital: "
                    .URL::temporarySignedRoute('quotes.public_pdf', now()->addDays(30), ['quote' => $quote->id])
                ) : null,
            ],
            'can' => [
                'update' => $user->can('update', $quote),
                'resolve' => $user->can('resolve', $quote) && $quote->isEditable(),
                'convert' => $user->can('resolve', $quote) && $quote->isConvertible(),
                'delete' => $user->can('delete', $quote),
            ],
            'expired' => $quote->isExpired(),
            'convert' => [
                'assignable' => User::role(['empleado', 'dueno'])->where('is_active', true)->orderBy('name')->get(['id', 'name', 'job_title']),
                'splitRules' => SplitRule::options(),
            ],
        ]);
    }

    public function edit(Quote $quote): Response
    {
        Gate::authorize('update', $quote);

        return Inertia::render('Quotes/Form', $this->formProps($quote->load('items')));
    }

    public function update(QuoteRequest $request, Quote $quote, SaveQuote $save): RedirectResponse
    {
        Gate::authorize('update', $quote);

        $save->handle($request->user(), $request->validated(), $quote, $request->file('custom_pdf'), $request->boolean('remove_custom_pdf'));
        activity('quotes')->performedOn($quote)->causedBy($request->user())->log('Presupuesto actualizado');

        return redirect()->route('quotes.show', $quote)->with('success', 'Presupuesto actualizado.');
    }

    public function destroy(Request $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('delete', $quote);

        $quote->delete();
        activity('quotes')->performedOn($quote)->causedBy($request->user())->log('Presupuesto eliminado');

        return redirect()->route('quotes.index')->with('success', 'Presupuesto eliminado.');
    }

    /** Marca como enviado. Por email se envía el PDF; por WhatsApp el navegador abre el enlace y acá solo se registra. */
    public function send(Request $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('resolve', $quote);
        $data = $request->validate(['channel' => ['required', 'in:email,whatsapp']]);

        $this->ensureSendable($quote);

        if ($data['channel'] === 'email') {
            $email = $quote->recipientEmail();
            if (blank($email)) {
                throw ValidationException::withMessages(['channel' => 'El destinatario no tiene email cargado.']);
            }
            if (! MailTemplates::enabled('quote.sent')) {
                throw ValidationException::withMessages(['channel' => 'El correo «Envío de presupuesto» está desactivado en Administración → Plantillas de correo.']);
            }
            Mail::to($email)->send(new QuoteMail($quote));
        }

        $quote->forceFill(['status' => QuoteStatus::Enviado, 'sent_at' => now()])->save();

        activity('quotes')->performedOn($quote)->causedBy($request->user())
            ->withProperties(['channel' => $data['channel']])->log('Presupuesto enviado por '.($data['channel'] === 'email' ? 'email' : 'WhatsApp'));

        return back()->with('success', $data['channel'] === 'email' ? 'Presupuesto enviado por email.' : 'Presupuesto marcado como enviado.');
    }

    public function reject(Request $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('resolve', $quote);
        abort_unless($quote->isEditable(), 422, 'Este presupuesto ya fue resuelto.');

        $quote->forceFill(['status' => QuoteStatus::Rechazado, 'rejected_at' => now()])->save();
        activity('quotes')->performedOn($quote)->causedBy($request->user())->log('Presupuesto rechazado');

        return back()->with('success', 'Presupuesto marcado como rechazado.');
    }

    public function convert(ConvertQuoteRequest $request, Quote $quote, ConvertQuoteToClient $convert): RedirectResponse
    {
        Gate::authorize('resolve', $quote);

        $client = $convert->handle($quote, $request->user(), $request->validated());

        return redirect()->route('clients.show', $client)->with('success', "¡Presupuesto aprobado! {$client->displayName()} ya es cliente activo.");
    }

    /** Descarga autenticada del PDF. */
    public function pdf(Quote $quote): HttpResponse
    {
        Gate::authorize('view', $quote);

        return $this->pdfResponse($quote);
    }

    /** Enlace público firmado (para WhatsApp / email): no requiere sesión, pero sí firma válida y vigente. */
    public function publicPdf(Quote $quote): HttpResponse
    {
        return $this->pdfResponse($quote);
    }

    private function pdfResponse(Quote $quote): HttpResponse
    {
        return response(QuotePdf::render($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Presupuesto-'.$quote->number.'.pdf"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ensureSendable(Quote $quote): void
    {
        if (! $quote->isEditable()) {
            throw ValidationException::withMessages(['channel' => 'Este presupuesto ya fue resuelto.']);
        }
        if ($quote->isExpired()) {
            throw ValidationException::withMessages(['channel' => 'El presupuesto venció. Extendé la fecha de validez antes de enviarlo.']);
        }
    }

    /** El estado "vencido" no se guarda: se deduce de la fecha de validez. */
    private function filterByEffectiveStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'vencido' => $query->whereIn('status', ['borrador', 'enviado'])->whereDate('valid_until', '<', today()),
            'borrador', 'enviado' => $query->where('status', $status)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today())),
            default => $query->where('status', $status),
        };
    }

    /** @return array<string, mixed> */
    private function formProps(?Quote $quote, ?int $preselectedClient = null): array
    {
        return [
            'quote' => $quote ? [
                'id' => $quote->id,
                'number' => $quote->number,
                'client_id' => $quote->client_id,
                'prospect_name' => $quote->prospect_name,
                'prospect_email' => $quote->prospect_email,
                'prospect_phone' => $quote->prospect_phone,
                'prospect_tax_id' => $quote->prospect_tax_id,
                'currency' => $quote->currency->value,
                'discount_percent' => (float) $quote->discount_percent,
                'valid_until' => $quote->valid_until?->toDateString(),
                'notes' => $quote->notes,
                'custom_pdf_name' => $quote->hasCustomPdf() ? $quote->custom_pdf_name : null,
                'items' => $quote->items->map(fn ($i) => [
                    'service_id' => $i->service_id,
                    'description' => $i->description,
                    'quantity' => $i->quantity,
                    'unit_price' => Money::toDecimal($i->unit_price),
                    'frequency' => $i->frequency->value,
                ]),
            ] : null,
            'preselectedClient' => $preselectedClient,
            'clients' => Client::where('is_active', true)->orderBy('business_name')
                ->get(['id', 'business_name', 'trade_name', 'email'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->displayName(), 'email' => $c->email]),
            'catalog' => CatalogService::where('is_active', true)->orderBy('name')->get()->map(fn (CatalogService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'price' => Money::toDecimal($s->price),
                'currency' => $s->currency->value,
                'frequency' => $s->frequency->value,
            ]),
            'currencies' => Currency::options(),
            'frequencies' => Frequency::options(),
            'defaultValidUntil' => now()->addDays(15)->toDateString(),
        ];
    }
}
