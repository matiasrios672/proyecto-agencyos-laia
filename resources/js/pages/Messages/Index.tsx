import { router } from '@inertiajs/react';
import axios from 'axios';
import { Building2, Inbox, Loader2, MessageSquarePlus, RefreshCw, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { ConversationView } from '@/components/messages/ConversationView';
import type { ChatUser, ConversationDto } from '@/components/messages/types';
import { apiError, useConversations } from '@/components/messages/useMessaging';
import { Button } from '@/components/ui/button';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout';
import { timeAgo } from '@/lib/theme';
import { cn } from '@/lib/utils';

interface Props {
    selected: number | null;
    me: { id: number; name: string };
    teammates: ChatUser[];
}

const fold = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

export default function Index({ selected, teammates }: Props) {
    const [current, setCurrent] = useState<number | null>(selected);
    const [filter, setFilter] = useState('');
    const [newChat, setNewChat] = useState(false);
    const { conversations, loading, error, refresh } = useConversations();

    const select = (id: number | null) => {
        setCurrent(id);
        // La URL refleja la conversación abierta (se puede compartir o recargar) sin recargar la página.
        router.get('/mensajes', id ? { c: id } : {}, { preserveState: true, preserveScroll: true, replace: true, only: ['selected'] });
    };

    const visible = useMemo(
        () => conversations.filter((c) => filter === '' || fold(c.title).includes(fold(filter))),
        [conversations, filter],
    );

    return (
        <AppLayout title="Mensajes">
            <div className="flex h-[calc(100dvh-8.5rem)] min-h-[30rem] overflow-hidden rounded-xl border bg-card">
                {/* Lista (en móvil se oculta cuando hay una conversación abierta) */}
                <aside className={cn('flex min-h-0 w-full flex-col md:w-80 md:shrink-0 md:border-r', current !== null && 'hidden md:flex')} aria-label="Conversaciones">
                    <div className="flex items-center justify-between gap-2 border-b px-4 py-3">
                        <h2 className="font-display text-base font-bold">Mensajes</h2>
                        <Button size="sm" onClick={() => setNewChat(true)}>
                            <MessageSquarePlus /> Nuevo chat
                        </Button>
                    </div>
                    <div className="border-b p-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input className="pl-9" placeholder="Buscar conversación…" aria-label="Buscar conversación" value={filter} onChange={(e) => setFilter(e.target.value)} />
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {loading && (
                            <ul className="flex flex-col gap-1 p-2" aria-busy="true" aria-label="Cargando conversaciones">
                                {[0, 1, 2, 3].map((i) => (
                                    <li key={i} className="h-16 animate-pulse rounded-lg bg-muted" />
                                ))}
                            </ul>
                        )}
                        {error && (
                            <div role="alert" className="flex flex-col items-center gap-3 p-6 text-center">
                                <p className="text-sm text-danger">{error}</p>
                                <Button variant="outline" size="sm" onClick={() => void refresh()}>
                                    <RefreshCw /> Reintentar
                                </Button>
                            </div>
                        )}
                        {!loading && !error && visible.length === 0 && (
                            <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                                <span className="grid size-11 place-items-center rounded-full bg-muted text-muted-foreground">
                                    <Inbox className="size-5" />
                                </span>
                                <p className="text-sm font-medium">{filter ? 'Sin resultados' : 'Todavía no hay conversaciones'}</p>
                                <p className="text-sm text-muted-foreground">
                                    {filter ? 'Probá con otro nombre.' : 'Empezá un chat con alguien del equipo o escribí en el hilo de un cliente.'}
                                </p>
                            </div>
                        )}
                        <ul className="flex flex-col gap-0.5 p-2">
                            {visible.map((c) => (
                                <li key={c.id}>
                                    <ConversationRow conversation={c} active={current === c.id} onSelect={() => select(c.id)} />
                                </li>
                            ))}
                        </ul>
                    </div>
                </aside>

                {/* Conversación */}
                <div className={cn('min-w-0 flex-1', current === null && 'hidden md:flex')}>
                    {current !== null ? (
                        <ConversationView key={current} conversationId={current} onRead={() => void refresh()} onBack={() => select(null)} className="h-full w-full" />
                    ) : (
                        <div className="m-auto flex flex-col items-center gap-2 px-6 text-center">
                            <span className="grid size-12 place-items-center rounded-full bg-accent text-accent-foreground">
                                <Inbox className="size-5" />
                            </span>
                            <p className="font-medium">Elegí una conversación</p>
                            <p className="text-sm text-muted-foreground">O empezá un chat nuevo con alguien del equipo.</p>
                        </div>
                    )}
                </div>
            </div>

            <NewChatDialog
                open={newChat}
                teammates={teammates}
                onClose={() => setNewChat(false)}
                onStarted={async (id) => {
                    setNewChat(false);
                    await refresh();
                    select(id);
                }}
            />
        </AppLayout>
    );
}

function ConversationRow({ conversation: c, active, onSelect }: { conversation: ConversationDto; active: boolean; onSelect: () => void }) {
    return (
        <button
            onClick={onSelect}
            aria-current={active ? 'true' : undefined}
            className={cn('flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', active && 'bg-accent hover:bg-accent')}
        >
            {c.type === 'thread' ? (
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-accent text-accent-foreground">
                    <Building2 className="size-4" />
                </span>
            ) : (
                <Avatar name={c.title} className="size-10 shrink-0" />
            )}
            <span className="min-w-0 flex-1">
                <span className="flex items-baseline justify-between gap-2">
                    <span className={cn('truncate text-sm', c.unread > 0 ? 'font-bold' : 'font-medium')}>{c.title}</span>
                    {c.last_message_at && <time className="shrink-0 text-[11px] text-muted-foreground">{timeAgo(c.last_message_at)}</time>}
                </span>
                <span className="flex items-center justify-between gap-2">
                    <span className={cn('truncate text-xs', c.unread > 0 ? 'text-foreground' : 'text-muted-foreground')}>
                        {c.last ? `${c.last.mine ? 'Vos: ' : c.type === 'thread' && c.last.author ? `${c.last.author.split(' ')[0]}: ` : ''}${c.last.preview}` : 'Sin mensajes todavía'}
                    </span>
                    {c.unread > 0 && (
                        <span className="grid min-w-5 shrink-0 place-items-center rounded-full bg-peach px-1.5 text-[11px] font-bold leading-5 text-navy" aria-label={`${c.unread} sin leer`}>
                            {c.unread > 9 ? '9+' : c.unread}
                        </span>
                    )}
                </span>
            </span>
        </button>
    );
}

function NewChatDialog({ open, teammates, onClose, onStarted }: { open: boolean; teammates: ChatUser[]; onClose: () => void; onStarted: (id: number) => void | Promise<void> }) {
    const [q, setQ] = useState('');
    const [busy, setBusy] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const list = teammates.filter((t) => q === '' || fold(t.name).includes(fold(q)));

    const start = async (user: ChatUser) => {
        setBusy(user.id);
        setError(null);
        try {
            const { data } = await axios.post<{ conversation: { id: number } }>('/mensajes/api/directa', { user_id: user.id });
            await onStarted(data.conversation.id);
        } catch (e) {
            setError(apiError(e, 'No se pudo abrir el chat.'));
        } finally {
            setBusy(null);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Nuevo chat</DialogTitle>
                    <DialogDescription>Elegí con quién del equipo querés hablar. Si ya tienen un chat, se abre ese.</DialogDescription>
                </DialogHeader>
                <DialogBody>
                    <Input placeholder="Buscar por nombre…" aria-label="Buscar compañero" autoFocus value={q} onChange={(e) => setQ(e.target.value)} />
                    {error && (
                        <p role="alert" className="text-sm text-danger">
                            {error}
                        </p>
                    )}
                    <ul className="max-h-72 overflow-y-auto rounded-lg border">
                        {list.length === 0 && <li className="p-6 text-center text-sm text-muted-foreground">No hay nadie con ese nombre.</li>}
                        {list.map((t) => (
                            <li key={t.id} className="border-b last:border-0">
                                <button
                                    onClick={() => void start(t)}
                                    disabled={busy !== null}
                                    className="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring disabled:opacity-60"
                                >
                                    <Avatar name={t.name} className="size-9" />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">{t.name}</span>
                                        {t.job_title && <span className="block truncate text-xs text-muted-foreground">{t.job_title}</span>}
                                    </span>
                                    {busy === t.id && <Loader2 className="size-4 animate-spin" />}
                                </button>
                            </li>
                        ))}
                    </ul>
                </DialogBody>
            </DialogContent>
        </Dialog>
    );
}
