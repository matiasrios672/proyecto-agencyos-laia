import { router, useForm } from '@inertiajs/react';
import { Loader2, Package, Pencil, Plus, Trash2 } from 'lucide-react';
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

interface Service {
    id: number;
    name: string;
    description: string | null;
    price: string;
    price_label: string;
    currency: string;
    frequency: string;
    frequency_label: string;
    is_active: boolean;
}

interface Props {
    services: Service[];
    currencies: Options;
    frequencies: Options;
    can: { manage: boolean };
}

export default function Index({ services, currencies, frequencies, can }: Props) {
    const [editing, setEditing] = useState<Service | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Service | null>(null);

    return (
        <AppLayout title="Catálogo de servicios">
            <div className="mb-5 flex items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">Servicios prearmados para armar presupuestos en segundos. Cada presupuesto guarda su propia copia del precio.</p>
                {can.manage && (
                    <Button onClick={() => setEditing('new')}>
                        <Plus /> Nuevo servicio
                    </Button>
                )}
            </div>

            <Card className="overflow-hidden">
                {services.length === 0 ? (
                    <EmptyState icon={Package} title="El catálogo está vacío" description="Cargá tus servicios más vendidos para presupuestar más rápido." />
                ) : (
                    <ul className="divide-y">
                        {services.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center gap-x-6 gap-y-2 p-4">
                                <div className="min-w-56 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={`font-medium ${s.is_active ? '' : 'text-muted-foreground line-through'}`}>{s.name}</span>
                                        <Badge variant="primary">{s.frequency_label}</Badge>
                                        {!s.is_active && <Badge>Inactivo</Badge>}
                                    </div>
                                    {s.description && <p className="mt-0.5 text-sm text-muted-foreground">{s.description}</p>}
                                </div>
                                <p className="font-display text-base font-bold tabular-nums">{s.price_label}</p>
                                {can.manage && (
                                    <div className="flex gap-1">
                                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Editar ${s.name}`} onClick={() => setEditing(s)}>
                                            <Pencil />
                                        </Button>
                                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Eliminar ${s.name}`} onClick={() => setDeleting(s)}>
                                            <Trash2 />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <ServiceDialog target={editing} currencies={currencies} frequencies={frequencies} onClose={() => setEditing(null)} />

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="¿Eliminar este servicio?"
                description={`Se quita "${deleting?.name ?? ''}" del catálogo. Los presupuestos ya emitidos no se modifican.`}
                confirmLabel="Eliminar"
                destructive
                onConfirm={() => deleting && router.delete(`/catalogo/${deleting.id}`, { preserveScroll: true, onSuccess: () => setDeleting(null) })}
            />
        </AppLayout>
    );
}

function ServiceDialog({ target, currencies, frequencies, onClose }: { target: Service | 'new' | null; currencies: Options; frequencies: Options; onClose: () => void }) {
    const service = target && target !== 'new' ? target : null;
    const form = useForm({ name: '', description: '', price: '', currency: 'ARS', frequency: 'mensual', is_active: true });

    useEffect(() => {
        if (target === null) return;
        form.clearErrors();
        form.setData({
            name: service?.name ?? '',
            description: service?.description ?? '',
            price: service?.price ?? '',
            currency: service?.currency ?? 'ARS',
            frequency: service?.frequency ?? 'mensual',
            is_active: service?.is_active ?? true,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (service) form.put(`/catalogo/${service.id}`, opts);
        else form.post('/catalogo', opts);
    };

    return (
        <Dialog open={target !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>{service ? 'Editar servicio' : 'Nuevo servicio'}</DialogTitle>
                        <DialogDescription>Precio de referencia: en cada presupuesto se puede ajustar.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="s-name">Nombre *</Label>
                            <Input id="s-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <FieldError message={form.errors.name} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="s-desc">Descripción</Label>
                            <Textarea id="s-desc" rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            <FieldError message={form.errors.description} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="s-price">Precio *</Label>
                                <Input id="s-price" type="number" step="0.01" min="0" required value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} />
                                <FieldError message={form.errors.price} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="s-currency">Moneda</Label>
                                <Select id="s-currency" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>
                                    {Object.keys(currencies).map((v) => (
                                        <option key={v} value={v}>{v}</option>
                                    ))}
                                </Select>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="s-freq">Frecuencia</Label>
                                <Select id="s-freq" value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                                    {Object.entries(frequencies).map(([v, l]) => (
                                        <option key={v} value={v}>{l}</option>
                                    ))}
                                </Select>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <Switch id="s-active" checked={form.data.is_active} onCheckedChange={(c) => form.setData('is_active', c)} />
                            <Label htmlFor="s-active">Disponible para presupuestar</Label>
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2 className="animate-spin" />} Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
