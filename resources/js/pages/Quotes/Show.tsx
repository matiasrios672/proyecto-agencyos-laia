import { Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, Download, FileCheck2, Mail, MessageCircle, Pencil, Rocket, Trash2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { LaiaLogo } from '@/components/brand/LaiaLogo';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { ConvertDialog } from '@/components/quotes/ConvertDialog';
import { QuoteStatusBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';
import type { Options, PersonRef } from '@/types';

interface Props {
    quote: {
        id: number;
        number: string;
        status: string;
        currency: string;
        recipient: { name: string; is_prospect: boolean; client_id: number | null; email: string | null; phone: string | null; tax_id: string | null };
        items: { id: number; description: string; quantity: number; frequency: string; frequency_label: string; unit_price_label: string; total_label: string; total: string }[];
        monthly_label: string;
        once_label: string;
        discount_percent: number;
        discount_label: string;
        total_label: string;
        valid_until: string | null;
        notes: string | null;
        /** Nombre del PDF propio (Canva) si lo tiene; reemplaza al generado. */
        custom_pdf: string | null;
        created_at: string;
        sent_at: string | null;
        approved_at: string | null;
        rejected_at: string | null;
        creator: string | null;
        converted_client: { id: number; name: string } | null;
    };
    share: { whatsapp_url: string | null };
    can: { update: boolean; resolve: boolean; convert: boolean; delete: boolean };
    expired: boolean;
    convert: { assignable: PersonRef[]; splitRules: Options };
}

const fmt = (iso: string, withTime = false) =>
    new Date(iso).toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: 'numeric', ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}) });

type Dialog = 'email' | 'reject' | 'delete' | 'convert' | null;

