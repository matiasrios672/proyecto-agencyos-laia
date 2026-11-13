import { CheckCircle2, Eye, FilePen, Play, Send } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

/** Estado de una grilla de contenido: siempre ícono + texto, nunca solo color. */
const statuses = {
    borrador: { label: 'Borrador', variant: 'default', Icon: FilePen },
    revision_interna: { label: 'Revisión interna', variant: 'primary', Icon: Eye },
    enviado_cliente: { label: 'Enviado al cliente', variant: 'warning', Icon: Send },
    aprobado: { label: 'Aprobado', variant: 'success', Icon: CheckCircle2 },
    en_curso: { label: 'En curso', variant: 'success', Icon: Play },
} as const;

export function ContentStatusBadge({ status }: { status: string }) {
    const cfg = statuses[status as keyof typeof statuses] ?? statuses.borrador;
    return (
        <Badge variant={cfg.variant}>
            <cfg.Icon className="size-3" />
            {cfg.label}
        </Badge>
    );
}
