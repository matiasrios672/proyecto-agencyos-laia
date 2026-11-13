import { usePage } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, Building2, Loader2, MessageSquareText, Pencil, RefreshCw, Trash2, WifiOff } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { useRealtimeStatus } from '@/lib/echo';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { Composer } from './Composer';
import { MessageText } from './MessageText';
import type { MessageDto } from './types';
import { apiError, useConversation } from './useMessaging';

interface Props {
    conversationId: number;
    /** Se llama cuando el servidor registró la lectura (para refrescar contadores de la bandeja). */
    onRead?: () => void;
    /** En móvil, volver a la lista. */
    onBack?: () => void;
    /** Dentro de otra pantalla (p. ej. ficha del cliente): sin encabezado grande. */
    embedded?: boolean;
    className?: string;
}

const time = (iso: string) => new Date(iso).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
const dayKey = (iso: string) => new Date(iso).toDateString();

function dayLabel(iso: string): string {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return 'Hoy';
    if (d.toDateString() === yesterday.toDateString()) return 'Ayer';
    return d.toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' });
}

export function ConversationView({ conversationId, onRead, onBack, embedded, className }: Props) {
    const me = usePage<SharedProps>().props.auth!.user.id;
    const status = useRealtimeStatus();
    const c = useConversation(conversationId, onRead);
    const [editing, setEditing] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<MessageDto | null>(null);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const [newBelow, setNewBelow] = useState(false);
    const scroller = useRef<HTMLDivElement>(null);
    const stickToBottom = useRef(true);
    const prevCount = useRef(0);
    const prevFirstId = useRef<number | null>(null);
    const prevHeight = useRef(0);

    const scrollToBottom = useCallback((smooth = false) => {
        const el = scroller.current;
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        setNewBelow(false);
    }, []);

    // Posición del scroll: al cargar → abajo; mensaje nuevo → abajo si ya estaba abajo (si no, aviso); anteriores → sin saltos.
    useLayoutEffect(() => {
        const el = scroller.current;
        if (!el || c.loading) return;
        const firstId = c.messages[0]?.id ?? null;
        const prepended = prevFirstId.current !== null && firstId !== null && firstId < prevFirstId.current;

        if (prepended) {
            el.scrollTop += el.scrollHeight - prevHeight.current;
        } else if (prevCount.current === 0) {
            scrollToBottom();
        } else if (c.messages.length > prevCount.current) {
            const last = c.messages[c.messages.length - 1];
            if (stickToBottom.current || last.user.id === me) scrollToBottom(true);
            else setNewBelow(true);
        }
        prevCount.current = c.messages.length;
        prevFirstId.current = firstId;
        prevHeight.current = el.scrollHeight;
    }, [c.messages, c.loading, me, scrollToBottom]);

    useEffect(() => {
        prevCount.current = 0;
        prevFirstId.current = null;
        stickToBottom.current = true;
        setEditing(null);
        setNewBelow(false);
    }, [conversationId]);

    // Marcar como leído cuando el último mensaje es de otra persona y la pestaña está a la vista.
    const lastMessage = c.messages[c.messages.length - 1];
    useEffect(() => {
        if (!c.loading && c.conversation && (c.conversation.unread > 0 || (lastMessage && lastMessage.user.id !== me))) c.markRead();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [c.loading, lastMessage?.id, c.conversation?.unread]);

    const { markRead } = c;
    useEffect(() => {
        const onVisible = () => document.visibilityState === 'visible' && markRead();
        document.addEventListener('visibilitychange', onVisible);
        return () => document.removeEventListener('visibilitychange', onVisible);
    }, [markRead]);

    const onScroll = () => {
        const el = scroller.current;
        if (!el) return;
        stickToBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
        if (stickToBottom.current) setNewBelow(false);
    };

    const confirmDelete = async () => {
        if (!deleting) return;
        try {
            await c.remove(deleting.id);
            setDeleting(null);
            setDeleteError(null);
        } catch (e) {
            setDeleteError(apiError(e, 'No se pudo eliminar el mensaje.'));
        }
    };

    const conversation = c.conversation;

    return (
        <section className={cn('flex min-h-0 flex-col', className)} aria-label={conversation ? `Conversación: ${conversation.title}` : 'Conversación'}>
            {!embedded && (
                <header className="flex items-center gap-3 border-b px-4 py-3">
                    {onBack && (
                        <Button variant="ghost" size="icon" className="-ml-2 md:hidden" aria-label="Volver a la lista" onClick={onBack}>
                            <ArrowLeft />
                        </Button>
                    )}
                    {conversation && (
                        <>
                            {conversation.type === 'thread' ? (
                                <span className="grid size-9 place-items-center rounded-full bg-accent text-accent-foreground">
                                    <Building2 className="size-4" />
                                </span>
                            ) : (
                                <Avatar name={conversation.title} className="size-9" />
                            )}
                            <div className="min-w-0">
                                <h2 className="truncate font-display text-sm font-bold">{conversation.title}</h2>
                                <p className="truncate text-xs text-muted-foreground">
                                    {conversation.type === 'thread' ? (
                                        <>
                                            Visible para el equipo de este cliente ·{' '}
                                            <a className="text-primary hover:underline" href={conversation.subject?.url}>
                                                Ver ficha
                                            </a>
                                        </>
                                    ) : (
                                        (conversation.other?.job_title ?? 'Chat directo')
                                    )}
                                </p>
                            </div>
                        </>
                    )}
                </header>
            )}

            {status === 'unavailable' && (
                <p role="status" className="flex items-center gap-2 border-b bg-warning/10 px-4 py-1.5 text-xs text-warning">
                    <WifiOff className="size-3.5" /> Sin conexión en tiempo real: los mensajes nuevos se actualizan cada 10 segundos.
                </p>
            )}

            <div className="relative min-h-0 flex-1">
                <div ref={scroller} onScroll={onScroll} role="log" aria-live="polite" aria-relevant="additions" aria-label="Mensajes" className="h-full overflow-y-auto px-4 py-4">
                    {c.loading && (
                        <div className="flex flex-col gap-4" aria-busy="true" aria-label="Cargando mensajes">
                            {[0, 1, 2].map((i) => (
                                <div key={i} className={cn('h-12 animate-pulse rounded-2xl bg-muted', i % 2 ? 'ml-auto w-2/5' : 'w-3/5')} />
                            ))}
                        </div>
                    )}

                    {c.error && (
                        <div role="alert" className="flex flex-col items-center gap-3 py-10 text-center">
                            <p className="text-sm text-danger">{c.error}</p>
                            <Button variant="outline" size="sm" onClick={() => void c.reload()}>
                                <RefreshCw /> Reintentar
                            </Button>
                        </div>
                    )}

                    {!c.loading && !c.error && (
                        <>
                            {c.hasMore && (
                                <div className="mb-3 text-center">
                                    <Button variant="ghost" size="sm" disabled={c.loadingOlder} onClick={() => void c.loadOlder()}>
                                        {c.loadingOlder && <Loader2 className="animate-spin" />} Cargar mensajes anteriores
                                    </Button>
                                </div>
                            )}

                            {c.messages.length === 0 && (
                                <div className="flex flex-col items-center gap-2 py-12 text-center">
                                    <span className="grid size-11 place-items-center rounded-full bg-muted text-muted-foreground">
                                        <MessageSquareText className="size-5" />
                                    </span>
                                    <p className="text-sm font-medium">Todavía no hay mensajes</p>
                                    <p className="text-sm text-muted-foreground">Escribí el primero. Con @ podés mencionar a alguien del equipo.</p>
                                </div>
                            )}

                            <ul className="flex flex-col gap-1">
                                {c.messages.map((m, i) => {
                                    const prev = c.messages[i - 1];
                                    const newDay = !prev || dayKey(prev.created_at) !== dayKey(m.created_at);
                                    const mine = m.user.id === me;
                                    const grouped = !newDay && prev?.user.id === m.user.id && !prev.deleted;
                                    return (
                                        <li key={m.id} className={cn(grouped ? 'mt-0' : 'mt-3')}>
                                            {newDay && (
                                                <p className="my-3 text-center text-xs font-medium capitalize text-muted-foreground">{dayLabel(m.created_at)}</p>
                                            )}
                                            <div className={cn('group flex items-end gap-2', mine && 'flex-row-reverse')}>
                                                {!mine && (grouped ? <span className="size-7 shrink-0" /> : <Avatar name={m.user.name} className="size-7 shrink-0 text-[10px]" />)}
                                                <div className={cn('flex max-w-[85%] flex-col sm:max-w-[75%]', mine ? 'items-end' : 'items-start')}>
                                                    {!mine && !grouped && <span className="mb-0.5 px-1 text-xs font-medium text-muted-foreground">{m.user.name}</span>}

                                                    {editing === m.id && m.body !== null ? (
                                                        <div className="w-[min(32rem,80vw)] rounded-xl border bg-card p-3">
                                                            <Composer
                                                                mentionables={c.mentionables}
                                                                initialValue={m.body}
                                                                initialMentions={m.mentions}
                                                                autoFocus
                                                                submitLabel="Guardar"
                                                                onCancel={() => setEditing(null)}
                                                                onSubmit={async (body, mentions) => {
                                                                    await c.edit(m.id, body, mentions);
                                                                    setEditing(null);
                                                                }}
                                                            />
                                                        </div>
                                                    ) : m.deleted ? (
                                                        <p className="rounded-2xl border border-dashed px-3 py-2 text-sm italic text-muted-foreground">Mensaje eliminado</p>
                                                    ) : (
                                                        <div className={cn('flex items-center gap-1', mine && 'flex-row-reverse')}>
                                                            <p
                                                                className={cn(
                                                                    'whitespace-pre-wrap break-words rounded-2xl px-3.5 py-2 text-sm',
                                                                    mine ? 'rounded-br-md bg-primary text-primary-foreground' : 'rounded-bl-md bg-muted',
                                                                )}
                                                            >
                                                                <MessageText body={m.body ?? ''} mentions={m.mentions} mine={mine} />
                                                            </p>
                                                            {mine && (
                                                                <span className="flex gap-0.5 opacity-100 transition-opacity sm:opacity-0 sm:focus-within:opacity-100 sm:group-hover:opacity-100">
                                                                    <Button variant="ghost" size="icon" className="size-7" aria-label="Editar mensaje" onClick={() => setEditing(m.id)}>
                                                                        <Pencil className="size-3.5" />
                                                                    </Button>
                                                                    <Button variant="ghost" size="icon" className="size-7" aria-label="Eliminar mensaje" onClick={() => { setDeleteError(null); setDeleting(m); }}>
                                                                        <Trash2 className="size-3.5" />
                                                                    </Button>
                                                                </span>
                                                            )}
                                                        </div>
                                                    )}
                                                    <span className="mt-0.5 px-1 text-[11px] text-muted-foreground">
                                                        {time(m.created_at)}
                                                        {m.edited && ' · editado'}
                                                    </span>
                                                </div>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </>
                    )}
                </div>

                {newBelow && (
                    <Button size="sm" className="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full shadow-lg" onClick={() => scrollToBottom(true)}>
                        <ArrowDown /> Mensajes nuevos
                    </Button>
                )}
            </div>

            <div className="border-t p-3">
                {conversation && (
                    <Composer
                        key={conversationId}
                        mentionables={c.mentionables}
                        onSubmit={c.send}
                        placeholder={conversation.type === 'thread' ? 'Escribí una nota para el equipo del cliente…' : `Escribile a ${conversation.other?.name ?? 'tu compañero'}…`}
                        disabled={conversation.other?.active === false}
                    />
                )}
                {!conversation && !c.error && <div className="h-10" />}
            </div>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="¿Eliminar este mensaje?"
                description={deleteError ?? 'Va a verse como “Mensaje eliminado” para todos. No se puede deshacer.'}
                confirmLabel="Eliminar"
                destructive
                onConfirm={() => void confirmDelete()}
            />
        </section>
    );
}
