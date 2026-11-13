import { Link, router, useForm } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Loader2, Megaphone, Plus, Save, Send } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select, Textarea } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout';
import type { Options, Paginated } from '@/types';

interface Row {
    id: number;
    subject: string;
    status: string;
    audience: string;
    recipients: number;
    sent_count: number;
    sent_at: string | null;
    created_at: string;
    creator: string | null;
}

interface Props {
    announcements: Paginated<Row>;
    audiences: Options;
    statusOptions: Options;
    clients: { id: number; name: string; email: string | null; status: string }[];
    can: { manage: boolean };
}

const fmt = (iso: string) => new Date(iso).toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' });

export default function Index({ announcements, audiences, statusOptions, clients, can }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <AppLayout title="Comunicados">
            <div className="mb-5 flex items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">Avisos por email a tus clientes: feriados, cambios de precios, novedades.</p>
                {can.manage && <Button onClick={() => setOpen(true)}><Plus /> Nuevo comunicado</Button>}
            </div>

            <Card className="overflow-hidden">
                {announcements.data.length === 0 ? (
                    <EmptyState icon={Megaphone} title="Todavía no enviaste comunicados" description="Redactá un aviso y elegí a quién llega: todos, por estado de cuenta o clientes puntuales." />
                ) : (
                    <ul className="divide-y">
                        {announcements.data.map((a) => (
                            <li key={a.id}>
                                <Link href={`/comunicados/${a.id}`} className="flex flex-wrap items-center gap-x-6 gap-y-2 p-4 transition-colors hover:bg-muted/40">
                                    <div className="min-w-56 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{a.subject}</span>
                                            <Badge variant={a.status === 'enviado' ? 'success' : 'default'}>{a.status === 'enviado' ? 'Enviado' : 'Borrador'}</Badge>
                                        </div>
                                        <p className="mt-0.5 text-sm text-muted-foreground">{a.audience}{a.creator && ` · ${a.creator}`}</p>
                                    </div>
                                    <div className="text-right text-sm">
                                        {a.status === 'enviado' ? (
                                            <>
                                                <p className="font-medium tabular-nums">{a.sent_count} de {a.recipients} recibieron</p>
                                                <p className="text-xs text-muted-foreground">{a.sent_at && fmt(a.sent_at)}</p>
                                            </>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">Creado el {fmt(a.created_at)}</p>
                                        )}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {announcements.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>{announcements.from}–{announcements.to} de {announcements.total}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!announcements.prev_page_url} onClick={() => announcements.prev_page_url && router.get(announcements.prev_page_url, {}, { preserveState: true })}><ChevronLeft /> Anterior</Button>
                        <Button variant="outline" size="sm" disabled={!announcements.next_page_url} onClick={() => announcements.next_page_url && router.get(announcements.next_page_url, {}, { preserveState: true })}>Siguiente <ChevronRight /></Button>
                    </div>
                </div>
            )}

            <ComposeDialog open={open} audiences={audiences} statusOptions={statusOptions} clients={clients} onClose={() => setOpen(false)} />
        </AppLayout>
    );
}

function ComposeDialog({ open, audiences, statusOptions, clients, onClose }: { open: boolean; audiences: Options; statusOptions: Options; clients: Props['clients']; onClose: () => void }) {
    const form = useForm({ subject: '', body: '', audience: 'todos', status_filter: 'deuda', client_ids: [] as number[], send: false });
    const err = (k: string) => (form.errors as Record<string, string | undefined>)[k];

    useEffect(() => { if (open) { form.clearErrors(); form.reset(); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [open]);

    // Cuántos clientes recibirían el comunicado con la audiencia elegida (los que no tienen email no reciben nada).
    const audienceClients = form.data.audience === 'estado'
        ? clients.filter((c) => c.status === form.data.status_filter)
        : form.data.audience === 'clientes' ? clients.filter((c) => form.data.client_ids.includes(c.id)) : clients;
    const withEmail = audienceClients.filter((c) => c.email).length;

    const submit = (send: boolean) => (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            subject: d.subject, body: d.body, audience: d.audience, send,
            ...(d.audience === 'estado' ? { status_filter: d.status_filter } : {}),
            ...(d.audience === 'clientes' ? { client_ids: d.client_ids } : {}),
        }));
        form.post('/comunicados', { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <form onSubmit={submit(true)} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Nuevo comunicado</DialogTitle>
                        <DialogDescription>Se envía por email a cada cliente con su nombre. Un comunicado enviado queda como registro.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="a-subject">Asunto *</Label>
                            <Input id="a-subject" required value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                            <FieldError message={form.errors.subject} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="a-body">Mensaje *</Label>
                            <Textarea id="a-body" required rows={5} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                            <FieldError message={form.errors.body} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="a-aud">Audiencia</Label>
                                <Select id="a-aud" value={form.data.audience} onChange={(e) => form.setData('audience', e.target.value)}>
                                    {Object.entries(audiences).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </Select>
                                <FieldError message={form.errors.audience} />
                            </div>
                            {form.data.audience === 'estado' && (
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="a-status">Estado de cuenta</Label>
                                    <Select id="a-status" value={form.data.status_filter} onChange={(e) => form.setData('status_filter', e.target.value)}>
                                        {Object.entries(statusOptions).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </Select>
                                    <FieldError message={err('status_filter')} />
                                </div>
                            )}
                        </div>
                        {form.data.audience === 'clientes' && (
                            <fieldset className="flex flex-col gap-1">
                                <legend className="mb-1 text-sm font-medium">Clientes</legend>
                                <div className="max-h-44 overflow-y-auto rounded-md border">
                                    {clients.map((c) => (
                                        <label key={c.id} className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm transition-colors hover:bg-muted">
                                            <input type="checkbox" className="size-4 accent-[var(--primary)]" checked={form.data.client_ids.includes(c.id)}
                                                onChange={() => form.setData('client_ids', form.data.client_ids.includes(c.id) ? form.data.client_ids.filter((x) => x !== c.id) : [...form.data.client_ids, c.id])} />
                                            <span className="flex-1">{c.name}</span>
                                            {!c.email && <Badge>Sin email</Badge>}
                                        </label>
                                    ))}
                                </div>
                                <FieldError message={err('client_ids')} />
                            </fieldset>
                        )}
                        <p className="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground" aria-live="polite">
                            Llegará a <strong className="text-foreground">{withEmail}</strong> {withEmail === 1 ? 'cliente' : 'clientes'}
                            {audienceClients.length > withEmail && <> ({audienceClients.length - withEmail} sin email cargado no lo {audienceClients.length - withEmail === 1 ? 'recibe' : 'reciben'})</>}.
                        </p>
                        <FieldError message={err('announcement')} />
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="button" variant="secondary" disabled={form.processing} onClick={submit(false)}><Save /> Guardar borrador</Button>
                        <Button type="submit" disabled={form.processing || withEmail === 0}>{form.processing ? <Loader2 className="animate-spin" /> : <Send />} Enviar ahora</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
