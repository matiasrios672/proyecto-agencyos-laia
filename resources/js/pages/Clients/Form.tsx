import { Link, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select, Textarea } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout';
import type { Options } from '@/types';

interface ClientForm {
    id: number;
    business_name: string;
    trade_name: string | null;
    tax_id: string | null;
    tax_condition: string | null;
    contact_name: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    website: string | null;
    address: string | null;
    city: string | null;
    notes: string | null;
    laiatime_instance_id: string | null;
    is_active: boolean;
}

interface Props {
    client: ClientForm | null;
    taxConditions: Options;
}

function Field({ label, htmlFor, error, hint, children, className }: { label: string; htmlFor: string; error?: string; hint?: string; children: ReactNode; className?: string }) {
    return (
        <div className={`flex flex-col gap-1.5 ${className ?? ''}`}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && !error && <p className="text-xs text-muted-foreground">{hint}</p>}
            <FieldError message={error} />
        </div>
    );
}

export default function Form({ client, taxConditions }: Props) {
    const editing = client !== null;
    const { data, setData, post, put, processing, errors } = useForm({
        business_name: client?.business_name ?? '',
        trade_name: client?.trade_name ?? '',
        tax_id: client?.tax_id ?? '',
        tax_condition: client?.tax_condition ?? '',
        contact_name: client?.contact_name ?? '',
        email: client?.email ?? '',
        phone: client?.phone ?? '',
        whatsapp: client?.whatsapp ?? '',
        website: client?.website ?? '',
        address: client?.address ?? '',
        city: client?.city ?? '',
        notes: client?.notes ?? '',
        laiatime_instance_id: client?.laiatime_instance_id ?? '',
        is_active: client?.is_active ?? true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (editing) put(`/clientes/${client.id}`);
        else post('/clientes');
    };

    const text = (key: keyof typeof data) => ({
        id: key,
        value: (data[key] as string) ?? '',
        onChange: (e: { target: { value: string } }) => setData(key, e.target.value as never),
        'aria-invalid': !!errors[key as keyof typeof errors],
    });

    return (
        <AppLayout title={editing ? 'Editar cliente' : 'Nuevo cliente'}>
            <form onSubmit={submit} className="mx-auto flex max-w-3xl flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Datos fiscales</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <Field label="Razón social *" htmlFor="business_name" error={errors.business_name} className="sm:col-span-2">
                            <Input {...text('business_name')} required autoFocus />
                        </Field>
                        <Field label="Nombre comercial" htmlFor="trade_name" error={errors.trade_name}>
                            <Input {...text('trade_name')} />
                        </Field>
                        <Field label="CUIT" htmlFor="tax_id" error={errors.tax_id} hint="Con o sin guiones. Se valida el dígito verificador.">
                            <Input {...text('tax_id')} inputMode="numeric" placeholder="30-12345678-9" />
                        </Field>
                        <Field label="Condición frente al IVA" htmlFor="tax_condition" error={errors.tax_condition}>
                            <Select id="tax_condition" value={data.tax_condition} onChange={(e) => setData('tax_condition', e.target.value)}>
                                <option value="">Sin especificar</option>
                                {Object.entries(taxConditions).map(([v, l]) => (
                                    <option key={v} value={v}>
                                        {l}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Contacto y ubicación</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <Field label="Persona de contacto" htmlFor="contact_name" error={errors.contact_name}>
                            <Input {...text('contact_name')} />
                        </Field>
                        <Field label="Email" htmlFor="email" error={errors.email}>
                            <Input {...text('email')} type="email" />
                        </Field>
                        <Field label="Teléfono" htmlFor="phone" error={errors.phone}>
                            <Input {...text('phone')} type="tel" />
                        </Field>
                        <Field label="WhatsApp" htmlFor="whatsapp" error={errors.whatsapp} hint="Con código de país, sin + ni espacios (ej. 5491122334455).">
                            <Input {...text('whatsapp')} inputMode="numeric" />
                        </Field>
                        <Field label="Sitio web" htmlFor="website" error={errors.website}>
                            <Input {...text('website')} placeholder="https://" />
                        </Field>
                        <Field label="Ciudad" htmlFor="city" error={errors.city}>
                            <Input {...text('city')} />
                        </Field>
                        <Field label="Dirección" htmlFor="address" error={errors.address} className="sm:col-span-2">
                            <Input {...text('address')} />
                        </Field>
                    </CardContent>
                </Card>

                {editing && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Estado</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <Field label="ID de instancia en LaiaTime" htmlFor="laiatime_instance_id" error={errors.laiatime_instance_id}>
                                <Input {...text('laiatime_instance_id')} />
                            </Field>
                            <div className="flex items-center gap-3">
                                <Switch id="is_active" checked={data.is_active} onCheckedChange={(c) => setData('is_active', c)} />
                                <Label htmlFor="is_active">Cliente activo</Label>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Notas internas</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Field label="Notas" htmlFor="notes" error={errors.notes}>
                            <Textarea {...text('notes')} rows={4} placeholder="Acuerdos, preferencias, contexto del cliente…" />
                        </Field>
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Link href={editing ? `/clientes/${client.id}` : '/clientes'}>
                        <Button variant="outline">Cancelar</Button>
                    </Link>
                    <Button type="submit" disabled={processing}>
                        {processing && <Loader2 className="animate-spin" />}
                        {editing ? 'Guardar cambios' : 'Crear cliente'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
