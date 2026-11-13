import { router } from '@inertiajs/react';
import { CheckCircle2, ChevronLeft, ChevronRight, FileStack, Hourglass, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { GridRow } from '@/components/content/GridRow';
import { PdfViewerDialog } from '@/components/content/PdfViewerDialog';
import type { ContentGridItem, CurrentMonth } from '@/components/content/types';
import { UploadGridDialog, type UploadTarget } from '@/components/content/UploadGridDialog';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout';
import type { Options, Paginated } from '@/types';

interface Props {
    grids: Paginated<ContentGridItem>;
    filters: { client: string | number; status: string; month: string };
    statusOptions: Options;
    clients: { id: number; name: string }[];
    missing: { id: number; name: string }[];
    currentMonth: CurrentMonth;
    can: { manage: boolean };
}

export default function Index({ grids, filters, statusOptions, clients, missing, currentMonth, can }: Props) {
    const [viewing, setViewing] = useState<ContentGridItem | null>(null);
    const [uploading, setUploading] = useState(false);
    const [target, setTarget] = useState<UploadTarget | null>(null);
    const [deleting, setDeleting] = useState<ContentGridItem | null>(null);
    const [busy, setBusy] = useState(false);

    const filtered = filters.client !== '' || filters.status !== '' || filters.month !== '';
    const setFilter = (next: Partial<Props['filters']>) =>
        router.get('/contenido', { ...filters, ...next }, { preserveState: true, replace: true });

    // Las grillas ya vienen ordenadas (mes en curso primero); se agrupan por mes para leerlas mejor.
    const groups = useMemo(() => {
        const map = new Map<string, { label: string; items: ContentGridItem[] }>();
        grids.data.forEach((g) => {
            if (!map.has(g.month)) map.set(g.month, { label: g.month_label, items: [] });
            map.get(g.month)!.items.push(g);
        });
        return [...map.entries()];
    }, [grids.data]);

    const openUpload = (t: UploadTarget | null = null) => {
        setTarget(t);
        setUploading(true);
    };

    return (
        <AppLayout title="Contenido">
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-display text-2xl font-extrabold tracking-tight">Contenido</h2>
                    <p className="text-sm text-muted-foreground">Grillas mensuales de tus clientes: qué falta cargar, qué está en revisión y qué ya se aprobó.</p>
                </div>
                {can.manage && (
                    <Button onClick={() => openUpload()}>
                        <Upload /> Subir grilla
                    </Button>
                )}
            </div>

            <Card className="mb-6">
                <CardHeader className="flex-row items-center justify-between">
                    <div>
                        <CardTitle className="flex items-center gap-2">
                            {missing.length === 0 ? <CheckCircle2 className="size-4 text-success" /> : <Hourglass className="size-4 text-warning" />}
                            Pendientes de {currentMonth.label}
                        </CardTitle>
                        <CardDescription>
                            {missing.length === 0 ? 'Todos tus clientes tienen su grilla del mes cargada.' : `${missing.length} ${missing.length === 1 ? 'cliente todavía no tiene' : 'clientes todavía no tienen'} la grilla del mes.`}
                        </CardDescription>
                    </div>
                </CardHeader>
                {missing.length > 0 && (
                    <CardContent className="p-0">
                        <ul className="divide-y">
                            {missing.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-3 px-5 py-2.5">
                                    <span className="font-medium">{c.name}</span>
                                    {can.manage && (
                                        <Button size="sm" variant="outline" onClick={() => openUpload({ clientId: c.id, month: currentMonth.value })}>
                                            <Upload /> Subir grilla
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                )}
            </Card>

            <div className="mb-5 flex flex-wrap items-center gap-3">
                <div className="w-52">
                    <Select aria-label="Filtrar por cliente" value={filters.client} onChange={(e) => setFilter({ client: e.target.value })}>
                        <option value="">Todos los clientes</option>
                        {clients.map((c) => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </Select>
                </div>
                <div className="w-48">
                    <Select aria-label="Filtrar por estado" value={filters.status} onChange={(e) => setFilter({ status: e.target.value })}>
                        <option value="">Todos los estados</option>
                        {Object.entries(statusOptions).map(([v, l]) => (
                            <option key={v} value={v}>{l}</option>
                        ))}
                    </Select>
                </div>
                <div className="w-44">
                    <Input type="month" aria-label="Filtrar por mes" placeholder="AAAA-MM" value={filters.month} onChange={(e) => setFilter({ month: e.target.value })} />
                </div>
                {filtered && (
                    <Button variant="ghost" size="sm" onClick={() => router.get('/contenido')}>
                        Quitar filtros
                    </Button>
                )}
            </div>

            <div className="flex flex-col gap-5">
                {grids.data.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={FileStack}
                            title={filtered ? 'Sin resultados' : 'Todavía no hay grillas'}
                            description={filtered ? 'Probá con otros filtros.' : 'Subí la primera grilla de un cliente para empezar.'}
                        />
                    </Card>
                ) : (
                    groups.map(([month, group]) => (
                        <section key={month} aria-label={group.label}>
                            <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                                {group.label}
                                {month === currentMonth.value && <span className="rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">Mes en curso</span>}
                            </h3>
                            <Card className="overflow-hidden">
                                <ul className="divide-y">
                                    {group.items.map((g) => (
                                        <GridRow
                                            key={g.id}
                                            grid={g}
                                            showClient
                                            statusOptions={statusOptions}
                                            onView={setViewing}
                                            onReplace={(grid) => openUpload({ clientId: grid.client.id, month: grid.month, title: grid.title, notes: grid.notes, replacing: true })}
                                            onDelete={setDeleting}
                                        />
                                    ))}
                                </ul>
                            </Card>
                        </section>
                    ))
                )}
            </div>

            {grids.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>{grids.from}–{grids.to} de {grids.total}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!grids.prev_page_url} onClick={() => grids.prev_page_url && router.get(grids.prev_page_url, {}, { preserveState: true })}><ChevronLeft /> Anterior</Button>
                        <Button variant="outline" size="sm" disabled={!grids.next_page_url} onClick={() => grids.next_page_url && router.get(grids.next_page_url, {}, { preserveState: true })}>Siguiente <ChevronRight /></Button>
                    </div>
                </div>
            )}

            <PdfViewerDialog grid={viewing} onClose={() => setViewing(null)} />
            <UploadGridDialog open={uploading} onClose={() => setUploading(false)} clients={clients} defaultMonth={currentMonth.value} target={target} />
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="¿Eliminar esta grilla?"
                description={`Se elimina la grilla de ${deleting?.client.name ?? ''} (${deleting?.month_label ?? ''}) y su PDF. Los enlaces ya compartidos dejan de funcionar. No se puede deshacer.`}
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
