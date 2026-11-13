import { DndContext, DragOverlay, PointerSensor, TouchSensor, useDraggable, useDroppable, useSensor, useSensors, type DragEndEvent, type DragStartEvent } from '@dnd-kit/core';
import { CheckCircle2, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { KanbanCardData, KanbanColumnData } from '@/types/kanban';

interface Props {
    cards: KanbanCardData[];
    columns: KanbanColumnData[];
    can: boolean;
    onOpen: (card: KanbanCardData) => void;
    onCreate: (date: string) => void;
    /** `null` = quitar la fecha (devolver la tarjeta a "Sin fecha"). */
    onReschedule: (card: KanbanCardData, date: string | null) => void;
}

const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Color por columna: refuerza el estado, pero cada chip también lo dice con texto/ícono. */
const tone = (key: string) =>
    key === 'publicado' ? 'border-l-success bg-success/10' : key === 'listo' ? 'border-l-chart-1 bg-chart-1/10' : 'border-l-chart-2 bg-chart-2/10';

function Chip({ card, columnKey, can, onOpen, overlay }: { card: KanbanCardData; columnKey: string; can: boolean; onOpen?: () => void; overlay?: boolean }) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: `chip-${card.id}`, disabled: !can || overlay });

    return (
        <button
            ref={setNodeRef}
            type="button"
            onClick={onOpen}
            {...(overlay ? {} : attributes)}
            {...(overlay ? {} : listeners)}
            title={card.title}
            className={cn(
                'flex w-full touch-none items-center gap-1.5 truncate rounded border-l-[3px] px-2 py-1 text-left text-xs font-medium transition-shadow hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                tone(columnKey),
                can && !overlay && 'cursor-grab active:cursor-grabbing',
                isDragging && 'opacity-40',
                overlay && 'rotate-2 shadow-[var(--shadow-pop)]',
            )}
        >
            {card.completed_at && <CheckCircle2 className="size-3 shrink-0 text-success" aria-label="Publicada" />}
            <span className={cn('truncate', card.overdue && !card.completed_at && 'text-danger')}>{card.title}</span>
        </button>
    );
}

function Day({ date, inMonth, isToday, can, children, onCreate }: { date: Date; inMonth: boolean; isToday: boolean; can: boolean; children: ReactNode; onCreate: () => void }) {
    const { setNodeRef, isOver } = useDroppable({ id: `day-${iso(date)}`, disabled: !can });

    return (
        <div
            ref={setNodeRef}
            className={cn('group relative flex min-h-24 flex-col gap-1 border-b border-r p-1.5 transition-colors', !inMonth && 'bg-muted/40', isOver && 'bg-accent/60')}
        >
            <div className="flex items-center justify-between">
                <span className={cn('grid size-6 place-items-center rounded-full text-xs tabular-nums', inMonth ? 'text-foreground' : 'text-muted-foreground/60', isToday && 'bg-primary font-semibold text-primary-foreground')}>
                    {date.getDate()}
                </span>
                {can && (
                    <button
                        type="button"
                        onClick={onCreate}
                        aria-label={`Nueva tarjeta el ${date.toLocaleDateString('es-AR', { day: 'numeric', month: 'long' })}`}
                        className="grid size-6 place-items-center rounded text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground focus-visible:opacity-100 group-hover:opacity-100"
                    >
                        <Plus className="size-3.5" />
                    </button>
                )}
            </div>
            {children}
        </div>
    );
}

function Tray({ can, children, count }: { can: boolean; children: ReactNode; count: number }) {
    const { setNodeRef, isOver } = useDroppable({ id: 'tray', disabled: !can });

    return (
        <div ref={setNodeRef} className={cn('mt-4 rounded-xl border border-dashed p-3 transition-colors', isOver && 'border-primary bg-accent/50')}>
            <p className="mb-2 text-sm font-medium">
                Sin fecha <span className="font-normal text-muted-foreground">({count})</span>
                {can && <span className="ml-2 text-xs font-normal text-muted-foreground">Arrastrá una tarjeta a un día para programarla</span>}
            </p>
            <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">{children}</div>
            {count === 0 && <p className="text-sm text-muted-foreground">Todas las tarjetas tienen fecha.</p>}
        </div>
    );
}

