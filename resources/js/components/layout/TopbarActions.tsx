import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Bell, BellOff, Building2, CheckCheck, MessageSquare, Monitor, Moon, Power, Sun, User as UserIcon, Wallet, type LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Avatar } from '@/components/avatar';
import { useConversations } from '@/components/messages/useMessaging';
import { NOTIFICATION_EVENT, useEchoChannel, useRealtimeStatus } from '@/lib/echo';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverClose, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { timeAgo, type Theme } from '@/lib/theme';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

const iconBtn =
    'relative grid size-9 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-muted data-[state=open]:text-foreground';

/* ───────────────────────────── Chat ───────────────────────────── */

export function ChatMenu() {
    const { auth } = usePage<SharedProps>().props;
    const allowed = auth!.user.is_sysadmin || auth!.permissions.includes('messages.use');
    const [open, setOpen] = useState(false);
    // Contador y lista en vivo (pista `inbox.updated` por Reverb; respaldo por consulta periódica).
    const { conversations, unread, loading, error } = useConversations(allowed);

    if (!allowed) return null;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger className={iconBtn} aria-label={unread > 0 ? `Mensajes, ${unread} sin leer` : 'Mensajes'}>
                <MessageSquare className="size-[18px]" />
                {unread > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 grid min-w-[18px] place-items-center rounded-full bg-peach px-1 text-[10px] font-bold leading-[18px] text-navy ring-2 ring-background">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </PopoverTrigger>
            <PopoverContent className="w-[min(24rem,calc(100vw-1.5rem))]">
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <p className="font-display text-sm font-semibold">Mensajes</p>
                    <Link href="/mensajes" onClick={() => setOpen(false)} className="text-xs font-medium text-primary hover:underline">
                        Abrir todos
                    </Link>
                </div>
                {loading ? (
                    <ul className="flex flex-col gap-1 p-2" aria-busy="true" aria-label="Cargando mensajes">
                        {[0, 1, 2].map((i) => (
                            <li key={i} className="h-14 animate-pulse rounded-lg bg-muted" />
                        ))}
                    </ul>
                ) : error ? (
                    <p role="alert" className="px-6 py-8 text-center text-sm text-danger">
                        {error}
                    </p>
                ) : conversations.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
                        <div className="grid size-12 place-items-center rounded-full bg-accent text-accent-foreground">
                            <MessageSquare className="size-5" />
                        </div>
                        <div>
                            <p className="text-sm font-medium">Todavía no hay conversaciones</p>
                            <p className="mt-1 text-sm text-muted-foreground">Escribile a alguien del equipo o dejá una nota en el hilo de un cliente.</p>
                        </div>
                        <Link href="/mensajes" onClick={() => setOpen(false)} className="text-sm font-medium text-primary hover:underline">
                            Iniciar un chat
                        </Link>
                    </div>
                ) : (
                    <ul className="max-h-[22rem] divide-y overflow-y-auto">
                        {conversations.slice(0, 6).map((c) => (
                            <li key={c.id}>
                                <button
                                    onClick={() => {
                                        setOpen(false);
                                        router.visit(`/mensajes?c=${c.id}`);
                                    }}
                                    className={cn('flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted', c.unread > 0 && 'bg-accent/50')}
                                >
                                    {c.type === 'thread' ? (
                                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-accent text-accent-foreground">
                                            <Building2 className="size-4" />
                                        </span>
                                    ) : (
                                        <Avatar name={c.title} className="size-9 shrink-0" />
                                    )}
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-baseline justify-between gap-2">
                                            <span className={cn('truncate text-sm', c.unread > 0 ? 'font-bold' : 'font-medium')}>{c.title}</span>
                                            {c.last_message_at && <time className="shrink-0 text-[11px] text-muted-foreground">{timeAgo(c.last_message_at)}</time>}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">{c.last ? `${c.last.mine ? 'Vos: ' : ''}${c.last.preview}` : 'Sin mensajes todavía'}</span>
                                    </span>
                                    {c.unread > 0 && (
                                        <span className="grid min-w-5 shrink-0 place-items-center rounded-full bg-peach px-1.5 text-[11px] font-bold leading-5 text-navy" aria-label={`${c.unread} sin leer`}>
                                            {c.unread > 9 ? '9+' : c.unread}
                                        </span>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </PopoverContent>
        </Popover>
    );
}

/* ───────────────────────── Notificaciones ───────────────────────── */

interface NotificationItem {
    id: string;
    icon: string;
    title: string;
    body: string;
    url: string | null;
    read: boolean;
    at: string;
}

const notificationIcons: Record<string, LucideIcon> = { building: Building2, bell: Bell, wallet: Wallet, message: MessageSquare };
/** Con WebSocket activo la consulta es solo una red de seguridad; sin él, es el mecanismo principal. */
const POLL_MS = 30_000;
const POLL_MS_REALTIME = 120_000;

interface RealtimeNotification {
    id: string;
    icon?: string;
    title?: string;
    body?: string;
    url?: string | null;
}

export function NotificationsMenu() {
    const props = usePage<SharedProps>().props;
    const me = props.auth!.user.id;
    const status = useRealtimeStatus();
    const [items, setItems] = useState<NotificationItem[]>([]);
    // El servidor entrega el contador al renderizar; el tiempo real y la consulta periódica lo mantienen al día.
    const [unread, setUnread] = useState(props.unread_notifications ?? 0);
    const [open, setOpen] = useState(false);
    // Ids ya vistos (consulta o tiempo real): evita avisos emergentes duplicados.
    const seen = useRef<Set<string>>(new Set());
    const firstLoad = useRef(true);

    const announce = useCallback((n: { id: string; title: string; body: string; url: string | null }) => {
        if (seen.current.has(n.id)) return;
        seen.current.add(n.id);
        toast(n.title, {
            description: n.body,
            icon: <Bell className="size-4 text-primary" />,
            action: n.url ? { label: 'Ver', onClick: () => router.visit(n.url!) } : undefined,
            duration: 8000,
        });
    }, []);

    const load = useCallback(async () => {
        try {
            const { data } = await axios.get<{ unread: number; items: NotificationItem[] }>('/notificaciones');
            if (firstLoad.current) {
                // La primera carga solo registra lo que ya existía: no se avisa de lo viejo.
                data.items.forEach((i) => seen.current.add(i.id));
                firstLoad.current = false;
            } else {
                data.items.filter((i) => !i.read).reverse().forEach(announce);
            }
            setItems(data.items);
            setUnread(data.unread);
        } catch {
            /* sin red: se reintenta en el próximo ciclo */
        }
    }, [announce]);

    // Tiempo real: la notificación aparece al instante en la lista, suma al contador y muestra el aviso emergente.
    useEchoChannel(`App.Models.User.${me}`, {
        [NOTIFICATION_EVENT]: (payload) => {
            const n = payload as RealtimeNotification;
            if (!n.id) return;
            const isNew = !seen.current.has(n.id);
            announce({ id: n.id, title: n.title ?? 'Notificación', body: n.body ?? '', url: n.url ?? null });
            if (!isNew) return;
            setItems((list) => [{ id: n.id, icon: n.icon ?? 'bell', title: n.title ?? 'Notificación', body: n.body ?? '', url: n.url ?? null, read: false, at: new Date().toISOString() }, ...list.filter((i) => i.id !== n.id)].slice(0, 15));
            setUnread((u) => u + 1);
        },
    });

    useEffect(() => {
        void load();
        const every = status === 'connected' ? POLL_MS_REALTIME : POLL_MS;
        const timer = setInterval(() => document.visibilityState === 'visible' && void load(), every);
        const onFocus = () => void load();
        window.addEventListener('focus', onFocus);
        return () => {
            clearInterval(timer);
            window.removeEventListener('focus', onFocus);
        };
    }, [load, status]);

    const markAll = async () => {
        setItems((l) => l.map((i) => ({ ...i, read: true })));
        setUnread(0);
        await axios.post('/notificaciones/leer').catch(() => void load());
    };

    const openItem = async (item: NotificationItem) => {
        setOpen(false);
        if (!item.read) {
            setItems((l) => l.map((i) => (i.id === item.id ? { ...i, read: true } : i)));
            setUnread((u) => Math.max(0, u - 1));
            await axios.post(`/notificaciones/${item.id}/leer`).catch(() => undefined);
        }
        if (item.url) router.visit(item.url);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger className={iconBtn} aria-label={unread > 0 ? `Notificaciones, ${unread} sin leer` : 'Notificaciones'}>
                <Bell className="size-[18px]" />
                {unread > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 grid min-w-[18px] place-items-center rounded-full bg-peach px-1 text-[10px] font-bold leading-[18px] text-navy ring-2 ring-background">
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </PopoverTrigger>
            <PopoverContent>
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <p className="font-display text-sm font-semibold">Notificaciones</p>
                    {unread > 0 && (
                        <button onClick={markAll} className="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline">
                            <CheckCheck className="size-3.5" /> Marcar todas como leídas
                        </button>
                    )}
                </div>
                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 py-10 text-center">
                        <BellOff className="size-6 text-muted-foreground" />
                        <p className="text-sm font-medium">Estás al día</p>
                        <p className="text-sm text-muted-foreground">Acá vas a ver asignaciones, menciones y avisos.</p>
                    </div>
                ) : (
                    <ul className="max-h-[22rem] divide-y overflow-y-auto">
                        {items.map((n) => {
                            const Icon = notificationIcons[n.icon] ?? Bell;
                            return (
                                <li key={n.id}>
                                    <button
                                        onClick={() => openItem(n)}
                                        className={cn('flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted', !n.read && 'bg-accent/50')}
                                    >
                                        <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-accent text-accent-foreground">
                                            <Icon className="size-4" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-2">
                                                <span className="text-sm font-medium">{n.title}</span>
                                                {!n.read && <span className="size-2 rounded-full bg-peach" aria-label="Sin leer" />}
                                            </span>
                                            <span className="block text-sm text-muted-foreground">{n.body}</span>
                                            <span className="mt-0.5 block text-xs text-muted-foreground">{timeAgo(n.at)}</span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <PopoverClose className="sr-only">Cerrar</PopoverClose>
            </PopoverContent>
        </Popover>
    );
}

/* ───────────────────────────── Perfil ───────────────────────────── */

export function ProfileMenu({ theme, onTheme }: { theme: Theme; onTheme: (t: Theme) => void }) {
    const user = usePage<SharedProps>().props.auth!.user;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className="flex items-center gap-2.5 rounded-lg py-1 pl-1 pr-2.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-muted"
                aria-label="Menú de perfil"
            >
                <Avatar name={user.name} className="size-8" />
                <span className="hidden text-left leading-tight md:block">
                    <span className="block max-w-36 truncate text-sm font-medium">{user.name}</span>
                    <span className="block text-xs text-muted-foreground">{user.role_label}</span>
                </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <div className="flex items-center gap-3 px-2.5 py-2.5">
                    <Avatar name={user.name} className="size-10 text-sm" />
                    <div className="min-w-0 leading-tight">
                        <p className="truncate text-sm font-semibold">{user.name}</p>
                        <p className="truncate text-xs text-muted-foreground">{user.email}</p>
                    </div>
                </div>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href="/perfil">
                        <UserIcon /> Mi perfil
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuLabel>Apariencia</DropdownMenuLabel>
                <DropdownMenuRadioGroup value={theme} onValueChange={(v) => onTheme(v as Theme)}>
                    <DropdownMenuRadioItem value="light">
                        <Sun /> Claro
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="dark">
                        <Moon /> Oscuro
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="system">
                        <Monitor /> Automático
                    </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.post('/logout')} className="text-danger data-[highlighted]:bg-danger/10 [&_svg]:text-danger">
                    <Power /> Cerrar sesión
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/* ───────────────────────────── Apagar ───────────────────────────── */

export function PowerButton() {
    return (
        <Button
            variant="ghost"
            size="icon"
            className="size-9 rounded-lg text-muted-foreground hover:bg-danger/10 hover:text-danger"
            aria-label="Cerrar sesión"
            title="Cerrar sesión"
            onClick={() => router.post('/logout')}
        >
            <Power className="size-[18px]" />
        </Button>
    );
}
