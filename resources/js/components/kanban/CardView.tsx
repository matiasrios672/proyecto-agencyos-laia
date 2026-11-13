import { AlertTriangle, CalendarClock, CalendarDays, CheckCircle2, Flag, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { KanbanCardData, Priority } from '@/types/kanban';

const priorityConfig: Record<Priority, { label: string; variant: 'danger' | 'warning' | 'default'; Icon: LucideIcon; stripe: string }> = {
    alta: { label: 'Prioridad alta', variant: 'danger', Icon: Flag, stripe: 'border-l-danger' },
    media: { label: 'Prioridad media', variant: 'warning', Icon: Flag, stripe: 'border-l-peach' },
    baja: { label: 'Prioridad baja', variant: 'default', Icon: Flag, stripe: 'border-l-input' },
};

export const formatDay = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString('es-AR', { day: 'numeric', month: 'short' });

const startOfToday = () => {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    return d;
};

/** Días que faltan para una fecha (negativo = ya pasó). */
export const daysUntil = (iso: string) => Math.round((new Date(iso + 'T00:00:00').getTime() - startOfToday().getTime()) / 86_400_000);

/** Texto relativo de una fecha límite: "Vence mañana", "Venció hace 2 días"… */
export function dueText(iso: string): { text: string; tone: 'danger' | 'warning' | 'muted' } {
    const d = daysUntil(iso);
    if (d < 0) return { text: d === -1 ? 'Venció ayer' : `Venció hace ${-d} días`, tone: 'danger' };
    if (d === 0) return { text: 'Vence hoy', tone: 'warning' };
    if (d === 1) return { text: 'Vence mañana', tone: 'warning' };
    if (d <= 7) return { text: `Vence en ${d} días`, tone: 'muted' };

    return { text: `Vence ${formatDay(iso)}`, tone: 'muted' };
}

export function PriorityBadge({ priority }: { priority: Priority }) {
    const cfg = priorityConfig[priority];
    // Texto + ícono: la prioridad nunca depende solo del color (la franja lateral es un refuerzo).
    return (
        <Badge variant={cfg.variant}>
            <cfg.Icon className="size-3" />
            {cfg.label.replace('Prioridad ', '')}
        </Badge>
    );
}

export function DueChip({ card }: { card: Pick<KanbanCardData, 'due_date' | 'overdue' | 'completed_at'> }) {
    if (card.completed_at) {
        return (
            <span className="inline-flex items-center gap-1 text-xs text-success">
                <CheckCircle2 className="size-3.5" /> Publicada
            </span>
        );
    }
    if (!card.due_date) return null;

    const { text, tone } = dueText(card.due_date);
    const Icon = tone === 'danger' ? AlertTriangle : tone === 'warning' ? CalendarClock : CalendarDays;

    return (
        <span className={cn('inline-flex items-center gap-1 text-xs', tone === 'danger' && 'font-medium text-danger', tone === 'warning' && 'font-medium text-warning', tone === 'muted' && 'text-muted-foreground')}>
            <Icon className="size-3.5" /> {text}
        </span>
    );
}

interface Props {
    card: KanbanCardData;
    /** Botón de arrastre (con los listeners de dnd-kit); no se muestra en el overlay ni cuando el arrastre está desactivado. */
    handle?: ReactNode;
    /** Menú de acciones ("Mover a…", editar). */
    menu?: ReactNode;
    onOpen?: () => void;
    dragging?: boolean;
    overlay?: boolean;
    /** Vista densa: una sola línea de título, sin descripción y con la prioridad como punto. */
    compact?: boolean;
}

export function CardView({ card, handle, menu, onOpen, dragging, overlay, compact }: Props) {
    const urgent = !card.completed_at && card.overdue;

    return (
        <article
            aria-label={card.title}
            className={cn(
                'group flex items-start gap-1.5 rounded-lg border border-l-4 bg-card shadow-xs transition-all duration-200',
                compact ? 'p-2' : 'p-3',
                priorityConfig[card.priority].stripe,
                card.completed_at && 'bg-success/5',
                urgent && 'ring-1 ring-danger/30',
                !overlay && 'hover:-translate-y-0.5 hover:shadow-md',
                overlay && 'rotate-2 scale-[1.03] cursor-grabbing shadow-[var(--shadow-pop)]',
                dragging && 'scale-[0.98] opacity-40',
            )}
        >
            {handle}
            <div className="min-w-0 flex-1">
                {onOpen ? (
                    <button
                        type="button"
                        onClick={onOpen}
                        className={cn('block w-full rounded text-left text-sm font-medium leading-snug hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', compact && 'truncate')}
                    >
                        {card.title}
                    </button>
                ) : (
                    <p className={cn('text-sm font-medium leading-snug', compact && 'truncate')}>{card.title}</p>
                )}
                {!compact && card.description && <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{card.description}</p>}
                <div className={cn('flex flex-wrap items-center gap-x-3 gap-y-1.5', compact ? 'mt-1' : 'mt-2.5')}>
                    {compact ? (
                        <span className={cn('size-2 rounded-full', card.priority === 'alta' ? 'bg-danger' : card.priority === 'media' ? 'bg-peach' : 'bg-input')} title={priorityConfig[card.priority].label}>
                            <span className="sr-only">{priorityConfig[card.priority].label}</span>
                        </span>
                    ) : (
                        <PriorityBadge priority={card.priority} />
                    )}
                    <DueChip card={card} />
                    {card.assignee && (
                        <span className="ml-auto flex items-center gap-1.5" title={card.assignee.name}>
                            <Avatar name={card.assignee.name} className={cn('text-[10px]', compact ? 'size-5' : 'size-6')} />
                            <span className="sr-only">Responsable: {card.assignee.name}</span>
                        </span>
                    )}
                </div>
            </div>
            {menu}
        </article>
    );
}
