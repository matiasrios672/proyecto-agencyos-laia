import { Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus, Search, Trash2, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { AccountStatusBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout';
import type { ClientRow, Options, Paginated, SharedProps } from '@/types';

interface Props {
    clients: Paginated<ClientRow>;
    filters: { q: string; status: string };
    statusOptions: Options;
    can: { create: boolean };
}

export default function Index({ clients, filters, statusOptions, can }: Props) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const first = useRef(true);
    // La papelera es de quien puede gestionar clientes (el servidor lo vuelve a validar).
    const { auth } = usePage<SharedProps>().props;
    const canRestore = !!auth && (auth.user.is_sysadmin || auth.permissions.includes('clients.manage'));

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => {
            router.get('/clientes', { q: q || undefined, status: status || undefined }, { preserveState: true, replace: true });
        }, 300);
        return () => clearTimeout(t);
    }, [q, status]);

    const filtered = q !== '' || status !== '';

    return (
        <AppLayout title="Clientes">
            <div className="mb-5 flex flex-wrap items-center gap-3">
                <div className="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        className="pl-9"
                        placeholder="Buscar por nombre, CUIT o email…"
                        aria-label="Buscar clientes"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                </div>
                <div className="w-44">
                    <Select aria-label="Filtrar por estado de cuenta" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">Todos los estados</option>
                        {Object.entries(statusOptions).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="ml-auto flex items-center gap-2">
                    {canRestore && (
                        <Link href="/clientes-eliminados">
                            <Button variant="ghost">
                                <Trash2 /> Eliminados
                            </Button>
                        </Link>
                    )}
                    {can.create && (
                        <Link href="/clientes/create">
                            <Button>
                                <Plus /> Nuevo cliente
                            </Button>
                        </Link>
                    )}
                </div>
            </div>

            <Card className="overflow-hidden">
                {clients.data.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={filtered ? 'Sin resultados' : 'Todavía no hay clientes'}
                        description={
                            filtered
                                ? 'Probá con otra búsqueda o quitá los filtros.'
                                : can.create
                                  ? 'Cargá el primero para empezar a gestionar su bóveda, contenido y cobros.'
                                  : 'Dirección todavía no te asignó ningún cliente.'
                        }
                        action={
                            filtered ? (
                                <Button variant="outline" size="sm" onClick={() => { setQ(''); setStatus(''); }}>
                                    Quitar filtros
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        {/* Escritorio: tabla */}
                        <table className="hidden w-full text-sm md:table">
                            <thead>
                                <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                    <th className="px-5 py-2.5 font-medium">Cliente</th>
                                    <th className="px-5 py-2.5 font-medium">CUIT</th>
                                    <th className="px-5 py-2.5 font-medium">Contacto</th>
                                    <th className="px-5 py-2.5 font-medium">Equipo</th>
                                    <th className="px-5 py-2.5 font-medium">Cuenta</th>
                                </tr>
                            </thead>
                            <tbody>
                                {clients.data.map((c) => (
                                    <tr key={c.id} className="border-b last:border-0 transition-colors hover:bg-muted/40">
                                        <td className="px-5 py-3">
                                            <Link href={`/clientes/${c.id}`} className="font-medium hover:underline">
                                                {c.name}
                                            </Link>
                                            {c.name !== c.business_name && <p className="text-xs text-muted-foreground">{c.business_name}</p>}
                                            {!c.is_active && <Badge className="mt-1">Inactivo</Badge>}
                                        </td>
                                        <td className="px-5 py-3 text-muted-foreground tabular-nums">{c.tax_id ?? '—'}</td>
                                        <td className="px-5 py-3 text-muted-foreground">
                                            <p>{c.email ?? '—'}</p>
                                            <p className="text-xs">{c.phone}</p>
                                        </td>
                                        <td className="px-5 py-3 text-muted-foreground">
                                            {c.team_count} {c.team_count === 1 ? 'persona' : 'personas'}
                                        </td>
                                        <td className="px-5 py-3">
                                            <AccountStatusBadge status={c.account_status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {/* Móvil: tarjetas */}
                        <ul className="divide-y md:hidden">
                            {clients.data.map((c) => (
                                <li key={c.id}>
                                    <Link href={`/clientes/${c.id}`} className="flex flex-col gap-1.5 p-4 transition-colors hover:bg-muted/40">
                                        <div className="flex items-start justify-between gap-3">
                                            <span className="font-medium">{c.name}</span>
                                            <AccountStatusBadge status={c.account_status} />
                                        </div>
                                        <span className="text-xs text-muted-foreground">{c.tax_id ?? 'Sin CUIT'} · {c.team_count} en el equipo</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </Card>

            {clients.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>
                        {clients.from}–{clients.to} de {clients.total}
                    </span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!clients.prev_page_url} onClick={() => clients.prev_page_url && router.get(clients.prev_page_url, {}, { preserveState: true })}>
                            <ChevronLeft /> Anterior
                        </Button>
                        <Button variant="outline" size="sm" disabled={!clients.next_page_url} onClick={() => clients.next_page_url && router.get(clients.next_page_url, {}, { preserveState: true })}>
                            Siguiente <ChevronRight />
                        </Button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
