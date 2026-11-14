import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Clock, LayoutGrid, List, Mail, MessageCircle, Phone, Plus, Search, Trash2, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { EmptyState } from '@/components/empty-state';
import { AccountStatusBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { ClientRow, Options, Paginated, SharedProps } from '@/types';

interface Props {
    clients: Paginated<ClientRow>;
    filters: { q: string; status: string };
    statusOptions: Options;
    stats: { total: number; al_dia: number; por_vencer: number; deuda: number };
    can: { create: boolean };
}

type View = 'cards' | 'list';
const VIEW_KEY = 'clients.view';

function readView(): View {
    try { return window.localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'cards'; } catch { return 'cards'; }
}

/** Color del borde superior de cada tarjeta según el estado de cuenta: se lee de un vistazo. */
const accent: Record<string, string> = { al_dia: 'bg-success', por_vencer: 'bg-warning', deuda: 'bg-danger' };

/** Acciones rápidas de contacto: no abren la ficha, van directo al correo / teléfono / WhatsApp. */
function QuickContact({ c }: { c: ClientRow }) {
    const wa = (c.whatsapp ?? '').replace(/\D/g, '');
    const items = [
        c.email && { href: `mailto:${c.email}`, label: `Escribir a ${c.name}`, Icon: Mail },
        c.phone && { href: `tel:${c.phone}`, label: `Llamar a ${c.name}`, Icon: Phone },
        wa && { href: `https://wa.me/${wa}`, label: `WhatsApp de ${c.name}`, Icon: MessageCircle, external: true },
    ].filter(Boolean) as { href: string; label: string; Icon: typeof Mail; external?: boolean }[];

    return (
        <div className="flex gap-1">
            {items.map(({ href, label, Icon, external }) => (
                <a
                    key={href}
                    href={href}
                    aria-label={label}
                    title={label}
                    {...(external ? { target: '_blank', rel: 'noreferrer noopener' } : {})}
                    className="grid size-8 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Icon className="size-4" />
                </a>
            ))}
        </div>
    );
}

function TeamStack({ names, total }: { names: string[]; total: number }) {
    if (total === 0) return <span className="text-xs text-muted-foreground">Sin equipo asignado</span>;
    return (
        <div className="flex items-center" title={names.join(', ')}>
            <div className="flex -space-x-2">
                {names.map((n) => <Avatar key={n} name={n} className="size-7 text-[10px] ring-2 ring-card" />)}
            </div>
            {total > names.length && <span className="ml-1.5 text-xs text-muted-foreground">+{total - names.length}</span>}
        </div>
    );
}

function ClientCard({ c }: { c: ClientRow }) {
    return (
        <Card className="group relative flex h-full min-w-0 flex-col overflow-hidden transition-all hover:-translate-y-0.5 hover:shadow-lg">
            <span aria-hidden className={cn('h-1 w-full', accent[c.account_status] ?? 'bg-border')} />
            <div className="flex min-w-0 flex-1 flex-col gap-4 p-5">
                <div className="flex items-start gap-3.5">
                    <Avatar name={c.name} className="size-12 text-base" />
                    <div className="min-w-0 flex-1">
                        {/* El enlace cubre toda la tarjeta; los botones de contacto quedan por encima. */}
                        <Link href={`/clientes/${c.id}`} className="line-clamp-2 font-display text-base font-bold leading-tight after:absolute after:inset-0 after:content-[''] focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-ring">
                            {c.name}
                        </Link>
                        <p className="mt-0.5 truncate text-sm text-muted-foreground">{c.name !== c.business_name ? c.business_name : (c.tax_id ?? 'Sin CUIT')}</p>
                    </div>
                    <AccountStatusBadge status={c.account_status} />
                </div>

                <dl className="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">Servicios activos</dt>
                        <dd className="mt-0.5 font-semibold tabular-nums">{c.services_count}</dd>
                    </div>
                    {c.monthly !== null && (
                        <div>
                            <dt className="text-xs text-muted-foreground">Abono mensual</dt>
                            <dd className="mt-0.5 font-semibold tabular-nums">{c.monthly.length ? c.monthly.join(' + ') : '—'}</dd>
                        </div>
                    )}
                </dl>

                {c.pending !== null && c.pending.length > 0 && (
                    <p className={cn('flex items-center gap-2 rounded-lg px-3 py-2 text-sm', (c.overdue_count ?? 0) > 0 ? 'bg-danger/10 text-danger' : 'bg-muted text-muted-foreground')}>
                        {(c.overdue_count ?? 0) > 0 ? <AlertTriangle className="size-4 shrink-0" /> : <Clock className="size-4 shrink-0" />}
                        <span className="min-w-0 truncate">
                            {(c.overdue_count ?? 0) > 0 ? 'En mora: ' : 'Por cobrar: '}
                            <span className="font-semibold tabular-nums">{c.pending.join(' + ')}</span>
                        </span>
                    </p>
                )}

                <div className="mt-auto flex items-center justify-between gap-2 border-t pt-3">
                    <TeamStack names={c.team} total={c.team_count} />
                    <div className="relative z-10">
                        <QuickContact c={c} />
                    </div>
                </div>
            </div>
            {!c.is_active && <Badge className="absolute right-3 top-4">Inactivo</Badge>}
        </Card>
    );
}

function ClientRowItem({ c }: { c: ClientRow }) {
    return (
        <li className="relative flex flex-wrap items-center gap-x-5 gap-y-2 px-4 py-3 transition-colors hover:bg-muted/50">
            <span aria-hidden className={cn('absolute inset-y-2 left-0 w-1 rounded-r-full', accent[c.account_status] ?? 'bg-border')} />
            <Avatar name={c.name} className="size-10" />
            <div className="min-w-48 flex-1">
                <Link href={`/clientes/${c.id}`} className="font-medium after:absolute after:inset-0 after:content-[''] hover:underline focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-ring">{c.name}</Link>
                <p className="text-xs text-muted-foreground">{c.name !== c.business_name ? `${c.business_name} · ` : ''}{c.tax_id ?? 'Sin CUIT'}</p>
            </div>
            <span className="hidden text-sm text-muted-foreground lg:block">{c.services_count} {c.services_count === 1 ? 'servicio' : 'servicios'}</span>
            {c.pending !== null && c.pending.length > 0 && (
                <span className={cn('hidden text-sm font-medium tabular-nums sm:block', (c.overdue_count ?? 0) > 0 ? 'text-danger' : 'text-muted-foreground')}>{c.pending.join(' + ')}</span>
            )}
            <div className="hidden md:block"><TeamStack names={c.team} total={c.team_count} /></div>
            <AccountStatusBadge status={c.account_status} />
            <div className="relative z-10"><QuickContact c={c} /></div>
        </li>
    );
}

export default function Index({ clients, filters, stats, can }: Props) {
    const [q, setQ] = useState(filters.q);
    const [view, setView] = useState<View>('cards');
    const first = useRef(true);
    // La papelera es de quien puede gestionar clientes (el servidor lo vuelve a validar).
    const { auth } = usePage<SharedProps>().props;
    const canRestore = !!auth && (auth.user.is_sysadmin || auth.permissions.includes('clients.manage'));

    useEffect(() => { setView(readView()); }, []);

    const changeView = (v: View) => {
        setView(v);
        try { window.localStorage.setItem(VIEW_KEY, v); } catch { /* sin almacenamiento: solo en memoria */ }
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => {
            router.get('/clientes', { q: q || undefined, status: filters.status || undefined }, { preserveState: true, replace: true });
        }, 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const setStatus = (status: string) => router.get('/clientes', { q: q || undefined, status: status || undefined }, { preserveState: true, replace: true });

    const filtered = q !== '' || filters.status !== '';
    const chips: { value: string; label: string; count: number; Icon?: typeof Clock; tone: string }[] = [
        { value: '', label: 'Todos', count: stats.total, tone: 'bg-navy text-white' },
        { value: 'al_dia', label: 'Al día', count: stats.al_dia, Icon: CheckCircle2, tone: 'bg-success text-white' },
        { value: 'por_vencer', label: 'Por vencer', count: stats.por_vencer, Icon: Clock, tone: 'bg-warning text-navy' },
        { value: 'deuda', label: 'Con deuda', count: stats.deuda, Icon: AlertTriangle, tone: 'bg-danger text-white' },
    ];

    return (
        <AppLayout title="Clientes">
            <div className="mb-5 flex flex-wrap items-center gap-3">
                <div className="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="pl-9" placeholder="Buscar por nombre, CUIT o email…" aria-label="Buscar clientes" value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                <div className="ml-auto flex items-center gap-2">
                    <div className="hidden rounded-lg border bg-muted p-0.5 sm:flex" role="group" aria-label="Vista">
                        {([['cards', LayoutGrid, 'Tarjetas'], ['list', List, 'Lista']] as const).map(([v, Icon, label]) => (
                            <button key={v} type="button" aria-pressed={view === v} aria-label={`Vista de ${label.toLowerCase()}`} title={label} onClick={() => changeView(v)} className={cn('grid size-8 place-items-center rounded-md text-muted-foreground transition-colors', view === v && 'bg-card text-foreground shadow-sm')}>
                                <Icon className="size-4" />
                            </button>
                        ))}
                    </div>
                    {canRestore && (
                        <Link href="/clientes-eliminados"><Button variant="ghost"><Trash2 /> Eliminados</Button></Link>
                    )}
                    {can.create && (
                        <Link href="/clientes/create"><Button><Plus /> Nuevo cliente</Button></Link>
                    )}
                </div>
            </div>

            <div className="mb-6 flex flex-wrap gap-2" role="group" aria-label="Filtrar por estado de cuenta">
                {chips.map((chip) => {
                    const on = filters.status === chip.value;
                    return (
                        <button
                            key={chip.value}
                            type="button"
                            aria-pressed={on}
                            onClick={() => setStatus(chip.value)}
                            className={cn('inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', on ? `${chip.tone} border-transparent shadow-sm` : 'bg-card text-muted-foreground hover:text-foreground')}
                        >
                            {chip.Icon && <chip.Icon className="size-3.5" />}
                            {chip.label}
                            <span className={cn('rounded-full px-1.5 text-xs tabular-nums', on ? 'bg-white/25' : 'bg-muted')}>{chip.count}</span>
                        </button>
                    );
                })}
            </div>

            {clients.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Users}
                        title={filtered ? 'Sin resultados' : 'Todavía no hay clientes'}
                        description={filtered ? 'Probá con otra búsqueda o quitá los filtros.' : can.create ? 'Cargá el primero para empezar a gestionar su bóveda, contenido y cobros.' : 'Dirección todavía no te asignó ningún cliente.'}
                        action={filtered ? <Button variant="outline" size="sm" onClick={() => { setQ(''); router.get('/clientes'); }}>Quitar filtros</Button> : undefined}
                    />
                </Card>
            ) : view === 'cards' ? (
                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {clients.data.map((c) => <li key={c.id} className="min-w-0"><ClientCard c={c} /></li>)}
                </ul>
            ) : (
                <Card className="overflow-hidden"><ul className="divide-y">{clients.data.map((c) => <ClientRowItem key={c.id} c={c} />)}</ul></Card>
            )}

            {clients.last_page > 1 && (
                <div className="mt-5 flex items-center justify-between text-sm text-muted-foreground">
                    <span>{clients.from}–{clients.to} de {clients.total}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!clients.prev_page_url} onClick={() => clients.prev_page_url && router.get(clients.prev_page_url, {}, { preserveState: true })}><ChevronLeft /> Anterior</Button>
                        <Button variant="outline" size="sm" disabled={!clients.next_page_url} onClick={() => clients.next_page_url && router.get(clients.next_page_url, {}, { preserveState: true })}>Siguiente <ChevronRight /></Button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
