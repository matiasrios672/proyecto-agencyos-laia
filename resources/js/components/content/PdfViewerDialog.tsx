import { Download, ExternalLink } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { ContentGridItem } from './types';

const linkClass =
    'inline-flex h-9 items-center justify-center gap-2 rounded-md border border-input bg-card px-4 text-sm font-medium transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&_svg]:size-4';

/**
 * Visor de PDF en un modal. El archivo viene de una ruta autenticada (con permiso por cliente);
 * en móvil muchos navegadores no embeben PDF, por eso siempre hay "Abrir en pestaña nueva" y "Descargar".
 * Esc cierra y el foco queda atrapado dentro (Dialog de Radix).
 */
export function PdfViewerDialog({ grid, onClose }: { grid: ContentGridItem | null; onClose: () => void }) {
    return (
        <Dialog open={grid !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="h-[88vh] max-w-5xl">
                {grid && (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {grid.client.name} · {grid.month_label}
                            </DialogTitle>
                            <DialogDescription>
                                {grid.title} · {grid.size_label}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="min-h-0 flex-1 p-3">
                            {/* El nombre del archivo cambia al reemplazarlo: la clave fuerza a recargar el visor. */}
                            <iframe
                                key={grid.updated_at}
                                title={`Grilla de ${grid.client.name}, ${grid.month_label}`}
                                src={grid.file_url}
                                className="size-full rounded-md border bg-muted"
                            />
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-2 border-t p-3">
                            <p className="text-xs text-muted-foreground">¿No se ve el PDF? Abrilo en una pestaña nueva o descargalo.</p>
                            <div className="flex gap-2">
                                <a href={grid.file_url} target="_blank" rel="noreferrer noopener" className={linkClass}>
                                    <ExternalLink /> Abrir en pestaña nueva
                                </a>
                                <a href={grid.download_url} className={linkClass}>
                                    <Download /> Descargar
                                </a>
                            </div>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
