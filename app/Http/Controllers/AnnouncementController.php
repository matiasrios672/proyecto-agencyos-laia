<?php

namespace App\Http\Controllers;

use App\Actions\Finance\SendAnnouncement;
use App\Enums\AccountStatus;
use App\Models\Announcement;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Comunicados / avisos varios a clientes. Ver: finance.view · crear y enviar: finance.manage. */
class AnnouncementController extends Controller
{
    private const AUDIENCES = ['todos' => 'Todos los clientes activos', 'estado' => 'Por estado de cuenta', 'clientes' => 'Clientes elegidos'];

    public function index(Request $request): Response
    {
        return Inertia::render('Announcements/Index', [
            'announcements' => Announcement::withCount([
                'recipients', 'recipients as sent_count' => fn ($q) => $q->where('status', 'enviado'),
            ])->with('creator:id,name')->latest('id')->paginate(10)->through(fn (Announcement $a) => [
                'id' => $a->id,
                'subject' => $a->subject,
                'status' => $a->status,
                'audience' => $this->audienceLabel($a),
                'recipients' => $a->recipients_count,
                'sent_count' => $a->sent_count,
                'sent_at' => $a->sent_at?->toIso8601String(),
                'created_at' => $a->created_at->toIso8601String(),
                'creator' => $a->creator?->name,
            ]),
            'audiences' => self::AUDIENCES,
            'statusOptions' => AccountStatus::options(),
            'clients' => Client::where('is_active', true)->orderBy('business_name')->get(['id', 'business_name', 'trade_name', 'email', 'account_status'])
                ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->displayName(), 'email' => $c->email, 'status' => $c->account_status->value]),
            'can' => ['manage' => $request->user()->can('finance.manage')],
        ]);
    }

    public function store(Request $request, SendAnnouncement $sender): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', Rule::in(array_keys(self::AUDIENCES))],
            'status_filter' => ['required_if:audience,estado', 'nullable', Rule::enum(AccountStatus::class)],
            'client_ids' => ['required_if:audience,clientes', 'nullable', 'array', 'max:500'],
            'client_ids.*' => ['integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'send' => ['sometimes', 'boolean'],
        ]);

        $announcement = Announcement::create([
            'subject' => $data['subject'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'audience_value' => match ($data['audience']) {
                'estado' => ['status' => $data['status_filter']],
                'clientes' => ['client_ids' => array_map('intval', $data['client_ids'])],
                default => null,
            },
            'status' => 'borrador',
            'created_by' => $request->user()->id,
        ]);

        if (! ($data['send'] ?? false)) {
            return back()->with('success', 'Comunicado guardado como borrador.');
        }

        $result = $sender->handle($announcement, $request->user());

        return back()->with('success', $this->sentMessage($result));
    }

    public function show(Announcement $announcement): Response
    {
        $announcement->load('recipients.client', 'creator');

        return Inertia::render('Announcements/Show', [
            'announcement' => [
                'id' => $announcement->id,
                'subject' => $announcement->subject,
                'body' => $announcement->body,
                'status' => $announcement->status,
                'audience' => $this->audienceLabel($announcement),
                'sent_at' => $announcement->sent_at?->toIso8601String(),
                'creator' => $announcement->creator?->name,
            ],
            'recipients' => $announcement->recipients->map(fn ($r) => [
                'id' => $r->id,
                'client_id' => $r->client_id,
                'client' => $r->client->displayName(),
                'email' => $r->email,
                'status' => $r->status,
            ])->sortBy('client')->values(),
            'can' => ['manage' => request()->user()->can('finance.manage')],
        ]);
    }

    public function send(Request $request, Announcement $announcement, SendAnnouncement $sender): RedirectResponse
    {
        $result = $sender->handle($announcement, $request->user());

        return back()->with('success', $this->sentMessage($result));
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        abort_if($announcement->isSent(), 422, 'Un comunicado enviado queda como registro y no se elimina.');

        $announcement->delete();

        return redirect()->route('announcements.index')->with('success', 'Borrador eliminado.');
    }

    private function audienceLabel(Announcement $a): string
    {
        return match ($a->audience) {
            'estado' => 'Clientes en estado “'.(AccountStatus::tryFrom($a->audience_value['status'] ?? '')?->label() ?? '—').'”',
            'clientes' => count($a->audience_value['client_ids'] ?? []).' clientes elegidos',
            default => self::AUDIENCES['todos'],
        };
    }

    /** @param  array{sent: int, without_email: int}  $r */
    private function sentMessage(array $r): string
    {
        return "Comunicado enviado a {$r['sent']} ".($r['sent'] === 1 ? 'cliente' : 'clientes')
            .($r['without_email'] > 0 ? " ({$r['without_email']} sin email cargado)" : '').'.';
    }
}
