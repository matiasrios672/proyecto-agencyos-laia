import { router, useForm } from '@inertiajs/react';
import { Landmark, Loader2, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select, Textarea } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout';
import type { Options } from '@/types';

interface Expense {
    id: number;
    name: string;
    category: string;
    category_label: string;
    amount: string;
    amount_label: string;
    monthly_label: string | null;
    currency: string;
    frequency: string;
    frequency_label: string;
    starts_on: string;
    ends_on: string | null;
    is_active: boolean;
    notes: string | null;
}

interface Props {
    expenses: Expense[];
    monthlyTotals: { currency: string; label: string }[];
    categories: Options;
    frequencies: Options;
    currencies: Options;
    can: { manage: boolean };
}

const today = () => new Date().toISOString().slice(0, 10);

export default function Index({ expenses, monthlyTotals, categories, frequencies, currencies, can }: Props) {
    const [editing, setEditing] = useState<Expense | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Expense | null>(null);

    return (
        <AppLayout title="Egresos y suscripciones">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p className="text-sm text-muted-foreground">Costo fijo mensual de la agencia</p>
                    <p className="font-display text-3xl font-extrabold tracking-tight tabular-nums">
                        {monthlyTotals.length ? monthlyTotals.map((t) => t.label).join(' + ') : '—'}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">Las suscripciones anuales se prorratean por mes. Alimenta la Ganancia Neta Real.</p>
                </div>
                {can.manage && <Button onClick={() => setEditing('new')}><Plus /> Nuevo egreso</Button>}
            </div>

            <Card className="overflow-hidden">
                {expenses.length === 0 ? (
                    <EmptyState icon={Landmark} title="Todavía no cargaste egresos" description="Sumá Adobe, Canva, el VPS, las APIs y demás gastos para calcular tu ganancia neta real." />
                ) : (
                    <ul className="divide-y">
                        {expenses.map((e) => (
                            <li key={e.id} className="flex flex-wrap items-center gap-x-6 gap-y-2 p-4">
                                <div className="min-w-56 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={`font-medium ${e.is_active ? '' : 'text-muted-foreground line-through'}`}>{e.name}</span>
                                        <Badge variant="primary">{e.category_label}</Badge>
                                        <Badge>{e.frequency_label}</Badge>
                                        {!e.is_active && <Badge>Pausado</Badge>}
                                    </div>
                                    {e.notes && <p className="mt-0.5 text-sm text-muted-foreground">{e.notes}</p>}
                                </div>
                                <div className="text-right">
                                    <p className="font-display text-base font-bold tabular-nums">{e.amount_label}</p>
                                    {e.monthly_label && e.frequency === 'anual' && <p className="text-xs text-muted-foreground">{e.monthly_label} por mes</p>}
                                </div>
                                {can.manage && (
                                    <div className="flex gap-1">
                                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Editar ${e.name}`} onClick={() => setEditing(e)}><Pencil /></Button>
                                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Eliminar ${e.name}`} onClick={() => setDeleting(e)}><Trash2 /></Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <ExpenseDialog target={editing} categories={categories} frequencies={frequencies} currencies={currencies} onClose={() => setEditing(null)} />
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="¿Eliminar este egreso?"
                description={`Se quita "${deleting?.name ?? ''}" y deja de contar en los meses. Si solo se terminó, mejor pausalo o cargale una fecha de fin.`}
                confirmLabel="Eliminar"
                destructive
                onConfirm={() => deleting && router.delete(`/egresos/${deleting.id}`, { preserveScroll: true, onSuccess: () => setDeleting(null) })}
            />
        </AppLayout>
    );
}

function ExpenseDialog({ target, categories, frequencies, currencies, onClose }: { target: Expense | 'new' | null; categories: Options; frequencies: Options; currencies: Options; onClose: () => void }) {
    const expense = target && target !== 'new' ? target : null;
    const form = useForm({ name: '', category: 'software', amount: '', currency: 'USD', frequency: 'mensual', starts_on: today(), ends_on: '', is_active: true, notes: '' });

    useEffect(() => {
        if (target === null) return;
        form.clearErrors();
        form.setData({
            name: expense?.name ?? '', category: expense?.category ?? 'software', amount: expense?.amount ?? '', currency: expense?.currency ?? 'USD',
            frequency: expense?.frequency ?? 'mensual', starts_on: expense?.starts_on ?? today(), ends_on: expense?.ends_on ?? '',
            is_active: expense?.is_active ?? true, notes: expense?.notes ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, ends_on: d.ends_on || null }));
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (expense) form.put(`/egresos/${expense.id}`, opts);
        else form.post('/egresos', opts);
    };

    return (
        <Dialog open={target !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl">
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>{expense ? 'Editar egreso' : 'Nuevo egreso'}</DialogTitle>
                        <DialogDescription>Suscripciones y gastos de la agencia. Los pagos únicos cuentan solo en el mes de la fecha de inicio.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="x-name">Nombre *</Label>
                            <Input id="x-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Adobe Creative Cloud" />
                            <FieldError message={form.errors.name} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="x-cat">Categoría</Label>
                                <Select id="x-cat" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                    {Object.entries(categories).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </Select>
                                <FieldError message={form.errors.category} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="x-freq">Frecuencia</Label>
                                <Select id="x-freq" value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                                    {Object.entries(frequencies).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="x-amount">Monto *</Label>
                                <Input id="x-amount" type="number" step="0.01" min="0.01" required value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                                <FieldError message={form.errors.amount} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="x-cur">Moneda</Label>
                                <Select id="x-cur" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                                    {Object.keys(currencies).map((v) => <option key={v} value={v}>{v}</option>)}
                                </Select>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="x-start">Desde *</Label>
                                <Input id="x-start" type="date" required value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} />
                                <FieldError message={form.errors.starts_on} />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="x-end">Hasta (opcional)</Label>
                                <Input id="x-end" type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} />
                                <FieldError message={form.errors.ends_on} />
                            </div>
                            <div className="flex items-end gap-3 pb-2">
                                <Switch id="x-active" checked={form.data.is_active} onCheckedChange={(c) => form.setData('is_active', c)} />
                                <Label htmlFor="x-active">Vigente</Label>
                            </div>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="x-notes">Notas</Label>
                            <Textarea id="x-notes" rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing && <Loader2 className="animate-spin" />} Guardar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
