import { router, useForm } from '@inertiajs/react';
import { BadgeCheck, ChevronLeft, ChevronRight, HandCoins, Hourglass, Loader2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout';
import type { Paginated } from '@/types';

interface Row {
    id: number;
    user: string;
    user_id: number;
    client: string;
    invoice: string;
    calculation: string;
    amount_label: string;
    status: 'pendiente' | 'liquidada';
    created_at: string;
    settled_at: string | null;
    note: string | null;
}

interface Props {
    payouts: Paginated<Row>;
    filters: { status: string; user: string };
    totals: { pending: string[]; settled: string[] };
    employees: { id: number; name: string }[];
    scope: 'all' | 'own';
    can: { settle: boolean };
}

const fmt = (iso: string) => new Date(iso).toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' });

export default function Index({ payouts, filters, totals, employees, scope, can }: Props) {
    const [selected, setSelected] = useState<number[]>([]);
    const [open, setOpen] = useState(false);
    const own = scope === 'own';

    // La selección se limpia al cambiar de página o de filtro.
    useEffect(() => setSelected([]), [payouts.current_page, filters.status, filters.user]);

    const pendingOnPage = payouts.data.filter((p) => p.status === 'pendiente');
    const allSelected = pendingOnPage.length > 0 && pendingOnPage.every((p) => selected.includes(p.id));
    const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const setFilter = (next: Partial<Props['filters']>) => router.get('/liquidaciones', { ...filters, ...next }, { preserveState: true, replace: true });

    return (
        <AppLayout title={own ? 'Mis liquidaciones' : 'Liquidaciones'}>
            <div className="mb-6 grid gap-3 sm:grid-cols-2 sm:gap-4">
                <Card className="p-5">
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">{own ? 'Te deben liquidar' : 'Pendiente de liquidar'}</p>
                        <span className="grid size-9 place-items-center rounded-lg bg-warning/15 text-warning"><Hourglass className="size-[18px]" /></span>
                    </div>
                    <p className="mt-3 font-display text-2xl font-extrabold tracking-tight tabular-nums">{totals.pending.length ? totals.pending.join(' · ') : '—'}</p>
                </Card>
                <Card className="p-5">
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">{own ? 'Ya liquidado' : 'Liquidado'}</p>
                        <span className="grid size-9 place-items-center rounded-lg bg-success/15 text-success"><BadgeCheck className="size-[18px]" /></span>
                    </div>
                    <p className="mt-3 font-display text-2xl font-extrabold tracking-tight tabular-nums">{totals.settled.length ? totals.settled.join(' · ') : '—'}</p>
                </Card>
            </div>

            <div className="mb-5 flex flex-wrap items-center gap-3">
                <div className="w-44">
                    <Select aria-label="Filtrar por estado" value={filters.status} onChange={(e) => setFilter({ status: e.target.value })}>
                        <option value="">Todas</option>
                        <option value="pendiente">Pendientes</option>
                        <option value="liquidada">Liquidadas</option>
                    </Select>
                </div>
                {!own && (
                    <div className="w-52">
                        <Select aria-label="Filtrar por empleado" value={filters.user} onChange={(e) => setFilter({ user: e.target.value })}>
                            <option value="">Todo el equipo</option>
                            {employees.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
                        </Select>
                    </div>
                )}
                {can.settle && (
                    <Button className="ml-auto" disabled={selected.length === 0} onClick={() => setOpen(true)}>
                        <BadgeCheck /> Marcar {selected.length > 0 ? selected.length : ''} como liquidada{selected.length === 1 ? '' : 's'}
                    </Button>
                )}
            </div>

            <Card className="overflow-hidden">
                {payouts.data.length === 0 ? (
                    <EmptyState
                        icon={HandCoins}
                        title="Todavía no hay liquidaciones"
                        description={own ? 'Cuando se cobre un servicio que tenés asignado, tu parte aparece acá automáticamente.' : 'Se generan solas cuando se registra el pago de un servicio con empleado asignado.'}
                    />
                ) : (
                    <ul className="divide-y">
                        {can.settle && pendingOnPage.length > 0 && (
                            <li className="flex items-center gap-3 bg-muted/40 px-4 py-2.5 text-sm">
                                <input type="checkbox" className="size-4 accent-[var(--primary)]" checked={allSelected} aria-label="Seleccionar todas las pendientes de esta página"
                                    onChange={() => setSelected(allSelected ? [] : pendingOnPage.map((p) => p.id))} />
                                <span className="text-muted-foreground">Seleccionar pendientes de esta página</span>
                            </li>
                        )}
                        {payouts.data.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
                                {can.settle && (
                                    <input type="checkbox" className="size-4 accent-[var(--primary)] disabled:opacity-30" disabled={p.status !== 'pendiente'} checked={selected.includes(p.id)}
                                        onChange={() => toggle(p.id)} aria-label={`Seleccionar liquidación de ${p.user} por ${p.client}`} />
                                )}
                                <div className="min-w-56 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {!own && <span className="font-medium">{p.user}</span>}
                                        <span className={own ? 'font-medium' : 'text-muted-foreground'}>{p.client}</span>
                                        <Badge variant={p.status === 'pendiente' ? 'warning' : 'success'}>{p.status === 'pendiente' ? 'Pendiente' : 'Liquidada'}</Badge>
                                    </div>
                                    <p className="mt-0.5 text-sm text-muted-foreground">{p.calculation} · cobro {p.invoice}</p>
                                    <p className="text-xs text-muted-foreground">
                                        Generada el {fmt(p.created_at)}{p.settled_at && ` · liquidada el ${fmt(p.settled_at)}`}{p.note && ` · ${p.note}`}
                                    </p>
                                </div>
                                <p className="font-display text-base font-bold tabular-nums">{p.amount_label}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {payouts.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>{payouts.from}–{payouts.to} de {payouts.total}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!payouts.prev_page_url} onClick={() => payouts.prev_page_url && router.get(payouts.prev_page_url, {}, { preserveState: true })}><ChevronLeft /> Anterior</Button>
                        <Button variant="outline" size="sm" disabled={!payouts.next_page_url} onClick={() => payouts.next_page_url && router.get(payouts.next_page_url, {}, { preserveState: true })}>Siguiente <ChevronRight /></Button>
                    </div>
                </div>
            )}

            <SettleDialog open={open} ids={selected} onClose={() => setOpen(false)} onDone={() => setSelected([])} />
        </AppLayout>
    );
}

function SettleDialog({ open, ids, onClose, onDone }: { open: boolean; ids: number[]; onClose: () => void; onDone: () => void }) {
    const form = useForm({ ids: [] as number[], note: '' });

    useEffect(() => { if (open) { form.clearErrors(); form.setData({ ids, note: '' }); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/liquidaciones/liquidar', { preserveScroll: true, onSuccess: () => { onDone(); onClose(); } });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Marcar como liquidadas</DialogTitle>
                        <DialogDescription>Confirmá que ya le pagaste a tu equipo. {ids.length} {ids.length === 1 ? 'liquidación' : 'liquidaciones'}; cada empleado recibe un aviso.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="s-note">Nota (opcional)</Label>
                            <Input id="s-note" placeholder="Ej.: transferencia del 14/11" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                            <FieldError message={form.errors.note ?? (form.errors as Record<string, string | undefined>).ids} />
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing && <Loader2 className="animate-spin" />} Confirmar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
