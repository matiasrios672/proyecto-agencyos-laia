import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { ArrowLeft, Loader2, MousePointerClick, RotateCcw, Save, Send, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
    custom: boolean;
    enabled: boolean;
    customized: boolean;
    subject: string;
    body: string;
    defaultSubject: string;
    defaultBody: string;
    variables: { name: string; label: string }[];
    whatsappReady: boolean | null;
}

const motiveLabel: Record<string, string> = { sales: 'Comercial', billing: 'Cobranzas', support: 'Soporte' };

export default function MailTemplateEdit({ template: t }: { template: Template }) {
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [subject, setSubject] = useState(t.subject);
    const [body, setBody] = useState(t.body);
    const [saving, setSaving] = useState(false);
    const [sending, setSending] = useState(false);
    const [confirmReset, setConfirmReset] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [label, setLabel] = useState(t.label);
    const [description, setDescription] = useState(t.description);
    const [preview, setPreview] = useState<{ subject: string; html: string } | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const bodyRef = useRef<HTMLTextAreaElement>(null);

    // Tras guardar o restaurar, el servidor manda los valores vigentes.
    useEffect(() => {
        setSubject(t.subject);
        setBody(t.body);
        setLabel(t.label);
        setDescription(t.description);
    }, [t.subject, t.body, t.label, t.description]);

    // Vista previa en vivo (con datos de ejemplo), sin guardar.
    useEffect(() => {
        const timer = setTimeout(async () => {
            setPreviewing(true);
            try {
                const { data } = await axios.post<{ subject: string; html: string }>(`/admin/correos/${t.key}/vista-previa`, { subject, body });
                setPreview(data);
            } catch {
                /* se conserva la última vista previa válida */
            } finally {
                setPreviewing(false);
            }
        }, 450);
        return () => clearTimeout(timer);
    }, [subject, body, t.key]);

    const dirty = subject !== t.subject || body !== t.body || (t.custom && (label !== t.label || description !== t.description));
    const isDefault = subject === t.defaultSubject && body.trim() === t.defaultBody.trim();

    const insert = (text: string) => {
        const el = bodyRef.current;
        if (!el) return setBody((b) => b + text);
        const start = el.selectionStart;
        const end = el.selectionEnd;
        setBody(body.slice(0, start) + text + body.slice(end));
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(start + text.length, start + text.length);
        });
    };

    const save = () => {
        setSaving(true);
        router.put(`/admin/correos/${t.key}`, t.custom ? { subject, body, label, description } : { subject, body }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    const sendTest = () => {
        setSending(true);
        router.post(`/admin/correos/${t.key}/prueba`, { subject, body }, { preserveScroll: true, onFinish: () => setSending(false) });
    };

    const destroy = () => {
        setConfirmDelete(false);
        router.delete(`/admin/correos/${t.key}`);
    };

    const reset = () => {
        setConfirmReset(false);
        router.post(`/admin/correos/${t.key}/restaurar`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout title={t.label}>
            <div className="flex flex-col gap-5">
                <div className="flex flex-wrap items-center gap-3">
                    <Link href="/admin/correos">
                        <Button variant="ghost" size="sm">
                            <ArrowLeft /> Plantillas
                        </Button>
                    </Link>
                    <Badge variant={t.audience === 'cliente' ? 'primary' : 'default'}>{t.audience === 'cliente' ? 'Para clientes' : 'Para el equipo'}</Badge>
                    {t.motive && <Badge>WhatsApp {motiveLabel[t.motive]}</Badge>}
                    <div className="ml-auto flex items-center gap-2 text-sm text-muted-foreground">
                        <Switch
                            checked={t.enabled}
                            disabled={t.locked}
                            onCheckedChange={(c) => router.post(`/admin/correos/${t.key}/estado`, { enabled: c }, { preserveScroll: true })}
                            aria-label="Activar o desactivar este correo"
                        />
                        {t.locked ? 'Obligatorio (siempre activo)' : t.enabled ? 'Se envía' : 'Desactivado: no se envía'}
                    </div>
                </div>

                {t.custom ? (
                    <Card>
                        <CardContent className="grid gap-4 pt-5 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="label">Nombre de la plantilla</Label>
                                <Input id="label" value={label} onChange={(e) => setLabel(e.target.value)} maxLength={80} />
                                <FieldError message={errors.label} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="description">Descripción (solo para vos)</Label>
                                <Input id="description" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={200} />
                                <FieldError message={errors.description} />
                            </div>
                            <p className="text-xs text-muted-foreground sm:col-span-2">
                                Las plantillas propias se eligen al enviar un comunicado a clientes (Finanzas → Comunicados).
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <p className="text-sm text-muted-foreground">{t.description}</p>
                )}

                <div className="grid gap-5 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Contenido</CardTitle>
                            <CardDescription>Escribí en Markdown simple. Las {'{variables}'} se reemplazan con los datos reales de cada envío.</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="subject">Asunto</Label>
                                <Input id="subject" value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={200} />
                                <FieldError message={errors.subject} />
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="body">Cuerpo del correo</Label>
                                <textarea
                                    id="body"
                                    ref={bodyRef}
                                    value={body}
                                    onChange={(e) => setBody(e.target.value)}
                                    rows={16}
                                    spellCheck
                                    className="w-full rounded-md border border-input bg-card px-3 py-2 font-mono text-[13px] leading-relaxed shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                                <FieldError message={errors.body} />
                            </div>

                            <div className="flex flex-col gap-2">
                                <Label>Variables (clic para insertar donde está el cursor)</Label>
                                <div className="flex flex-wrap gap-1.5">
                                    {t.variables.map((v) => (
                                        <button
                                            key={v.name}
                                            type="button"
                                            title={v.label}
                                            onClick={() => insert(`{${v.name}}`)}
                                            className="rounded-md border border-input bg-muted px-2 py-1 font-mono text-xs transition-colors hover:bg-accent"
                                        >
                                            {`{${v.name}}`}
                                        </button>
                                    ))}
                                </div>
                                <div className="flex flex-wrap items-center gap-2 pt-1">
                                    <Button variant="outline" size="sm" onClick={() => insert('\n\n[[boton: Texto del botón | {url}]]\n\n')}>
                                        <MousePointerClick /> Insertar botón
                                    </Button>
                                    <span className="text-xs text-muted-foreground">
                                        Formato: <code>[[boton: Texto | https://...]]</code>
                                    </span>
                                </div>
                            </div>

                            {t.motive && t.whatsappReady === false && (
                                <p className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
                                    Este correo muestra un bloque de atención por WhatsApp ({motiveLabel[t.motive]}), pero todavía no hay un número cargado.{' '}
                                    <Link href="/admin/configuracion" className="underline">
                                        Cargalo en Configuración → Marca y contacto
                                    </Link>
                                    .
                                </p>
                            )}

                            <div className="flex flex-wrap gap-2">
                                <Button onClick={save} disabled={saving || !dirty}>
                                    {saving ? <Loader2 className="animate-spin" /> : <Save />} Guardar plantilla
                                </Button>
                                <Button variant="outline" onClick={sendTest} disabled={sending}>
                                    {sending ? <Loader2 className="animate-spin" /> : <Send />} Enviarme una prueba
                                </Button>
                                {t.custom ? (
                                    <Button variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>
                                        <Trash2 /> Eliminar plantilla
                                    </Button>
                                ) : (
                                    <Button variant="ghost" onClick={() => setConfirmReset(true)} disabled={isDefault && !t.customized}>
                                        <RotateCcw /> Restaurar original
                                    </Button>
                                )}
                            </div>
                            {dirty && <p className="text-xs text-muted-foreground">Hay cambios sin guardar. La prueba usa el texto que ves acá.</p>}
                        </CardContent>
                    </Card>

                    <Card className="overflow-hidden">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                Vista previa {previewing && <Loader2 className="size-4 animate-spin text-muted-foreground" />}
                            </CardTitle>
                            <CardDescription>
                                {preview ? (
                                    <>
                                        Asunto: <strong className="font-medium text-foreground">{preview.subject}</strong>
                                    </>
                                ) : (
                                    'Generando…'
                                )}{' '}
                                · con datos de ejemplo
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <iframe
                                title="Vista previa del correo"
                                sandbox=""
                                srcDoc={preview?.html ?? ''}
                                className="h-[780px] w-full border-t border-border bg-white"
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title="¿Eliminar esta plantilla?"
                description="Los comunicados que la usaban pasan a usar la plantilla estándar «Comunicado a clientes». No se puede deshacer."
                confirmLabel="Eliminar"
                destructive
                onConfirm={destroy}
            />
            <ConfirmDialog
                open={confirmReset}
                onOpenChange={setConfirmReset}
                title="¿Restaurar el texto original?"
                description="Se pierden los cambios de asunto y cuerpo de este correo. Si estaba activo o desactivado, sigue igual."
                confirmLabel="Restaurar"
                destructive
                onConfirm={reset}
            />
        </AppLayout>
    );
}
