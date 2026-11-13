import { Link, router } from '@inertiajs/react';
import { Copy, Eye, MessageCircle, Trash2, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { ContentStatusBadge } from './ContentStatusBadge';
import type { ContentGridItem } from './types';

interface Props {
    grid: ContentGridItem;
    statusOptions: Record<string, string>;
    /** En el panorama global se muestra el cliente (con enlace a su página). */
    showClient?: boolean;
    onView: (grid: ContentGridItem) => void;
    onReplace: (grid: ContentGridItem) => void;
    onDelete: (grid: ContentGridItem) => void;
}

const fmt = (iso: string) => new Date(iso).toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' });

const linkButton =
    'inline-flex h-8 items-center justify-center gap-2 rounded-md border border-input bg-card px-3 text-xs font-medium transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&_svg]:size-4';

export function GridRow({ grid, statusOptions, showClient, onView, onReplace, onDelete }: Props) {
    const registerShare = (channel: 'whatsapp' | 'link') =>
        router.post(`/contenido/${grid.id}/compartir`, { channel }, { preserveScroll: true, preserveState: true });

    const copyLink = async () => {
        if (!grid.share_url) return;
        try {
            await navigator.clipboard.writeText(grid.share_url);
            toast.success('Enlace copiado. Vale 30 días y abre el PDF sin iniciar sesión.');
            registerShare('link');
        } catch {
            toast.error('No se pudo copiar el enlace. Probá desde "Abrir en pestaña nueva".');
        }
    };

    return (
        <li className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:gap-6">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-display font-bold">{grid.month_label}</span>
                    <ContentStatusBadge status={grid.status} />
                    {showClient && (
                        <Link href={`/clientes/${grid.client.id}/contenido`} className="text-sm font-medium text-primary hover:underline">
                            {grid.client.name}
                        </Link>
                    )}
                </div>
                <p className="mt-0.5 truncate text-sm text-muted-foreground">{grid.title}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {grid.original_name} · {grid.size_label}
                    {grid.uploaded_by && ` · subida por ${grid.uploaded_by}`}
                    {grid.approved_at && ` · aprobada el ${fmt(grid.approved_at)}`}
                    {grid.shared_at && ` · compartida el ${fmt(grid.shared_at)}`}
                </p>
                {grid.notes && <p className="mt-1 text-xs italic text-muted-foreground">“{grid.notes}”</p>}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {grid.can.manage && (
                    <div className="w-44">
                        <Select
                            aria-label={`Estado de la grilla de ${grid.client.name}, ${grid.month_label}`}
                            value={grid.status}
                            onChange={(e) => router.patch(`/contenido/${grid.id}/estado`, { status: e.target.value }, { preserveScroll: true })}
                        >
                            {Object.entries(statusOptions).map(([v, l]) => (
                                <option key={v} value={v}>{l}</option>
                            ))}
                        </Select>
                    </div>
                )}

                <Button variant="outline" size="sm" onClick={() => onView(grid)}>
                    <Eye /> Ver
                </Button>

                {grid.can.manage && (
                    <>
                        {grid.whatsapp_url ? (
                            // Abre WhatsApp (nueva pestaña) y, a la vez, registra el compartido.
                            <a href={grid.whatsapp_url} target="_blank" rel="noreferrer noopener" className={linkButton} onClick={() => registerShare('whatsapp')}>
                                <MessageCircle /> WhatsApp
                            </a>
                        ) : (
                            <Button variant="outline" size="sm" disabled title="El cliente no tiene WhatsApp cargado: copiá el enlace y mandalo por otro medio">
                                <MessageCircle /> WhatsApp
                            </Button>
                        )}
                        <Button variant="outline" size="icon" className="size-8" aria-label={`Copiar enlace de ${grid.month_label}`} title="Copiar enlace público (30 días)" onClick={copyLink}>
                            <Copy />
                        </Button>
                        <Button variant="outline" size="icon" className="size-8" aria-label={`Reemplazar el PDF de ${grid.month_label}`} title="Reemplazar PDF" onClick={() => onReplace(grid)}>
                            <Upload />
                        </Button>
                        <Button variant="ghost" size="icon" className={cn('size-8 text-muted-foreground hover:bg-danger/10 hover:text-danger')} aria-label={`Eliminar la grilla de ${grid.month_label}`} title="Eliminar" onClick={() => onDelete(grid)}>
                            <Trash2 />
                        </Button>
                    </>
                )}
            </div>
        </li>
    );
}
