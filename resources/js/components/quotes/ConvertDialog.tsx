import { useForm } from '@inertiajs/react';
import { Loader2, Rocket } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import type { Options, PersonRef } from '@/types';

interface QuoteItem {
    id: number;
    description: string;
    frequency_label: string;
    total_label: string;
    total: string;
}

interface Row {
    quote_item_id: number;
    user_id: number | '';
    split_rule: string;
    split_percent: string;
    split_amount: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    quoteId: number;
    recipientName: string;
    isProspect: boolean;
    items: QuoteItem[];
    assignable: PersonRef[];
    splitRules: Options;
}

const today = () => new Date().toISOString().slice(0, 10);

export function ConvertDialog({ open, onOpenChange, quoteId, recipientName, isProspect, items, assignable, splitRules }: Props) {
    const form = useForm<{ start_date: string; items: Row[] }>({
        start_date: today(),
        items: items.map((i) => ({ quote_item_id: i.id, user_id: '', split_rule: 'porcentaje', split_percent: '30', split_amount: '' })),
    });
    const err = (key: string) => (form.errors as Record<string, string | undefined>)[key];

    useEffect(() => {
        if (open) form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const update = (index: number, patch: Partial<Row>) =>
        form.setData('items', form.data.items.map((r, i) => (i === index ? { ...r, ...patch } : r)));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // Sin empleado no hay reparto: se envía solo el ítem y todo queda para la agencia.
        form.transform((d) => ({
            ...d,
            items: d.items.map((r) => (r.user_id === '' ? { quote_item_id: r.quote_item_id } : r)),
        }));
        form.post(`/presupuestos/${quoteId}/convertir`);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Aprobar y convertir</DialogTitle>
                        <DialogDescription>
                            {isProspect ? `${recipientName} pasa a ser cliente activo` : `Se agregan los servicios a ${recipientName}`}, se crea su tablero de trabajo y se configura el reparto de cada servicio.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        {err('quote') && (
                            <p role="alert" className="rounded-md border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger">{err('quote')}</p>
                        )}

                        <div className="flex flex-col gap-1.5 sm:w-56">
                            <Label htmlFor="start_date">Inicio de los servicios</Label>
                            <Input id="start_date" type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />
                            <p className="text-xs text-muted-foreground">El día de inicio define el vencimiento mensual (máx. día 28).</p>
                            <FieldError message={err('start_date')} />
                        </div>

                        <div className="flex flex-col gap-3">
                            {items.map((item, i) => {
                                const row = form.data.items[i];
                                const assigned = row.user_id !== '';
                                return (
                                    <fieldset key={item.id} className="rounded-lg border p-3.5">
                                        <legend className="sr-only">{item.description}</legend>
                                        <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                                            <p className="font-medium">{item.description}</p>
                                            <p className="text-sm text-muted-foreground">{item.frequency_label} · <span className="font-medium text-foreground tabular-nums">{item.total_label}</span></p>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-[1.5fr_1fr_110px]">
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor={`u-${i}`}>Empleado asignado</Label>
                                                <Select id={`u-${i}`} value={row.user_id} onChange={(e) => update(i, { user_id: e.target.value ? Number(e.target.value) : '' })}>
                                                    <option value="">Sin asignar (100 % agencia)</option>
                                                    {assignable.map((p) => (
                                                        <option key={p.id} value={p.id}>{p.name}{p.job_title ? ` · ${p.job_title}` : ''}</option>
                                                    ))}
                                                </Select>
                                                <FieldError message={err(`items.${i}.user_id`)} />
                                            </div>
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor={`r-${i}`}>Regla de reparto</Label>
                                                <Select id={`r-${i}`} disabled={!assigned} value={row.split_rule} onChange={(e) => update(i, { split_rule: e.target.value })}>
                                                    {Object.entries(splitRules).map(([v, l]) => (
                                                        <option key={v} value={v}>{l}</option>
                                                    ))}
                                                </Select>
                                                <FieldError message={err(`items.${i}.split_rule`)} />
                                            </div>
                                            {assigned && row.split_rule === 'porcentaje' && (
                                                <div className="flex flex-col gap-1.5">
                                                    <Label htmlFor={`pc-${i}`}>Porcentaje %</Label>
                                                    <Input id={`pc-${i}`} type="number" min="0" max="100" step="0.01" value={row.split_percent} onChange={(e) => update(i, { split_percent: e.target.value })} />
                                                    <FieldError message={err(`items.${i}.split_percent`)} />
                                                </div>
                                            )}
                                            {assigned && row.split_rule === 'monto_fijo' && (
                                                <div className="flex flex-col gap-1.5">
                                                    <Label htmlFor={`mf-${i}`}>Monto fijo</Label>
                                                    <Input id={`mf-${i}`} type="number" min="0" step="0.01" value={row.split_amount} onChange={(e) => update(i, { split_amount: e.target.value })} />
                                                    <FieldError message={err(`items.${i}.split_amount`)} />
                                                </div>
                                            )}
                                        </div>
                                    </fieldset>
                                );
                            })}
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Loader2 className="animate-spin" /> : <Rocket />} Aprobar y convertir
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
