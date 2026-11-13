import { Link, useForm } from '@inertiajs/react';
import { FileCheck2, Loader2, PackagePlus, Plus, Trash2, Upload, UserPlus, Users } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select, Textarea } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout';
import { formatMoney, lineCents, toNumber } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { Options } from '@/types';

interface Item {
    service_id: number | null;
    description: string;
    quantity: number | string;
    unit_price: string;
    frequency: string;
}

interface QuoteData {
    id: number;
    number: string;
    client_id: number | null;
    prospect_name: string | null;
    prospect_email: string | null;
    prospect_phone: string | null;
    prospect_tax_id: string | null;
    currency: string;
    discount_percent: number;
    valid_until: string | null;
    notes: string | null;
    custom_pdf_name: string | null;
    items: Item[];
}

interface Props {
    quote: QuoteData | null;
    preselectedClient: number | null;
    clients: { id: number; name: string; email: string | null }[];
    catalog: { id: number; name: string; description: string | null; price: string; currency: string; frequency: string }[];
    currencies: Options;
    frequencies: Options;
    defaultValidUntil: string;
}

const blankItem = (): Item => ({ service_id: null, description: '', quantity: 1, unit_price: '', frequency: 'unico' });

export default function Form({ quote, preselectedClient, clients, catalog, currencies, frequencies, defaultValidUntil }: Props) {
    const editing = quote !== null;
    const initialClient = quote?.client_id ?? preselectedClient;
    // Al editar un presupuesto de prospecto se abre en "Prospecto"; en cualquier otro caso, en "Cliente existente".
    const [mode, setMode] = useState<'client' | 'prospect'>(quote && !quote.client_id ? 'prospect' : 'client');

    const form = useForm({
        client_id: (initialClient ?? '') as number | '',
        prospect_name: quote?.prospect_name ?? '',
        prospect_email: quote?.prospect_email ?? '',
        prospect_phone: quote?.prospect_phone ?? '',
        prospect_tax_id: quote?.prospect_tax_id ?? '',
        currency: quote?.currency ?? 'ARS',
        discount_percent: String(quote?.discount_percent ?? 0),
        valid_until: quote?.valid_until ?? defaultValidUntil,
        notes: quote?.notes ?? '',
        items: (quote?.items ?? []) as Item[],
        custom_pdf: null as File | null,
        remove_custom_pdf: false,
    });
    const [pdfError, setPdfError] = useState<string | null>(null);
    const { data, setData, errors } = form;
    // PDF propio vigente (no cuenta si el usuario marcó "Quitar").
    const currentPdf = quote?.custom_pdf_name && !data.remove_custom_pdf ? quote.custom_pdf_name : null;
    const err = (key: string) => (errors as Record<string, string | undefined>)[key];

    const totals = useMemo(() => {
        const monthly = data.items.filter((i) => i.frequency === 'mensual').reduce((s, i) => s + lineCents(i.quantity, i.unit_price), 0);
        const once = data.items.filter((i) => i.frequency !== 'mensual').reduce((s, i) => s + lineCents(i.quantity, i.unit_price), 0);
        const subtotal = monthly + once;
        const discount = Math.round((subtotal * Math.min(100, Math.max(0, toNumber(data.discount_percent)))) / 100);
        return { monthly, once, subtotal, discount, total: subtotal - discount };
    }, [data.items, data.discount_percent]);

    const money = (cents: number) => formatMoney(cents / 100, data.currency);
    const availableCatalog = catalog.filter((c) => c.currency === data.currency);

    const updateItem = (index: number, patch: Partial<Item>) =>
        setData('items', data.items.map((it, i) => (i === index ? { ...it, ...patch } : it)));

    const addFromCatalog = (id: string) => {
        const svc = catalog.find((c) => c.id === Number(id));
        if (!svc) return;
        setData('items', [...data.items, { service_id: svc.id, description: svc.name, quantity: 1, unit_price: svc.price, frequency: svc.frequency }]);
    };

    // Validación rápida en el navegador (el servidor vuelve a validar el contenido real del PDF).
    const pickPdf = (file: File | null | undefined) => {
        setPdfError(null);
        if (!file) return setData('custom_pdf', null);
        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            return setPdfError('El archivo tiene que ser un PDF.');
        }
        if (file.size > 25 * 1024 * 1024) return setPdfError('El PDF pesa más de 25 MB.');
        setData('custom_pdf', file);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            client_id: mode === 'client' ? d.client_id || null : null,
            ...(mode === 'client' ? { prospect_name: '', prospect_email: '', prospect_phone: '', prospect_tax_id: '' } : {}),
        }));
        if (editing) form.put(`/presupuestos/${quote.id}`);
        else form.post('/presupuestos');
    };

    return (
        <AppLayout title={editing ? `Editar ${quote.number}` : 'Nuevo presupuesto'}>
            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <div className="flex min-w-0 flex-col gap-6">
                    {/* Destinatario */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Para quién es</CardTitle>
                            <CardDescription>Elegí un cliente existente o cargá los datos de un prospecto.</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="inline-flex w-fit rounded-md border bg-muted p-0.5" role="tablist">
                                {([['client', 'Cliente existente', Users], ['prospect', 'Prospecto nuevo', UserPlus]] as const).map(([id, label, Icon]) => (
                                    <button
                                        key={id}
                                        type="button"
                                        role="tab"
                                        aria-selected={mode === id}
                                        onClick={() => setMode(id)}
                                        className={cn('inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors', mode === id && 'bg-card text-foreground shadow-sm')}
                                    >
                                        <Icon className="size-3.5" /> {label}
                                    </button>
                                ))}
                            </div>

                            {mode === 'client' ? (
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="client_id">Cliente</Label>
                                    <Select id="client_id" value={data.client_id} onChange={(e) => setData('client_id', e.target.value ? Number(e.target.value) : '')}>
                                        <option value="">Elegí un cliente…</option>
                                        {clients.map((c) => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </Select>
                                    <FieldError message={err('client_id') ?? err('prospect_name')} />
                                </div>
                            ) : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                                        <Label htmlFor="prospect_name">Nombre o razón social *</Label>
                                        <Input id="prospect_name" value={data.prospect_name} onChange={(e) => setData('prospect_name', e.target.value)} />
                                        <FieldError message={err('prospect_name')} />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="prospect_email">Email</Label>
                                        <Input id="prospect_email" type="email" value={data.prospect_email} onChange={(e) => setData('prospect_email', e.target.value)} />
                                        <FieldError message={err('prospect_email')} />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="prospect_phone">Teléfono / WhatsApp</Label>
                                        <Input id="prospect_phone" type="tel" value={data.prospect_phone} onChange={(e) => setData('prospect_phone', e.target.value)} />
                                        <FieldError message={err('prospect_phone')} />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="prospect_tax_id">CUIT</Label>
                                        <Input id="prospect_tax_id" inputMode="numeric" value={data.prospect_tax_id} onChange={(e) => setData('prospect_tax_id', e.target.value)} />
                                        <FieldError message={err('prospect_tax_id')} />
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Ítems */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Servicios</CardTitle>
                            <CardDescription>Armalo desde el catálogo o sumá ítems libres. Podés ajustar precio y cantidad.</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            {data.items.length === 0 && (
                                <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">Todavía no agregaste servicios.</p>
                            )}

                            {data.items.map((item, i) => (
                                <div key={i} className="rounded-lg border bg-muted/30 p-3.5">
                                    <div className="mb-3 flex flex-col gap-1.5">
                                        <Label htmlFor={`d-${i}`}>Descripción</Label>
                                        <Input id={`d-${i}`} value={item.description} onChange={(e) => updateItem(i, { description: e.target.value })} />
                                        <FieldError message={err(`items.${i}.description`)} />
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-[80px_1fr_1fr_auto] sm:items-end">
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor={`q-${i}`}>Cant.</Label>
                                            <Input id={`q-${i}`} type="number" min={1} max={999} value={item.quantity} onChange={(e) => updateItem(i, { quantity: e.target.value })} />
                                            <FieldError message={err(`items.${i}.quantity`)} />
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor={`p-${i}`}>Precio unit.</Label>
                                            <Input id={`p-${i}`} type="number" step="0.01" min="0" value={item.unit_price} onChange={(e) => updateItem(i, { unit_price: e.target.value })} />
                                            <FieldError message={err(`items.${i}.unit_price`)} />
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor={`f-${i}`}>Frecuencia</Label>
                                            <Select id={`f-${i}`} value={item.frequency} onChange={(e) => updateItem(i, { frequency: e.target.value })}>
                                                {Object.entries(frequencies).map(([v, l]) => (
                                                    <option key={v} value={v}>{l}</option>
                                                ))}
                                            </Select>
                                        </div>
                                        <Button type="button" variant="ghost" size="icon" aria-label={`Quitar ítem ${i + 1}`} onClick={() => setData('items', data.items.filter((_, n) => n !== i))}>
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    <p className="mt-2 text-right text-sm text-muted-foreground">
                                        Subtotal: <span className="font-medium text-foreground tabular-nums">{money(lineCents(item.quantity, item.unit_price))}</span>
                                    </p>
                                </div>
                            ))}
                            <FieldError message={err('items')} />

                            <div className="flex flex-wrap items-center gap-2">
                                <div className="w-64">
                                    <Select aria-label="Agregar desde el catálogo" value="" onChange={(e) => addFromCatalog(e.target.value)}>
                                        <option value="">{availableCatalog.length ? '＋ Agregar desde el catálogo…' : `Sin servicios en ${data.currency}`}</option>
                                        {availableCatalog.map((c) => (
                                            <option key={c.id} value={c.id}>{c.name} · {formatMoney(toNumber(c.price), c.currency)}</option>
                                        ))}
                                    </Select>
                                </div>
                                <Button type="button" variant="outline" onClick={() => setData('items', [...data.items, blankItem()])}>
                                    <PackagePlus /> Ítem libre
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Condiciones y notas</CardTitle>
                            <CardDescription>Se muestran al pie del PDF: forma de pago, alcance, plazos.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Textarea rows={4} aria-label="Condiciones y notas" value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Ej.: 50 % al aprobar y 50 % contra entrega. Incluye 2 rondas de ajustes." />
                            <FieldError message={err('notes')} />
                        </CardContent>
                    </Card>

                    {/* PDF propio (armado en Canva): reemplaza al que genera el sistema; los ítems y totales siguen siendo obligatorios */}
                    <Card>
                        <CardHeader>
                            <CardTitle>PDF propio (opcional)</CardTitle>
                            <CardDescription>
                                ¿Armaste el presupuesto en Canva? Subí el PDF y se usa ese archivo al descargar, compartir por WhatsApp y enviar por email. Igual cargá
                                los servicios de arriba: el sistema los necesita para convertir el presupuesto en cliente.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {currentPdf && !data.custom_pdf && (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/40 px-3.5 py-3">
                                    <p className="flex min-w-0 items-center gap-2.5 text-sm">
                                        <FileCheck2 className="size-4 shrink-0 text-success" />
                                        <span className="truncate font-medium">{currentPdf}</span>
                                    </p>
                                    <Button type="button" variant="ghost" size="sm" className="text-danger hover:bg-danger/10 hover:text-danger" onClick={() => setData('remove_custom_pdf', true)}>
                                        <Trash2 /> Quitar
                                    </Button>
                                </div>
                            )}
                            {quote?.custom_pdf_name && data.remove_custom_pdf && !data.custom_pdf && (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/40 bg-warning/10 px-3.5 py-3 text-sm">
                                    <p>Al guardar se quita el PDF propio y vuelve a usarse el generado por el sistema.</p>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setData('remove_custom_pdf', false)}>Deshacer</Button>
                                </div>
                            )}
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="custom_pdf">{currentPdf || data.custom_pdf ? 'Reemplazar con otro PDF' : 'Elegir el PDF'}</Label>
                                <input
                                    id="custom_pdf"
                                    type="file"
                                    accept="application/pdf,.pdf"
                                    className="block w-full cursor-pointer rounded-md border border-input bg-card text-sm file:mr-3 file:cursor-pointer file:rounded-l-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    onChange={(e) => pickPdf(e.target.files?.[0])}
                                />
                                <p className="text-xs text-muted-foreground">Solo PDF, hasta 25 MB. Se guarda en privado: solo se comparte con enlaces firmados.</p>
                                {data.custom_pdf && (
                                    <p className="flex items-center gap-2 text-sm">
                                        <Upload className="size-4 text-primary" />
                                        <span className="font-medium">{data.custom_pdf.name}</span>
                                        <button type="button" className="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground" onClick={() => setData('custom_pdf', null)}>
                                            descartar
                                        </button>
                                    </p>
                                )}
                                <FieldError message={pdfError ?? err('custom_pdf')} />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Resumen */}
                <aside className="lg:sticky lg:top-24 lg:self-start">
                    <Card>
                        <CardHeader>
                            <CardTitle>Resumen</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="currency">Moneda</Label>
                                    <Select id="currency" value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                                        {Object.keys(currencies).map((v) => (
                                            <option key={v} value={v}>{v}</option>
                                        ))}
                                    </Select>
                                    <FieldError message={err('currency')} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="discount">Descuento %</Label>
                                    <Input id="discount" type="number" step="0.01" min="0" max="100" value={data.discount_percent} onChange={(e) => setData('discount_percent', e.target.value)} />
                                    <FieldError message={err('discount_percent')} />
                                </div>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="valid_until">Válido hasta</Label>
                                <Input id="valid_until" type="date" value={data.valid_until ?? ''} onChange={(e) => setData('valid_until', e.target.value)} />
                                <FieldError message={err('valid_until')} />
                            </div>

                            <dl className="flex flex-col gap-2 border-t pt-4 text-sm">
                                <div className="flex justify-between"><dt className="text-muted-foreground">Mensual</dt><dd className="tabular-nums">{money(totals.monthly)}</dd></div>
                                <div className="flex justify-between"><dt className="text-muted-foreground">Pagos únicos</dt><dd className="tabular-nums">{money(totals.once)}</dd></div>
                                {totals.discount > 0 && (
                                    <div className="flex justify-between"><dt className="text-muted-foreground">Descuento</dt><dd className="tabular-nums text-success">− {money(totals.discount)}</dd></div>
                                )}
                                <div className="mt-1 flex items-baseline justify-between border-t pt-3">
                                    <dt className="font-medium">Total</dt>
                                    <dd className="font-display text-2xl font-extrabold tracking-tight tabular-nums" aria-live="polite">{money(totals.total)}</dd>
                                </div>
                            </dl>

                            <Button type="submit" disabled={form.processing} className="w-full">
                                {form.processing ? <Loader2 className="animate-spin" /> : <Plus />}
                                {editing ? 'Guardar cambios' : 'Crear presupuesto'}
                            </Button>
                            <Link href={editing ? `/presupuestos/${quote.id}` : '/presupuestos'} className="block">
                                <Button type="button" variant="outline" className="w-full">Cancelar</Button>
                            </Link>
                        </CardContent>
                    </Card>
                </aside>
            </form>
        </AppLayout>
    );
}
