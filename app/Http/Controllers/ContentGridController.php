<?php

namespace App\Http\Controllers;

use App\Actions\Content\StoreContentGrid;
use App\Enums\ContentStatus;
use App\Http\Requests\UploadContentGridRequest;
use App\Models\Client;
use App\Models\ContentGrid;
use App\Services\PdfStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Grillas mensuales de contenido (PDF de Canva): una por cliente y mes. */
class ContentGridController extends Controller
{
    public function __construct(private readonly PdfStorage $pdfs) {}

    /** Panorama global de los clientes que el usuario puede ver. */
    public function overview(Request $request): Response
    {
        Gate::authorize('viewAny', ContentGrid::class);
        $user = $request->user();

        $filters = $request->validate([
            'client' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ContentStatus::class)],
            'month' => ['nullable', 'regex:/^20\d{2}-(0[1-9]|1[0-2])$/'],
        ]);

        $visible = fn () => Client::visibleTo($user)->where('is_active', true);
        $current = now()->startOfMonth();

        $grids = ContentGrid::query()->with(['client:id,business_name,trade_name,whatsapp', 'uploader:id,name'])
            ->whereIn('client_id', Client::visibleTo($user)->select('clients.id'))
            ->when($filters['client'] ?? null, fn (Builder $q, $id) => $q->where('client_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['month'] ?? null, fn (Builder $q, string $m) => $q->whereDate('month', Carbon::createFromFormat('!Y-m', $m)))
            ->orderByDesc('month')->orderBy('client_id')
            ->paginate(15)->withQueryString()
            ->through(fn (ContentGrid $g) => $this->present($g, $request));

        return Inertia::render('Content/Index', [
            'grids' => $grids,
            'filters' => ['client' => $filters['client'] ?? '', 'status' => $filters['status'] ?? '', 'month' => $filters['month'] ?? ''],
            'statusOptions' => ContentStatus::options(),
            'clients' => $visible()->orderBy('business_name')->get(['id', 'business_name', 'trade_name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->displayName()]),
            // Clientes activos que todavía no tienen la grilla del mes en curso.
            'missing' => $visible()->whereDoesntHave('contentGrids', fn ($q) => $q->whereDate('month', $current))
                ->orderBy('business_name')->get(['id', 'business_name', 'trade_name'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->displayName()]),
            'currentMonth' => ['value' => $current->format('Y-m'), 'label' => ucfirst($current->copy()->locale('es')->translatedFormat('F Y'))],
            'can' => ['manage' => $user->can('content.manage')],
        ]);
    }

    /** Grillas de un cliente. */
    public function clientIndex(Request $request, Client $client): Response
    {
        Gate::authorize('viewForClient', [ContentGrid::class, $client]);
        $current = now()->startOfMonth();

        $grids = $client->contentGrids()->with(['client:id,business_name,trade_name,whatsapp', 'uploader:id,name'])
            ->orderByDesc('month')->get();

        return Inertia::render('Content/Client', [
            'client' => ['id' => $client->id, 'name' => $client->displayName(), 'whatsapp' => $client->whatsapp],
            'grids' => $grids->map(fn (ContentGrid $g) => $this->present($g, $request)),
            'statusOptions' => ContentStatus::options(),
            'currentMonth' => ['value' => $current->format('Y-m'), 'label' => ucfirst($current->copy()->locale('es')->translatedFormat('F Y'))],
            'hasCurrent' => $grids->contains(fn (ContentGrid $g) => $g->month->isSameMonth($current)),
            'can' => ['manage' => $request->user()->can('manageForClient', [ContentGrid::class, $client])],
        ]);
    }

    public function store(UploadContentGridRequest $request, Client $client, StoreContentGrid $store): RedirectResponse
    {
        Gate::authorize('manageForClient', [ContentGrid::class, $client]);

        $grid = $store->handle($client, $request->user(), $request->validated(), $request->file('file'), $request->boolean('replace'));

        return back()->with('success', "Grilla de {$grid->monthLabel()} guardada.");
    }

    public function updateStatus(Request $request, ContentGrid $grid): RedirectResponse
    {
        Gate::authorize('manage', $grid);
        $data = $request->validate(['status' => ['required', Rule::enum(ContentStatus::class)]]);
        $to = ContentStatus::from($data['status']);
        $from = $grid->status;

        if ($from === $to) {
            return back();
        }

        $grid->forceFill([
            'status' => $to,
            // La aprobación se registra al aprobar y se pierde si la grilla vuelve a una etapa previa.
            // "En curso" es posterior a "aprobado": conserva la fecha de aprobación que ya tenía.
            'approved_at' => match ($to) {
                ContentStatus::Aprobado => now(),
                ContentStatus::EnCurso => $grid->approved_at,
                default => null,
            },
        ])->save();

        activity('content')->performedOn($grid)->causedBy($request->user())
            ->withProperties(['from' => $from->value, 'to' => $to->value, 'client_id' => $grid->client_id])
            ->log('Estado de la grilla modificado');

        return back()->with('success', "Estado: {$to->label()}.");
    }

    public function destroy(Request $request, ContentGrid $grid): RedirectResponse
    {
        Gate::authorize('manage', $grid);

        $path = $grid->file_path;
        $grid->delete();
        $this->pdfs->delete($path);

        activity('content')->performedOn($grid)->causedBy($request->user())
            ->withProperties(['client_id' => $grid->client_id, 'month' => $grid->month->format('Y-m')])
            ->log('Grilla de contenido eliminada');

        return back()->with('success', 'Grilla eliminada.');
    }

    /** PDF para el visor del modal (inline) o para descargar (?download=1). Solo con sesión y permiso. */
    public function file(Request $request, ContentGrid $grid)
    {
        Gate::authorize('view', $grid);

        return $this->fileResponse($grid, $request->boolean('download'));
    }

    /** Enlace público firmado (se comparte por WhatsApp): sin sesión, pero con firma válida y vigente. */
    public function publicFile(ContentGrid $grid)
    {
        return $this->fileResponse($grid, false);
    }

    /** Registra que la grilla se compartió. Por WhatsApp, además, pasa a "enviado al cliente" si todavía no lo estaba. */
    public function share(Request $request, ContentGrid $grid): RedirectResponse
    {
        Gate::authorize('manage', $grid);
        $data = $request->validate(['channel' => ['required', 'in:whatsapp,link']]);

        $grid->shared_at = now();
        if ($data['channel'] === 'whatsapp' && in_array($grid->status, [ContentStatus::Borrador, ContentStatus::RevisionInterna], true)) {
            $grid->status = ContentStatus::EnviadoCliente;
            $grid->approved_at = null;
        }
        $grid->save();

        activity('content')->performedOn($grid)->causedBy($request->user())
            ->withProperties(['channel' => $data['channel'], 'client_id' => $grid->client_id])
            ->log($data['channel'] === 'whatsapp' ? 'Grilla compartida por WhatsApp' : 'Enlace de la grilla copiado');

        return back();
    }

    private function fileResponse(ContentGrid $grid, bool $download)
    {
        $grid->loadMissing('client');
        abort_unless($this->pdfs->exists($grid->file_path), 404);

        return $this->pdfs->response($grid->file_path, $grid->downloadName(), $download);
    }

    /** @return array<string, mixed> */
    private function present(ContentGrid $g, Request $request): array
    {
        $user = $request->user();
        $manage = $user->can('manage', $g);
        $signed = URL::temporarySignedRoute('content.public_file', now()->addDays(30), ['grid' => $g->id]);
        $phone = preg_replace('/\D/', '', (string) $g->client?->whatsapp);

        return [
            'id' => $g->id,
            'client' => ['id' => $g->client_id, 'name' => $g->client?->displayName() ?? '—'],
            'month' => $g->month->format('Y-m'),
            'month_label' => $g->monthLabel(),
            'title' => $g->title,
            'status' => $g->status->value,
            'original_name' => $g->original_name,
            'size_label' => PdfStorage::humanSize($g->size),
            'notes' => $g->notes,
            'uploaded_by' => $g->uploader?->name,
            'approved_at' => $g->approved_at?->toIso8601String(),
            'shared_at' => $g->shared_at?->toIso8601String(),
            'updated_at' => $g->updated_at->toIso8601String(),
            'file_url' => route('content.file', $g),
            'download_url' => route('content.file', [$g, 'download' => 1]),
            // El enlace firmado y el de WhatsApp solo se entregan a quien puede compartir.
            'share_url' => $manage ? $signed : null,
            'whatsapp_url' => $manage && $phone !== '' ? 'https://wa.me/'.$phone.'?text='.rawurlencode(
                "¡Hola {$g->client?->displayName()}! Te comparto la grilla de contenido de {$g->monthLabel()} de Laia Estudio Creativo Digital: {$signed}"
            ) : null,
            'can' => ['manage' => $manage],
        ];
    }
}
