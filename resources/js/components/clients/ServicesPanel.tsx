import { router, useForm } from '@inertiajs/react';
import { CalendarClock, Layers, Loader2, Pencil, Plus, Trash2, UserRound } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import type { Options, PersonRef } from '@/types';

export interface ServiceRow {
    id: number;
    name: string;
    frequency: string;
    frequency_label: string;
    due_day: number;
    status: string;
    assignee: { id: number; name: string } | null;
    amount_label?: string;
    amount?: string;
    currency?: string;
    split_rule?: string;
    split_label?: string;
    split_percent?: number | null;
    split_amount?: string | null;
}

export interface ServiceOptions {
    frequencies: Options;
    splitRules: Options;
    currencies: Options;
    statuses: Options;
}

interface Props {
    clientId: number;
    services: ServiceRow[];
    options: ServiceOptions | null;
    assignable: PersonRef[];
    canFinance: boolean;
    canManage: boolean;
}

const statusVariant = { activo: 'success', pausado: 'warning', cancelado: 'default' } as const;

export function ServicesPanel({ clientId, services, options, assignable, canFinance, canManage }: Props) {
    const [editing, setEditing] = useState<ServiceRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<ServiceRow | null>(null);
    const base = `/clientes/${clientId}/servicios`;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    {canFinance ? 'Cada servicio define monto, vencimiento, empleado asignado y cómo se reparte lo cobrado.' : 'Servicios que tiene contratados este cliente.'}
                </p>
                {canManage && options && (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus /> Agregar servicio
                    </Button>
                )}
            </div>

            <Card className="overflow-hidden">
                {services.length === 0 ? (
                    <EmptyState icon={Layers} title="Sin servicios contratados" description="Se crean al aprobar un presupuesto, o podés agregarlos a mano." />
                ) : (
                    <ul className="divide-y">
                        {services.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center gap-x-6 gap-y-2 p-4">
                                <div className="min-w-56 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{s.name}</span>
                                        <Badge variant="primary">{s.frequency_label}</Badge>
                                        <Badge variant={statusVariant[s.status as keyof typeof statusVariant] ?? 'default'}>
                                            {options?.statuses[s.status] ?? s.status}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                        {s.frequency === 'mensual' && (
                                            <span className="inline-flex items-center gap-1.5"><CalendarClock className="size-3.5" /> Vence el día {s.due_day}</span>
                                        )}
                                        <span className="inline-flex items-center gap-1.5">
                                            <UserRound className="size-3.5" /> {s.assignee ? s.assignee.name : 'Sin empleado asignado'}
                                        </span>
                                        {canFinance && s.split_label && s.assignee && <span>· {s.split_label}</span>}
                                    </div>
                                </div>
                                {canFinance && s.amount_label && <p className="font-display text-base font-bold tabular-nums">{s.amount_label}</p>}
                                {canManage && options && (
                                    <div className="flex gap-1">
                                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Editar ${s.name}`} onClick={() => setEditing(s)}><Pencil /></Button>
                                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Eliminar ${s.name}`} onClick={() => setDeleting(s)}><Trash2 /></Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {options && <ServiceDialog base={base} target={editing} options={options} assignable={assignable} onClose={() => setEditing(null)} />}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="¿Eliminar este servicio?"
                description={`Se quita "${deleting?.name ?? ''}" del cliente. Si solo querés frenar el cobro, mejor pausalo.`}
                confirmLabel="Eliminar"
                destructive
                onConfirm={() => deleting && router.delete(`${base}/${deleting.id}`, { preserveScroll: true, onSuccess: () => setDeleting(null) })}
            />
        </div>
    );
}

function ServiceDialog({ base, target, options, assignable, onClose }: { base: string; target: ServiceRow | 'new' | null; options: ServiceOptions; assignable: PersonRef[]; onClose: () => void }) {
    const service = target && target !== 'new' ? target : null;
    const form = useForm({
        name: '', amount: '', currency: 'ARS', frequency: 'mensual', due_day: 10,
        assigned_user_id: '' as number | '', split_rule: 'porcentaje', split_percent: '30', split_amount: '', status: 'activo',
    });

    useEffect(() => {
        if (target === null) return;
        form.clearErrors();
        form.setData({
            name: service?.name ?? '',
            amount: service?.amount ?? '',
            currency: service?.currency ?? 'ARS',
            frequency: service?.frequency ?? 'mensual',
            due_day: service?.due_day ?? 10,
            assigned_user_id: service?.assignee?.id ?? '',
            split_rule: service?.split_rule && service.split_rule !== '100_agencia' ? service.split_rule : 'porcentaje',
            split_percent: service?.split_percent != null ? String(service.split_percent) : '30',
            split_amount: service?.split_amount ?? '',
            status: service?.status ?? 'activo',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    const assigned = form.data.assigned_user_id !== '';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            assigned_user_id: d.assigned_user_id === '' ? null : d.assigned_user_id,
            split_rule: d.assigned_user_id === '' ? '100_agencia' : d.split_rule,
        }));
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (service) form.put(`${base}/${service.id}`, opts);
        else form.post(base, opts);
    };

    return (
        <Dialog open={target !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl">
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>{service ? 'Editar servicio contratado' : 'Agregar servicio contratado'}</DialogTitle>
                        <DialogDescription>Define cuánto se cobra, cuándo vence y cómo se reparte entre la agencia y el empleado.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="cs-name">Servicio *</Label>
                            <Input id="cs-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <FieldError message={form.errors.name} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="cs-amount">Monto *</Label>
                                <Input id="cs-amount" type="number" step="0.01" min="0" required value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                                <FieldError message={form.errors.amount} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="cs-currency">Moneda</Label>
                                <Select id="cs-currency" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                                    {Object.keys(options.currencies).map((v) => <option key={v} value={v}>{v}</option>)}
                                </Select>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="cs-freq">Frecuencia</Label>
                                <Select id="cs-freq" value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                                    {Object.entries(options.frequencies).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="cs-due">Día de vencimiento (1–28)</Label>
                                <Input id="cs-due" type="number" min={1} max={28} value={form.data.due_day} onChange={(e) => form.setData('due_day', Number(e.target.value))} />
                                <FieldError message={form.errors.due_day} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="cs-status">Estado</Label>
                                <Select id="cs-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                    {Object.entries(options.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </Select>
                            </div>
                        </div>

                        <div className="rounded-lg border bg-muted/30 p-3.5">
                            <p className="mb-3 text-sm font-medium">Empleado y reparto</p>
                            <div className="grid gap-3 sm:grid-cols-[1fr_1fr_110px]">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="cs-user">Empleado asignado</Label>
                                    <Select id="cs-user" value={form.data.assigned_user_id} onChange={(e) => form.setData('assigned_user_id', e.target.value ? Number(e.target.value) : '')}>
                                        <option value="">Sin asignar (100 % agencia)</option>
                                        {assignable.map((p) => <option key={p.id} value={p.id}>{p.name}{p.job_title ? ` · ${p.job_title}` : ''}</option>)}
                                    </Select>
                                    <FieldError message={form.errors.assigned_user_id} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="cs-rule">Regla de reparto</Label>
                                    <Select id="cs-rule" disabled={!assigned} value={form.data.split_rule} onChange={(e) => form.setData('split_rule', e.target.value)}>
                                        {Object.entries(options.splitRules).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </Select>
                                </div>
                                {assigned && form.data.split_rule === 'porcentaje' && (
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="cs-pct">Porcentaje %</Label>
                                        <Input id="cs-pct" type="number" min="0" max="100" step="0.01" value={form.data.split_percent} onChange={(e) => form.setData('split_percent', e.target.value)} />
                                        <FieldError message={form.errors.split_percent} />
                                    </div>
                                )}
                                {assigned && form.data.split_rule === 'monto_fijo' && (
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="cs-fixed">Monto fijo</Label>
                                        <Input id="cs-fixed" type="number" min="0" step="0.01" value={form.data.split_amount} onChange={(e) => form.setData('split_amount', e.target.value)} />
                                        <FieldError message={form.errors.split_amount} />
                                    </div>
                                )}
                            </div>
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2 className="animate-spin" />} Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
