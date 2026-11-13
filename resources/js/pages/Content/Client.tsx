import { Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarPlus, FileStack, Upload } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { GridRow } from '@/components/content/GridRow';
import { PdfViewerDialog } from '@/components/content/PdfViewerDialog';
import type { ContentGridItem, CurrentMonth } from '@/components/content/types';
import { UploadGridDialog, type UploadTarget } from '@/components/content/UploadGridDialog';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';
import type { Options } from '@/types';

interface Props {
    client: { id: number; name: string; whatsapp: string | null };
    grids: ContentGridItem[];
    statusOptions: Options;
    currentMonth: CurrentMonth;
    hasCurrent: boolean;
    can: { manage: boolean };
}

export default function Client({ client, grids, statusOptions, currentMonth, hasCurrent, can }: Props) {
    const [viewing, setViewing] = useState<ContentGridItem | null>(null);
    const [uploading, setUploading] = useState(false);
    const [target, setTarget] = useState<UploadTarget | null>(null);
    const [deleting, setDeleting] = useState<ContentGridItem | null>(null);
    const [busy, setBusy] = useState(false);

    const openUpload = (t: UploadTarget | null = null) => {
        setTarget(t);
        setUploading(true);
    };

    return (
        <AppLayout title={`Contenido · ${client.name}`}>
            <Link href={`/clientes/${client.id}`} className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-4" /> {client.name}
            </Link>

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-display text-2xl font-extrabold tracking-tight">Grillas de contenido</h2>
                    <p className="text-sm text-muted-foreground">Un PDF por mes, armado en Canva. Se revisa, se envía al cliente y se aprueba desde acá.</p>
                </div>
                {can.manage && (
                    <Button onClick={() => openUpload()}>
                        <Upload /> Subir grilla
                    </Button>
                )}
            </div>

            {!hasCurrent && (
                <div role="status" className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/30 bg-warning/10 p-4 text-sm">
                    <p className="flex items-center gap-2.5">
                        <CalendarPlus className="size-4 shrink-0 text-warning" />
                        Todavía no hay grilla de <strong>{currentMonth.label}</strong> para {client.name}.
                    </p>
                    {can.manage && (
                        <Button size="sm" variant="outline" onClick={() => openUpload({ month: currentMonth.value })}>
                            <Upload /> Subir la de {currentMonth.label}
                        </Button>
                    )}
                </div>
            )}

            <Card className="overflow-hidden">
                {grids.length === 0 ? (
                    <EmptyState
                        icon={FileStack}
                        title="Todavía no hay grillas"
                        description={can.manage ? 'Subí el PDF del mes que armaste en Canva para empezar el seguimiento.' : 'Cuando se suba la grilla del mes, la vas a ver acá.'}
                    />
                ) : (
                    <ul className="divide-y">
                        {grids.map((g) => (
                            <GridRow
                                key={g.id}
                                grid={g}
                                statusOptions={statusOptions}
                                onView={setViewing}
                                onReplace={(grid) => openUpload({ clientId: grid.client.id, month: grid.month, title: grid.title, notes: grid.notes, replacing: true })}
                                onDelete={setDeleting}
                            />
                        ))}
                    </ul>
                )}
            </Card>

            <PdfViewerDialog grid={viewing} onClose={() => setViewing(null)} />
            <UploadGridDialog open={uploading} onClose={() => setUploading(false)} fixedClient={client} defaultMonth={currentMonth.value} target={target} />
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="¿Eliminar esta grilla?"
                description={`Se elimina la grilla de ${deleting?.month_label ?? ''} y su PDF. Los enlaces que ya compartiste dejan de funcionar. No se puede deshacer.`}
                confirmLabel="Eliminar"
                destructive
                busy={busy}
                onConfirm={() => {
                    if (!deleting) return;
                    setBusy(true);
                    router.delete(`/contenido/${deleting.id}`, { preserveScroll: true, onFinish: () => { setBusy(false); setDeleting(null); } });
                }}
            />
        </AppLayout>
    );
}