export function CalendarView({ cards, columns, can, onOpen, onCreate, onReschedule }: Props) {
    const [month, setMonth] = useState(() => {
        const d = new Date();
        return new Date(d.getFullYear(), d.getMonth(), 1);
    });
    const [dragging, setDragging] = useState<KanbanCardData | null>(null);
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }), useSensor(TouchSensor, { activationConstraint: { delay: 180, tolerance: 8 } }));
    const keyOf = useMemo(() => new Map(columns.map((c) => [c.id, c.key])), [columns]);

    // 6 semanas × 7 días empezando en lunes: la grilla nunca cambia de alto entre meses.
    const days = useMemo(() => {
        const offset = (month.getDay() + 6) % 7;
        return Array.from({ length: 42 }, (_, i) => new Date(month.getFullYear(), month.getMonth(), i - offset + 1));
    }, [month]);

    const byDate = useMemo(() => {
        const map = new Map<string, KanbanCardData[]>();
        cards.filter((c) => c.due_date).forEach((c) => map.set(c.due_date!, [...(map.get(c.due_date!) ?? []), c]));
        return map;
    }, [cards]);
    const undated = cards.filter((c) => !c.due_date);
    const todayIso = iso(new Date());
    const label = month.toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });

    const shift = (n: number) => setMonth((m) => new Date(m.getFullYear(), m.getMonth() + n, 1));

    const onDragEnd = ({ over }: DragEndEvent) => {
        const card = dragging;
        setDragging(null);
        if (!card || !over) return;
        const target = over.id === 'tray' ? null : String(over.id).replace('day-', '');
        if ((card.due_date ?? null) !== target) onReschedule(card, target);
    };

    return (
        <DndContext
            sensors={sensors}
            onDragStart={({ active }: DragStartEvent) => setDragging(cards.find((c) => `chip-${c.id}` === active.id) ?? null)}
            onDragEnd={onDragEnd}
            onDragCancel={() => setDragging(null)}
        >
            <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="font-display text-lg font-bold capitalize tracking-tight" aria-live="polite">{label}</h3>
                <div className="flex items-center gap-1.5">
                    <Button variant="outline" size="sm" onClick={() => setMonth(new Date(new Date().getFullYear(), new Date().getMonth(), 1))}>Hoy</Button>
                    <Button variant="outline" size="icon" className="size-8" aria-label="Mes anterior" onClick={() => shift(-1)}><ChevronLeft /></Button>
                    <Button variant="outline" size="icon" className="size-8" aria-label="Mes siguiente" onClick={() => shift(1)}><ChevronRight /></Button>
                </div>
            </div>

            <div className="overflow-x-auto rounded-xl border bg-card">
                <div className="min-w-[44rem]">
                    <div className="grid grid-cols-7 border-b bg-muted/40 text-xs font-medium text-muted-foreground">
                        {WEEKDAYS.map((d) => <div key={d} className="px-2 py-2 text-center">{d}</div>)}
                    </div>
                    <div className="grid grid-cols-7 [&>div:nth-child(7n)]:border-r-0">
                        {days.map((date) => (
                            <Day key={iso(date)} date={date} inMonth={date.getMonth() === month.getMonth()} isToday={iso(date) === todayIso} can={can} onCreate={() => onCreate(iso(date))}>
                                {(byDate.get(iso(date)) ?? []).map((c) => <Chip key={c.id} card={c} columnKey={keyOf.get(c.column_id) ?? ''} can={can} onOpen={() => onOpen(c)} />)}
                            </Day>
                        ))}
                    </div>
                </div>
            </div>

            <Tray can={can} count={undated.length}>
                {undated.map((c) => <Chip key={c.id} card={c} columnKey={keyOf.get(c.column_id) ?? ''} can={can} onOpen={() => onOpen(c)} />)}
            </Tray>

            <DragOverlay dropAnimation={null}>{dragging ? <Chip card={dragging} columnKey={keyOf.get(dragging.column_id) ?? ''} can={false} overlay /> : null}</DragOverlay>
        </DndContext>
    );
}
