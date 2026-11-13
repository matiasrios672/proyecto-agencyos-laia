import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FileText, FilePen, Plus, Search, Send, TrendingUp, CheckCircle2, type LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { QuoteStatusBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout';
import type { Options, Paginated } from '@/types';

interface Row {
    id: number;
    number: string;
    recipient: string;
    is_prospect: boolean;
    total_label: string;
    status: string;
    valid_until: string | null;
    created_at: string;
}

interface Props {
    quotes: Paginated<Row>;
    filters: { q: string; status: string };
    statusOptions: Options;
    summary: { drafts: number; sent: number; approved_30d: number; pipeline: { currency: string; label: string }[] };
    can: { create: boolean };
}

function Kpi({ label, value, icon: Icon, tone, hint }: { label: string; value: string | number; icon: LucideIcon; tone: string; hint?: string }) {
    return (
        <Card className="p-4 sm:p-5">
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">{label}</p>
                <span className={`grid size-9 place-items-center rounded-lg ${tone}`}>
                    <Icon className="size-[18px]" />
                </span>
            </div>
            <p className="mt-3 font-display text-2xl font-extrabold tracking-tight tabular-nums">{value}</p>
            {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
        </Card>
    );
}

const fmtDate = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString('es-AR', { day: 'numeric', month: 'short' });

export default function Index({ quotes, filters, statusOptions, summary, can }: Props) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => router.get('/presupuestos', { q: q || undefined, status: status || undefined }, { preserveState: true, replace: true }), 300);
        return () => clearTimeout(t);
    }, [q, status]);

    const filtered = q !== '' || status !== '';

    return (
        <AppLayout title="Presupuestos">
            <div className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <Kpi label="Borradores" value={summary.drafts} icon={FilePen} tone="bg-muted text-muted-foreground" />
                <Kpi label="Enviados" value={summary.sent} icon={Send} tone="bg-accent text-accent-foreground" />
                <Kpi
                    label="En negociación"
                    value={summary.pipeline.length ? summary.pipeline[0].label : '—'}
                    hint={summary.pipeline.length > 1 ? `+ ${summary.pipeline.slice(1).map((p) => p.label).join(' · ')}` : 'Enviados y vigentes'}
                    icon={TrendingUp}
                    tone="bg-warning/15 text-warning"
                />
                <Kpi label="Aprobados (30 días)" value={summary.approved_30d} icon={CheckCircle2} tone="bg-success/15 text-success" />
            </div>

            <div className="mb-5 flex flex-wrap items-center gap-3">
                <div className="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="pl-9" placeholder="Buscar por número o destinatario…" aria-label="Buscar presupuestos" value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                <div className="w-44">
                    <Select aria-label="Filtrar por estado" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">Todos los estados</option>
                        {Object.entries(statusOptions).map(([v, l]) => (
                            <option key={v} value={v}>{l}</option>
                        ))}
                    </Select>
                </div>
                {can.create && (
                    <Link href="/presupuestos/create" className="ml-auto">
                        <Button><Plus /> Nuevo presupuesto</Button>
                    </Link>
                )}
            </div>

            <Card className="overflow-hidden">
                {quotes.data.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title={filtered ? 'Sin resultados' : 'Todavía no hay presupuestos'}
                        description={filtered ? 'Probá con otra búsqueda o quitá los filtros.' : 'Armá el primero a partir del catálogo y envialo por email o WhatsApp.'}
                        action={filtered ? <Button variant="outline" size="sm" onClick={() => { setQ(''); setStatus(''); }}>Quitar filtros</Button> : undefined}
                    />
                ) : (
                    <>
                        <table className="hidden w-full text-sm md:table">
                            <thead>
                                <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                    <th className="px-5 py-2.5 font-medium">Número</th>
                                    <th className="px-5 py-2.5 font-medium">Destinatario</th>
                                    <th className="px-5 py-2.5 text-right font-medium">Total</th>
                                    <th className="px-5 py-2.5 font-medium">Válido hasta</th>
                                    <th className="px-5 py-2.5 font-medium">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {quotes.data.map((r) => (
                                    <tr key={r.id} className="border-b transition-colors last:border-0 hover:bg-muted/40">
                                        <td className="px-5 py-3">
                                            <Link href={`/presupuestos/${r.id}`} className="font-medium tabular-nums hover:underline">{r.number}</Link>
                                        </td>
                                        <td className="px-5 py-3">
                                            {r.recipient} {r.is_prospect && <Badge className="ml-1.5">Prospecto</Badge>}
                                        </td>
                                        <td className="px-5 py-3 text-right font-medium tabular-nums">{r.total_label}</td>
                                        <td className="px-5 py-3 text-muted-foreground">{r.valid_until ? fmtDate(r.valid_until) : '—'}</td>
                                        <td className="px-5 py-3"><QuoteStatusBadge status={r.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <ul className="divide-y md:hidden">
                            {quotes.data.map((r) => (
                                <li key={r.id}>
                                    <Link href={`/presupuestos/${r.id}`} className="flex flex-col gap-1.5 p-4 transition-colors hover:bg-muted/40">
                                        <div className="flex items-start justify-between gap-3">
                                            <span className="font-medium">{r.recipient}</span>
                                            <QuoteStatusBadge status={r.status} />
                                        </div>
                                        <div className="flex items-center justify-between text-sm text-muted-foreground">
                                            <span className="tabular-nums">{r.number}</span>
                                            <span className="font-medium text-foreground tabular-nums">{r.total_label}</span>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </Card>

            {quotes.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>{quotes.from}–{quotes.to} de {quotes.total}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!quotes.prev_page_url} onClick={() => quotes.prev_page_url && router.get(quotes.prev_page_url, {}, { preserveState: true })}>
                            <ChevronLeft /> Anterior
                        </Button>
                        <Button variant="outline" size="sm" disabled={!quotes.next_page_url} onClick={() => quotes.next_page_url && router.get(quotes.next_page_url, {}, { preserveState: true })}>
                            Siguiente <ChevronRight />
                        </Button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
