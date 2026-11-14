import { Link, router, useForm } from '@inertiajs/react';
import { Lock, MessageCircle, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout';

interface Template {
    key: string;
    label: string;
    description: string;
    audience: 'cliente' | 'equipo';
    motive: 'sales' | 'billing' | 'support' | null;
    locked: boolean;
    custom?: boolean;
    enabled: boolean;
    customized: boolean;
}

const motiveLabel: Record<string, string> = { sales: 'Comercial', billing: 'Cobranzas', support: 'Soporte' };

export default function MailTemplates({ groups }: { groups: Record<string, Template[]> }) {
    const [busy, setBusy] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const form = useForm({ label: '', description: '' });

    const create = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/correos', { onSuccess: () => setCreating(false) });
    };

    const toggle = (t: Template, enabled: boolean) => {
        setBusy(t.key);
        router.post(`/admin/correos/${t.key}/estado`, { enabled }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    const total = Object.values(groups).flat();
    const active = total.filter((t) => t.enabled).length;

    return (
        <AppLayout title="Plantillas de correo">
            <div className="flex flex-col gap-6">
                <p className="text-sm text-muted-foreground">
                    Elegí qué correos envía el sistema y cómo se ven. Todos usan el diseño con el logo y los datos de contacto de Laia
                    (se editan en <Link href="/admin/configuracion" className="underline">Configuración → Marca y contacto</Link>).{' '}
                    <strong className="font-medium text-foreground">
                        {active} de {total.length}
                    </strong>{' '}
                    correos activos.
                </p>

                <div>
                    <Button onClick={() => { form.reset(); form.clearErrors(); setCreating(true); }}>
                        <Plus /> Nueva plantilla
                    </Button>
                </div>

                {!groups['Personalizadas'] && (
                    <p className="text-sm text-muted-foreground">
                        Con «Nueva plantilla» creás tus propios diseños de correo (por ejemplo una promo o un aviso de feriados) y los elegís al enviar un comunicado.
                    </p>
                )}

                {Object.entries(groups).map(([group, templates]) => (
                    <Card key={group}>
                        <CardHeader>
                            <CardTitle>{group}</CardTitle>
                            <CardDescription>
                                {templates.length} {templates.length === 1 ? 'correo' : 'correos'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="divide-y divide-border p-0">
                            {templates.map((t) => (
                                <div key={t.key} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{t.label}</span>
                                            <Badge variant={t.audience === 'cliente' ? 'primary' : 'default'}>
                                                {t.audience === 'cliente' ? 'Para clientes' : 'Para el equipo'}
                                            </Badge>
                                            {t.locked && (
                                                <Badge variant="warning">
                                                    <Lock className="size-3" /> Obligatorio
                                                </Badge>
                                            )}
                                            {t.customized && <Badge variant="success">Personalizado</Badge>}
                                            {t.motive && (
                                                <Badge>
                                                    <MessageCircle className="size-3" /> WhatsApp {motiveLabel[t.motive]}
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-sm text-muted-foreground">{t.description}</p>
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <Switch
                                                checked={t.enabled}
                                                disabled={t.locked || busy === t.key}
                                                onCheckedChange={(c) => toggle(t, c)}
                                                aria-label={`${t.enabled ? 'Desactivar' : 'Activar'} ${t.label}`}
                                            />
                                            {t.enabled ? 'Activo' : 'Desactivado'}
                                        </label>
                                        <Link href={`/admin/correos/${t.key}`}>
                                            <Button variant="outline" size="sm">
                                                <Pencil /> Editar
                                            </Button>
                                        </Link>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ))}
            </div>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="max-w-md">
                    <form onSubmit={create} className="flex min-h-0 flex-col">
                        <DialogHeader>
                            <DialogTitle>Nueva plantilla de correo</DialogTitle>
                            <DialogDescription>Arranca con el diseño de Laia y un texto base; después lo editás y ves cómo queda.</DialogDescription>
                        </DialogHeader>
                        <DialogBody>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="t-label">Nombre *</Label>
                                <Input id="t-label" required maxLength={80} value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="Ej: Promo de verano" />
                                <FieldError message={form.errors.label} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="t-desc">Descripción</Label>
                                <Input id="t-desc" maxLength={200} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Para qué la vas a usar" />
                                <FieldError message={form.errors.description} />
                            </div>
                        </DialogBody>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>Cancelar</Button>
                            <Button type="submit" disabled={form.processing}>Crear y editar</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
