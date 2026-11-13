import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Ban, BadgeCheck, ChevronLeft, ChevronRight, Clock, Loader2, Plus, Receipt, RefreshCw, Search, Wallet, type LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { EmptyState } from '@/components/empty-state';
import { InvoiceStatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout';
import type { Options, Paginated } from '@/types';

interface Row {
    id: number;
    number: string;
    client: string;
    client_id: number;
    description: string;
    amount_label: string;
    amount: string;
    due_date: string;
    status: string;
    paid_at: string | null;
    payment_method: string | null;
    void_reason: string | null;
    payouts: { user: string; amount_label: string; status: string }[];
}

interface Props {
    invoices: Paginated<Row>;
    filters: { q: string; status: string; client: string };
    statusOptions: Options;
    paymentMethods: Options;
    currencies: Options;
    clients: { id: number; name: string }[];
    summary: { pending: number; overdue: number; paid_month: number };
    can: { manage: boolean };
}

const fmt = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' });
const today = () => new Date().toISOString().slice(0, 10);

function Kpi({ label, value, icon: Icon, tone }: { label: string; value: number; icon: LucideIcon; tone: string }) {
    return (
        <Card className="p-4 sm:p-5">
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">{label}</p>
                <span className={`grid size-9 place-items-center rounded-lg ${tone}`}><Icon className="size-[18px]" /></span>
            </div>
            <p className="mt-3 font-display text-2xl font-extrabold tracking-tight tabular-nums">{value}</p>
        </Card>
    );
}

export default function Index({ invoices, filters, statusOptions, paymentMethods, currencies, clients, summary, can }: Props) {
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const [paying, setPaying] = useState<Row | null>(null);
    const [voiding, setVoiding] = useState<Row | null>(null);
    const [creating, setCreating] = useState(false);
    const [generating, setGenerating] = useState(false);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => router.get('/cobros', { q: q || undefined, status: status || undefined, client: filters.client || undefined }, { preserveState: true, replace: true }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q, status]);

    const filtered = q !== '' || status !== '' || filters.client !== '';

    return (
        <AppLayout title="Cobros">
            <div className="mb-6 grid grid-cols-3 gap-3 sm:gap-4">
                <Kpi label="Pendientes" value={summary.pending} icon={Clock} tone="bg-accent text-accent-foreground" />
                <Kpi label="Vencidos" value={summary.overdue} icon={AlertTriangle} tone="bg-danger/15 text-danger" />
                <Kpi label="Pagados este mes" value={summary.paid_month} icon={BadgeCheck} tone="bg-success/15 text-success" />
            </div>

            <div className="mb-5 flex flex-wrap items-center gap-3">
                <div className="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="pl-9" placeholder="Buscar por cliente, número o concepto…" aria-label="Buscar cobros" value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                <div className="w-44">
                    <Select aria-label="Filtrar por estado" value={status} onChange={(e) => setStatus(e.target.value)}>
                        <option value="">Todos los estados</option>
                        {Object.entries(statusOptions).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </Select>
                </div>
                {can.manage && (
                    <div className="ml-auto flex gap-2">
                        <Button
                            variant="outline"
                            disabled={generating}
                            onClick={() => { setGenerating(true); router.post('/cobros/generar', {}, { preserveScroll: true, onFinish: () => setGenerating(false) }); }}
                        >
                            {generating ? <Loader2 className="animate-spin" /> : <RefreshCw />} Generar cobros
                        </Button>
                        <Button onClick={() => setCreating(true)}><Plus /> Cobro manual</Button>
                    </div>
                )}
            </div>

            <Card className="overflow-hidden">
                {invoices.data.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title={filtered ? 'Sin resultados' : 'Todavía no hay cobros'}
                        description={filtered ? 'Probá con otra búsqueda o quitá los filtros.' : 'Se generan solos a partir de los servicios contratados, o podés crear uno manual.'}
                        action={filtered ? <Button variant="outline" size="sm" onClick={() => { setQ(''); setStatus(''); router.get('/cobros'); }}>Quitar filtros</Button> : undefined}
                    />
                ) : (
                    <ul className="divide-y">
                        {invoices.data.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
                                <div className="min-w-56 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link href={`/clientes/${r.client_id}`} className="font-medium hover:underline">{r.client}</Link>
                                        <InvoiceStatusBadge status={r.status} />
                                    </div>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {r.description} · <span className="tabular-nums">{r.number}</span>
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {r.status === 'pagada' && r.paid_at ? `Pagado el ${fmt(r.paid_at.slice(0, 10))}${r.payment_method ? ` · ${r.payment_method}` : ''}` : r.status === 'anulada' ? `Anulado${r.void_reason ? `: ${r.void_reason}` : ''}` : `Vence el ${fmt(r.due_date)}`}
                                        {r.payouts.map((p) => ` · liquidación ${p.amount_label} a ${p.user} (${p.status})`).join('')}
                                    </p>
                                </div>
                                <p className="font-display text-base font-bold tabular-nums">{r.amount_label}</p>
                                {can.manage && (r.status === 'pendiente' || r.status === 'vencida') && (
                                    <div className="flex gap-1">
                                        <Button size="sm" onClick={() => setPaying(r)}><Wallet /> Registrar pago</Button>
                                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Anular ${r.number}`} onClick={() => setVoiding(r)}><Ban /></Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {invoices.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>{invoices.from}–{invoices.to} de {invoices.total}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!invoices.prev_page_url} onClick={() => invoices.prev_page_url && router.get(invoices.prev_page_url, {}, { preserveState: true })}><ChevronLeft /> Anterior</Button>
                        <Button variant="outline" size="sm" disabled={!invoices.next_page_url} onClick={() => invoices.next_page_url && router.get(invoices.next_page_url, {}, { preserveState: true })}>Siguiente <ChevronRight /></Button>
                    </div>
                </div>
            )}

            <PayDialog invoice={paying} methods={paymentMethods} onClose={() => setPaying(null)} />
            <VoidDialog invoice={voiding} onClose={() => setVoiding(null)} />
            <CreateDialog open={creating} clients={clients} currencies={currencies} onClose={() => setCreating(false)} />
        </AppLayout>
    );
}

function PayDialog({ invoice, methods, onClose }: { invoice: Row | null; methods: Options; onClose: () => void }) {
    const form = useForm({ payment_method: 'transferencia', payment_reference: '', paid_at: today() });
    const err = (k: string) => (form.errors as Record<string, string | undefined>)[k];

    useEffect(() => { if (invoice) { form.clearErrors(); form.setData({ payment_method: 'transferencia', payment_reference: '', paid_at: today() }); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [invoice]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (invoice) form.post(`/cobros/${invoice.id}/pagar`, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={invoice !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Registrar pago</DialogTitle>
                        <DialogDescription>{invoice?.client} · <span className="font-medium text-foreground">{invoice?.amount_label}</span>. Si el servicio tiene empleado asignado, se genera su liquidación automáticamente.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        {err('invoice') && <p role="alert" className="rounded-md border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger">{err('invoice')}</p>}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="p-method">Medio de pago</Label>
                                <Select id="p-method" value={form.data.payment_method} onChange={(e) => form.setData('payment_method', e.target.value)}>
                                    {Object.entries(methods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </Select>
                                <FieldError message={form.errors.payment_method} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="p-date">Fecha de pago</Label>
                                <Input id="p-date" type="date" max={today()} value={form.data.paid_at} onChange={(e) => form.setData('paid_at', e.target.value)} />
                                <FieldError message={form.errors.paid_at} />
                            </div>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="p-ref">Referencia (opcional)</Label>
                            <Input id="p-ref" placeholder="N.º de transferencia, comprobante…" value={form.data.payment_reference} onChange={(e) => form.setData('payment_reference', e.target.value)} />
                            <FieldError message={form.errors.payment_reference} />
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing ? <Loader2 className="animate-spin" /> : <Wallet />} Registrar pago</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function VoidDialog({ invoice, onClose }: { invoice: Row | null; onClose: () => void }) {
    const form = useForm({ reason: '' });

    useEffect(() => { if (invoice) { form.clearErrors(); form.reset(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [invoice]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (invoice) form.post(`/cobros/${invoice.id}/anular`, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={invoice !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Anular cobro</DialogTitle>
                        <DialogDescription>{invoice?.number} · {invoice?.client}. Un cobro anulado no se cobra ni cuenta como deuda. Solo se anulan cobros pendientes.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="v-reason">Motivo *</Label>
                            <Input id="v-reason" required value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="Ej.: el cliente dio de baja el servicio" />
                            <FieldError message={form.errors.reason ?? (form.errors as Record<string, string | undefined>).invoice} />
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" variant="danger" disabled={form.processing}>{form.processing && <Loader2 className="animate-spin" />} Anular cobro</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CreateDialog({ open, clients, currencies, onClose }: { open: boolean; clients: { id: number; name: string }[]; currencies: Options; onClose: () => void }) {
    const form = useForm({ client_id: '' as number | '', description: '', amount: '', currency: 'ARS', due_date: today() });

    useEffect(() => { if (open) { form.clearErrors(); form.reset(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/cobros', { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Cobro manual</DialogTitle>
                        <DialogDescription>Para trabajos puntuales o ajustes que no salen de un servicio recurrente.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="m-client">Cliente *</Label>
                            <Select id="m-client" required value={form.data.client_id} onChange={(e) => form.setData('client_id', e.target.value ? Number(e.target.value) : '')}>
                                <option value="">Elegí un cliente…</option>
                                {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                            <FieldError message={form.errors.client_id} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="m-desc">Concepto *</Label>
                            <Input id="m-desc" required value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            <FieldError message={form.errors.description} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="m-amount">Monto *</Label>
                                <Input id="m-amount" type="number" step="0.01" min="0.01" required value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                                <FieldError message={form.errors.amount} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="m-currency">Moneda</Label>
                                <Select id="m-currency" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                                    {Object.keys(currencies).map((v) => <option key={v} value={v}>{v}</option>)}
                                </Select>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="m-due">Vence *</Label>
                                <Input id="m-due" type="date" required value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                                <FieldError message={form.errors.due_date} />
                            </div>
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing && <Loader2 className="animate-spin" />} Crear cobro</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