export default function Show({ quote, share, can, expired, convert }: Props) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const [busy, setBusy] = useState(false);
    const done = () => { setBusy(false); setDialog(null); };
    const post = (path: string, data: Record<string, string> = {}) => {
        setBusy(true);
        router.post(`/presupuestos/${quote.id}/${path}`, data, { preserveScroll: true, onFinish: done });
    };

    return (
        <AppLayout title={quote.number}>
            <Link href="/presupuestos" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-4" /> Presupuestos
            </Link>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h2 className="font-display text-2xl font-extrabold tracking-tight tabular-nums">{quote.number}</h2>
                        <QuoteStatusBadge status={quote.status} />
                        {quote.custom_pdf && (
                            <Badge variant="primary" title={`Se usa ${quote.custom_pdf} en lugar del PDF generado`}>
                                <FileCheck2 className="size-3" /> PDF propio
                            </Badge>
                        )}
                    </div>
                    <p className="mt-1 text-muted-foreground">
                        Para <span className="font-medium text-foreground">{quote.recipient.name}</span>
                        {quote.recipient.is_prospect && <Badge className="ml-2">Prospecto</Badge>}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <a href={`/presupuestos/${quote.id}/pdf`} target="_blank" rel="noreferrer noopener">
                        <Button variant="outline" size="sm"><Download /> PDF</Button>
                    </a>
                    {can.resolve && (
                        <>
                            <Button variant="outline" size="sm" onClick={() => setDialog('email')}><Mail /> Email</Button>
                            {share.whatsapp_url && (
                                <a href={share.whatsapp_url} target="_blank" rel="noreferrer noopener" onClick={() => post('enviar', { channel: 'whatsapp' })}>
                                    <Button variant="outline" size="sm"><MessageCircle /> WhatsApp</Button>
                                </a>
                            )}
                        </>
                    )}
                    {can.update && (
                        <Link href={`/presupuestos/${quote.id}/edit`}><Button variant="outline" size="sm"><Pencil /> Editar</Button></Link>
                    )}
                    {can.convert && (
                        <Button size="sm" onClick={() => setDialog('convert')}><Rocket /> Aprobar y convertir</Button>
                    )}
                </div>
            </div>

            {expired && (
                <div role="status" className="mb-5 flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/10 p-4 text-sm">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
                    <p>Este presupuesto <strong>venció</strong>{quote.valid_until && <> el {fmt(quote.valid_until + 'T00:00:00')}</>}. Extendé la fecha de validez desde “Editar” para poder enviarlo o aprobarlo.</p>
                </div>
            )}
            {quote.converted_client && (
                <div role="status" className="mb-5 flex items-start gap-3 rounded-lg border border-success/30 bg-success/10 p-4 text-sm">
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" />
                    <p>
                        Aprobado el {quote.approved_at && fmt(quote.approved_at)}. Es cliente activo:{' '}
                        <Link href={`/clientes/${quote.converted_client.id}`} className="font-medium text-primary hover:underline">{quote.converted_client.name}</Link>.
                    </p>
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
                <Card className="overflow-hidden">
                    <div className="brand-gradient flex items-center justify-between px-6 py-5 text-white">
                        <LaiaLogo tone="light" className="h-14" />
                        <p className="text-sm text-white/70">Presupuesto <span className="font-medium text-white tabular-nums">{quote.number}</span></p>
                    </div>

                    <table className="hidden w-full text-sm sm:table">
                        <thead>
                            <tr className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                <th className="px-6 py-2.5 font-medium">Servicio</th>
                                <th className="px-3 py-2.5 font-medium">Frecuencia</th>
                                <th className="px-3 py-2.5 text-right font-medium">Cant.</th>
                                <th className="px-3 py-2.5 text-right font-medium">Precio unit.</th>
                                <th className="px-6 py-2.5 text-right font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {quote.items.map((i) => (
                                <tr key={i.id} className="border-b last:border-0">
                                    <td className="px-6 py-3 font-medium">{i.description}</td>
                                    <td className="px-3 py-3 text-muted-foreground">{i.frequency_label}</td>
                                    <td className="px-3 py-3 text-right tabular-nums">{i.quantity}</td>
                                    <td className="px-3 py-3 text-right tabular-nums">{i.unit_price_label}</td>
                                    <td className="px-6 py-3 text-right font-medium tabular-nums">{i.total_label}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <ul className="divide-y sm:hidden">
                        {quote.items.map((i) => (
                            <li key={i.id} className="flex flex-col gap-1 p-4">
                                <span className="font-medium">{i.description}</span>
                                <span className="flex justify-between text-sm text-muted-foreground">
                                    <span>{i.quantity} × {i.unit_price_label} · {i.frequency_label}</span>
                                    <span className="font-medium text-foreground tabular-nums">{i.total_label}</span>
                                </span>
                            </li>
                        ))}
                    </ul>

                    <dl className="ml-auto flex w-full max-w-xs flex-col gap-2 px-6 py-5 text-sm">
                        <div className="flex justify-between"><dt className="text-muted-foreground">Servicios mensuales</dt><dd className="tabular-nums">{quote.monthly_label}</dd></div>
                        <div className="flex justify-between"><dt className="text-muted-foreground">Pagos únicos</dt><dd className="tabular-nums">{quote.once_label}</dd></div>
                        {quote.discount_percent > 0 && (
                            <div className="flex justify-between"><dt className="text-muted-foreground">Descuento ({quote.discount_percent} %)</dt><dd className="tabular-nums text-success">− {quote.discount_label}</dd></div>
                        )}
                        <div className="mt-1 flex items-baseline justify-between border-t pt-3">
                            <dt className="font-medium">Total</dt>
                            <dd className="font-display text-2xl font-extrabold tracking-tight tabular-nums">{quote.total_label}</dd>
                        </div>
                    </dl>

                    {quote.notes && (
                        <div className="mx-6 mb-6 rounded-md border-l-[3px] border-peach bg-muted/50 px-4 py-3">
                            <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">Condiciones y notas</p>
                            <p className="whitespace-pre-wrap text-sm">{quote.notes}</p>
                        </div>
                    )}
                </Card>

                <div className="flex flex-col gap-6">
                    <Card>
                        <CardHeader><CardTitle>Detalle</CardTitle></CardHeader>
                        <CardContent>
                            <dl className="flex flex-col gap-3 text-sm">
                                <div><dt className="text-xs text-muted-foreground">Destinatario</dt><dd>{quote.recipient.client_id ? <Link className="text-primary hover:underline" href={`/clientes/${quote.recipient.client_id}`}>{quote.recipient.name}</Link> : quote.recipient.name}</dd></div>
                                {quote.recipient.tax_id && <div><dt className="text-xs text-muted-foreground">CUIT</dt><dd className="tabular-nums">{quote.recipient.tax_id}</dd></div>}
                                <div><dt className="text-xs text-muted-foreground">Email</dt><dd>{quote.recipient.email ?? <span className="text-muted-foreground">Sin email</span>}</dd></div>
                                <div><dt className="text-xs text-muted-foreground">Teléfono</dt><dd>{quote.recipient.phone ?? <span className="text-muted-foreground">—</span>}</dd></div>
                                <div><dt className="text-xs text-muted-foreground">Válido hasta</dt><dd>{quote.valid_until ? fmt(quote.valid_until + 'T00:00:00') : 'Sin vencimiento'}</dd></div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Historial</CardTitle></CardHeader>
                        <CardContent>
                            <ol className="flex flex-col gap-3 text-sm">
                                <li><span className="font-medium">Creado</span><span className="block text-xs text-muted-foreground">{fmt(quote.created_at)}{quote.creator && ` · ${quote.creator}`}</span></li>
                                {quote.sent_at && <li><span className="font-medium">Enviado</span><span className="block text-xs text-muted-foreground">{fmt(quote.sent_at, true)}</span></li>}
                                {quote.approved_at && <li><span className="font-medium text-success">Aprobado</span><span className="block text-xs text-muted-foreground">{fmt(quote.approved_at, true)}</span></li>}
                                {quote.rejected_at && <li><span className="font-medium text-danger">Rechazado</span><span className="block text-xs text-muted-foreground">{fmt(quote.rejected_at, true)}</span></li>}
                            </ol>
                            {(can.resolve || can.delete) && (
                                <div className="mt-5 flex flex-col gap-2 border-t pt-4">
                                    {can.resolve && (
                                        <Button variant="outline" size="sm" onClick={() => setDialog('reject')}><XCircle /> Marcar como rechazado</Button>
                                    )}
                                    {can.delete && (
                                        <Button variant="ghost" size="sm" className="text-danger hover:bg-danger/10 hover:text-danger" onClick={() => setDialog('delete')}><Trash2 /> Eliminar borrador</Button>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                open={dialog === 'email'}
                onOpenChange={(o) => !o && setDialog(null)}
                title="Enviar por email"
                description={quote.recipient.email ? `Se enviará el presupuesto ${quote.number} en PDF a ${quote.recipient.email}.` : 'El destinatario no tiene email cargado. Editá el presupuesto o la ficha del cliente.'}
                confirmLabel="Enviar"
                busy={busy || !quote.recipient.email}
                onConfirm={() => post('enviar', { channel: 'email' })}
            />
            <ConfirmDialog
                open={dialog === 'reject'}
                onOpenChange={(o) => !o && setDialog(null)}
                title="¿Marcar como rechazado?"
                description="El presupuesto queda cerrado y ya no se podrá editar ni aprobar."
                confirmLabel="Marcar rechazado"
                destructive
                busy={busy}
                onConfirm={() => post('rechazar')}
            />
            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={(o) => !o && setDialog(null)}
                title="¿Eliminar este borrador?"
                description={`Se eliminará ${quote.number}. El número no se reutiliza.`}
                confirmLabel="Eliminar"
                destructive
                busy={busy}
                onConfirm={() => { setBusy(true); router.delete(`/presupuestos/${quote.id}`, { onFinish: done }); }}
            />
            <ConvertDialog
                open={dialog === 'convert'}
                onOpenChange={(o) => !o && setDialog(null)}
                quoteId={quote.id}
                recipientName={quote.recipient.name}
                isProspect={quote.recipient.is_prospect}
                items={quote.items}
                assignable={convert.assignable}
                splitRules={convert.splitRules}
            />
        </AppLayout>
    );
}
