import { Link, router } from '@inertiajs/react';
import { ArchiveRestore, ArrowLeft, ChevronLeft, ChevronRight, KeyRound, Layers, Receipt, Search, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout';
import type { Paginated } from '@/types';

interface Row {
    id: number;
    name: string;
    business_name: string;
    tax_id: string | null;
    deleted_at: string;
    deleted_by: string | null;
    services_count: number;
    vault_count: number;
    invoices_count: number;
}

interface Props {
    clients: Paginated<Row>;
    filters: { q: string };
}

const fmt = (iso: string) => new Date(iso).toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: 'numeric' });

export default function Trashed({ clients, filters }: Props) {
    const [q, setQ] = useState(filters.q);
    const [restoring, setRestoring] = useState<Row | null>(null);
    const [busy, setBusy] = useState(false);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => router.get('/clientes-eliminados', { q: q || undefined }, { preserveState: true, replace: true }), 300);
        return () => clearTimeout(t);
    }, [q]);

    const restore = () => {
        if (!restoring) return;
        setBusy(true);
        router.post(`/clientes-eliminados/${restoring.id}/restaurar`, {}, {
            // Si el CUIT ya lo usa otro cliente activo, el servidor explica por qué no se puede restaurar.
            onError: (errors) => toast.error(errors.restore ?? 'No se pudo restaurar el cliente.'),
            onFinish: () => { setBusy(false); setRestoring(null); },
        });
    };

    return (
        <AppLayout title="Clientes eliminados">
            <Link href="/clientes" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-4" /> Clientes
            </Link>

            <div className="mb-5 flex flex-wrap items-center gap-3">
                <div className="relative min-w-56 flex-1 sm:max-w-sm">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="pl-9" placeholder="Buscar por nombre o CUIT…" aria-label="Buscar clientes eliminados" value={q} onChange={(e) => setQ(e.target.value)} />
                </div>
                <p className="text-sm text-muted-foreground">Al restaurar un cliente vuelve con sus servicios, bóveda, cobros y equipo.</p>
            </div>

            <Card className="overflow-hidden">
                {clients.data.length === 0 ? (
                    <EmptyState
                        icon={Trash2}
                        title={q ? 'Sin resultados' : 'No hay clientes eliminados'}
                        description={q ? 'Probá con otro nombre o CUIT.' : 'Cuando elimines un cliente por error, lo vas a poder recuperar desde acá.'}
                    />
                ) : (
                    <ul className="divide-y">
                        {clients.data.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
                                <div className="min-w-56 flex-1">
                                    <p className="font-medium">{c.name}</p>
                                    {c.name !== c.business_name && <p className="text-sm text-muted-foreground">{c.business_name}</p>}
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {c.tax_id ? <span className="tabular-nums">CUIT {c.tax_id} · </span> : null}
                                        Eliminado el {fmt(c.deleted_at)}{c.deleted_by && ` por ${c.deleted_by}`}
                                    </p>
                                </div>
                                <ul className="flex items-center gap-3 text-xs text-muted-foreground" aria-label="Información que conserva">
                                    <li className="inline-flex items-center gap-1" title="Servicios contratados"><Layers className="size-3.5" /> {c.services_count}</li>
                                    <li className="inline-flex items-center gap-1" title="Credenciales en la bóveda"><KeyRound className="size-3.5" /> {c.vault_count}</li>
                                    <li className="inline-flex items-center gap-1" title="Cobros"><Receipt className="size-3.5" /> {c.invoices_count}</li>
                                </ul>
                                <Button variant="outline" size="sm" onClick={() => setRestoring(c)}>
                                    <ArchiveRestore /> Restaurar
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {clients.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                    <span>{clients.from}–{clients.to} de {clients.total}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!clients.prev_page_url} onClick={() => clients.prev_page_url && router.get(clients.prev_page_url, {}, { preserveState: true })}><ChevronLeft /> Anterior</Button>
                        <Button variant="outline" size="sm" disabled={!clients.next_page_url} onClick={() => clients.next_page_url && router.get(clients.next_page_url, {}, { preserveState: true })}>Siguiente <ChevronRight /></Button>
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={restoring !== null}
                onOpenChange={(o) => !o && setRestoring(null)}
                title="¿Restaurar este cliente?"
                description={`${restoring?.name ?? ''} vuelve a la lista de clientes con sus servicios, bóveda, cobros y equipo asignado. Queda registrado en la auditoría.`}
                confirmLabel="Restaurar cliente"
                busy={busy}
                onConfirm={restore}
            />
        </AppLayout>
    );
}
