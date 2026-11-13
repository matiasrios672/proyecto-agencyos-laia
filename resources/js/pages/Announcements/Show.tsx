import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';

interface Props {
    announcement: { id: number; subject: string; body: string; status: string; audience: string; sent_at: string | null; creator: string | null };
    recipients: { id: number; client_id: number; client: string; email: string | null; status: string }[];
    can: { manage: boolean };
}

export default function Show({ announcement, recipients, can }: Props) {
    const [dialog, setDialog] = useState<'send' | 'delete' | null>(null);
    const [busy, setBusy] = useState(false);
    const sent = announcement.status === 'enviado';
    const done = () => { setBusy(false); setDialog(null); };

    return (
        <AppLayout title="Comunicado">
            <Link href="/comunicados" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-4" /> Comunicados
            </Link>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h2 className="font-display text-2xl font-extrabold tracking-tight">{announcement.subject}</h2>
                        <Badge variant={sent ? 'success' : 'default'}>{sent ? 'Enviado' : 'Borrador'}</Badge>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {announcement.audience}{announcement.creator && ` · ${announcement.creator}`}
                        {announcement.sent_at && ` · enviado el ${new Date(announcement.sent_at).toLocaleString('es-AR', { dateStyle: 'long', timeStyle: 'short' })}`}
                    </p>
                </div>
                {can.manage && !sent && (
                    <div className="flex gap-2">
                        <Button variant="ghost" size="sm" className="text-danger hover:bg-danger/10 hover:text-danger" onClick={() => setDialog('delete')}><Trash2 /> Eliminar</Button>
                        <Button size="sm" onClick={() => setDialog('send')}><Send /> Enviar ahora</Button>
                    </div>
                )}
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <Card>
                    <CardHeader><CardTitle>Mensaje</CardTitle></CardHeader>
                    <CardContent><p className="whitespace-pre-wrap text-sm leading-relaxed">{announcement.body}</p></CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Destinatarios {sent && `(${recipients.length})`}</CardTitle></CardHeader>
                    <CardContent className="p-0">
                        {!sent ? (
                            <p className="p-5 text-sm text-muted-foreground">Los destinatarios se definen al enviar, según la audiencia elegida.</p>
                        ) : (
                            <ul className="max-h-96 divide-y overflow-y-auto">
                                {recipients.map((r) => (
                                    <li key={r.id} className="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                                        <span className="min-w-0">
                                            <Link href={`/clientes/${r.client_id}`} className="block truncate font-medium hover:underline">{r.client}</Link>
                                            <span className="block truncate text-xs text-muted-foreground">{r.email ?? 'Sin email'}</span>
                                        </span>
                                        <Badge variant={r.status === 'enviado' ? 'success' : 'warning'}>{r.status === 'enviado' ? 'Enviado' : 'Sin email'}</Badge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <ConfirmDialog
                open={dialog === 'send'}
                onOpenChange={(o) => !o && setDialog(null)}
                title="¿Enviar el comunicado?"
                description="Se envía por email a toda la audiencia elegida. No se puede deshacer."
                confirmLabel="Enviar"
                busy={busy}
                onConfirm={() => { setBusy(true); router.post(`/comunicados/${announcement.id}/enviar`, {}, { preserveScroll: true, onFinish: done }); }}
            />
            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={(o) => !o && setDialog(null)}
                title="¿Eliminar este borrador?"
                description="Se elimina el borrador. Los comunicados ya enviados no se pueden eliminar."
                confirmLabel="Eliminar"
                destructive
                busy={busy}
                onConfirm={() => { setBusy(true); router.delete(`/comunicados/${announcement.id}`, { onFinish: done }); }}
            />
        </AppLayout>
    );
}
