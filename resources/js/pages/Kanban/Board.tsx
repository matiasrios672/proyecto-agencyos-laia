import { Link, router } from '@inertiajs/react';
import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    TouchSensor,
    closestCorners,
    useDroppable,
    useSensor,
    useSensors,
    type Announcements,
    type DragEndEvent,
    type DragOverEvent,
    type DragStartEvent,
    type UniqueIdentifier,
} from '@dnd-kit/core';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import axios from 'axios';
import { AlertTriangle, ArrowLeft, ArrowRightLeft, CalendarClock, CalendarDays, ChevronDown, GripVertical, KanbanSquare, MoreHorizontal, Pencil, Plus, Rows3, Search, UserX, X, type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { CalendarView } from '@/components/kanban/CalendarView';
import { CardDialog, type DialogTarget } from '@/components/kanban/CardDialog';
import { CardView, daysUntil } from '@/components/kanban/CardView';
import { Confetti } from '@/components/kanban/Confetti';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { Options } from '@/types';
import type { KanbanCardData, KanbanColumnData, MoveResponse, TeamMember } from '@/types/kanban';

interface Props {
    client: { id: number; name: string };
    columns: KanbanColumnData[];
    team: TeamMember[];
    priorities: Options;
    me: number;
    can: { manage: boolean };
}

type Filter = 'all' | 'mine' | 'overdue' | 'week' | 'unassigned';
type View = 'board' | 'calendar';

const cardId = (id: number) => `card-${id}`;
const colId = (id: number) => `col-${id}`;
/** Preferencia de interfaz recordada en el navegador (sin almacenamiento, vive solo en memoria). */
function usePref<T>(key: string, initial: T): [T, (v: T) => void] {
    const [value, setValue] = useState<T>(() => {
        try {
            const raw = localStorage.getItem(key);
            return raw === null ? initial : (JSON.parse(raw) as T);
        } catch {
            return initial;
        }
    });
    return [
        value,
        (v: T) => {
            setValue(v);
            try {
                localStorage.setItem(key, JSON.stringify(v));
            } catch {
                /* noop */
            }
        },
    ];
}

const parseId = (id: UniqueIdentifier) => Number(String(id).split('-')[1]);
const isColumn = (id: UniqueIdentifier) => String(id).startsWith('col-');

/** Color de cada estado: una barra superior en la columna (el nombre y el contador siempre están en texto). */
const accent = (key: string) => (key === 'publicado' ? 'bg-success' : key === 'listo' ? 'bg-chart-1' : key === 'pendientes' ? 'bg-chart-2' : 'bg-muted-foreground');

/** Tarjetas con la posición que el servidor les asignó (0..n-1), para reconciliar el estado optimista. */
function reconcile(columns: KanbanColumnData[], res: MoveResponse): KanbanColumnData[] {
    const touched = new Set(res.cards.map((c) => c.column_id));
    const byId = new Map(res.cards.map((c) => [c.id, c]));
    const all = columns.flatMap((c) => c.cards);

    return columns.map((col) => {
        if (!touched.has(col.id)) return col;
        const cards = all
            .filter((c) => byId.get(c.id)?.column_id === col.id)
            .map((c) => ({ ...c, ...byId.get(c.id)! }))
            .sort((a, b) => a.position - b.position);

        return { ...col, cards };
    });
}

export default function Board({ client, columns: serverColumns, team, priorities, me, can }: Props) {
    const [columns, setColumns] = useState(serverColumns);
    const [active, setActive] = useState<KanbanCardData | null>(null);
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const [view, setView] = useState<View>('board');
    const [burst, setBurst] = useState(0);
    const [dialog, setDialog] = useState<DialogTarget>(null);
    const [compact, setCompact] = usePref<boolean>('laia.kanban.compact', false);
    const [collapsed, setCollapsed] = usePref<number[]>(`laia.kanban.collapsed.${client.id}`, []);
    const searchRef = useRef<HTMLInputElement>(null);
    // Estado previo al arrastre: sirve para deshacer si el servidor rechaza el movimiento.
    const snapshot = useRef<KanbanColumnData[]>(serverColumns);

    // Cuando Inertia trae datos nuevos (crear / editar / eliminar), el tablero se sincroniza con el servidor.
    useEffect(() => setColumns(serverColumns), [serverColumns]);

    // Atajos: "/" busca · "n" nueva tarjeta · "v" alterna tablero/calendario · "d" alterna densidad.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const el = e.target as HTMLElement | null;
            if (e.metaKey || e.ctrlKey || e.altKey || el?.closest('input, textarea, select, [contenteditable], [role="dialog"]')) return;
            if (e.key === '/') {
                e.preventDefault();
                searchRef.current?.focus();
            } else if (e.key === 'n' && can.manage) {
                e.preventDefault();
                setDialog({ mode: 'create', columnId: serverColumns[0]?.id ?? 0 });
            } else if (e.key === 'v') setView((v) => (v === 'board' ? 'calendar' : 'board'));
            else if (e.key === 'd') setCompact(!compact);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const filtersActive = filter !== 'all' || query.trim() !== '';
    // Con un filtro activo las posiciones visibles no coinciden con las reales: el arrastre se desactiva y queda "Mover a…".
    const dragDisabled = !can.manage || filtersActive;

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 180, tolerance: 8 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const findColumn = (id: UniqueIdentifier, cols = columns): KanbanColumnData | undefined =>
        isColumn(id) ? cols.find((c) => c.id === parseId(id)) : cols.find((c) => c.cards.some((card) => card.id === parseId(id)));

    const cardTitle = (id: UniqueIdentifier) => columns.flatMap((c) => c.cards).find((c) => c.id === parseId(id))?.title ?? 'tarjeta';

    const position = (id: UniqueIdentifier) => {
        const col = findColumn(id);
        return col ? col.cards.findIndex((c) => c.id === parseId(id)) + 1 : 0;
    };

    const announcements: Announcements = {
        onDragStart: ({ active }) => `Tomaste la tarjeta «${cardTitle(active.id)}». Usá las flechas para moverla y la barra espaciadora para soltarla.`,
        onDragOver: ({ active, over }) => {
            if (!over) return `La tarjeta «${cardTitle(active.id)}» no está sobre ninguna columna.`;
            const col = findColumn(over.id);
            return `Tarjeta «${cardTitle(active.id)}» sobre la columna ${col?.name ?? ''}, posición ${isColumn(over.id) ? (col?.cards.length ?? 0) + 1 : position(over.id)}.`;
        },
        onDragEnd: ({ active, over }) => {
            if (!over) return `Soltaste «${cardTitle(active.id)}» fuera de las columnas: no se movió.`;
            const col = findColumn(active.id);
            return `Soltaste «${cardTitle(active.id)}» en la columna ${col?.name ?? ''}, posición ${position(active.id)} de ${col?.cards.length ?? 0}.`;
        },
        onDragCancel: ({ active }) => `Cancelaste el movimiento de «${cardTitle(active.id)}».`,
    };

    /** Celebración al publicar (confeti + aviso): refuerza el cierre de una tarea de contenido. */
    const celebrate = (title: string) => {
        setBurst((b) => b + 1);
        toast.success(`¡Publicada! ${title}`, { duration: 3500 });
    };

    /** Aplica el movimiento en el servidor; si falla, vuelve al estado previo y avisa. */
    const commit = async (card: KanbanCardData, columnId: number, index: number, before: KanbanColumnData[], publish: boolean) => {
        try {
            const { data } = await axios.post<MoveResponse>(`/clientes/${client.id}/tablero/tarjetas/${card.id}/mover`, { column_id: columnId, position: index });
            setColumns((cols) => reconcile(cols, data));
            if (publish) celebrate(card.title);
        } catch (error) {
            setColumns(before);
            const message = axios.isAxiosError(error) ? (error.response?.data?.errors?.column_id?.[0] ?? error.response?.data?.message) : null;
            toast.error(message ?? 'No se pudo mover la tarjeta. Se volvió a su lugar.');
        }
    };

    const moveToColumn = (card: KanbanCardData, columnId: number) => {
        const before = columns;
        const target = columns.find((c) => c.id === columnId);
        if (!target || card.column_id === columnId) return;

        setColumns(
            columns.map((c) =>
                c.id === card.column_id
                    ? { ...c, cards: c.cards.filter((x) => x.id !== card.id) }
                    : c.id === columnId
                      ? { ...c, cards: [...c.cards, { ...card, column_id: columnId }] }
                      : c,
            ),
        );
        void commit(card, columnId, target.cards.length, before, target.key === 'publicado');
    };

    const onDragStart = ({ active }: DragStartEvent) => {
        snapshot.current = columns;
        setActive(columns.flatMap((c) => c.cards).find((c) => c.id === parseId(active.id)) ?? null);
    };

    // Mientras se arrastra sobre otra columna, la tarjeta ya se muestra ahí (patrón de múltiples contenedores).
    const onDragOver = ({ active, over }: DragOverEvent) => {
        if (!over) return;
        const from = findColumn(active.id);
        const to = findColumn(over.id);
        if (!from || !to || from.id === to.id) return;

        setColumns((cols) => {
            const source = cols.find((c) => c.id === from.id)!;
            const dest = cols.find((c) => c.id === to.id)!;
            const moving = source.cards.find((c) => c.id === parseId(active.id));
            if (!moving) return cols;

            let index = dest.cards.length;
            if (!isColumn(over.id)) {
                const overIndex = dest.cards.findIndex((c) => c.id === parseId(over.id));
                const below = active.rect.current.translated && active.rect.current.translated.top > over.rect.top + over.rect.height / 2;
                index = overIndex + (below ? 1 : 0);
            }

            return cols.map((c) =>
                c.id === source.id
                    ? { ...c, cards: c.cards.filter((x) => x.id !== moving.id) }
                    : c.id === dest.id
                      ? { ...c, cards: [...c.cards.slice(0, index), { ...moving, column_id: dest.id }, ...c.cards.slice(index)] }
                      : c,
            );
        });
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setActive(null);
        const before = snapshot.current;

        if (!over) {
            setColumns(before);
            return;
        }

        let next = columns;
        const col = findColumn(active.id, next);
        if (!col) return;

        // Reordenamiento dentro de la misma columna.
        if (!isColumn(over.id) && over.id !== active.id && findColumn(over.id, next)?.id === col.id) {
            const oldIndex = col.cards.findIndex((c) => c.id === parseId(active.id));
            const newIndex = col.cards.findIndex((c) => c.id === parseId(over.id));
            next = next.map((c) => (c.id === col.id ? { ...c, cards: arrayMove(c.cards, oldIndex, newIndex) } : c));
            setColumns(next);
        }

        const finalCol = findColumn(active.id, next)!;
        const index = finalCol.cards.findIndex((c) => c.id === parseId(active.id));
        const original = before.find((c) => c.cards.some((x) => x.id === parseId(active.id)));
        const originalIndex = original?.cards.findIndex((x) => x.id === parseId(active.id)) ?? -1;

        if (original?.id === finalCol.id && originalIndex === index) return; // quedó donde estaba

        const card = finalCol.cards[index];
        void commit(card, finalCol.id, index, before, finalCol.key === 'publicado' && original?.id !== finalCol.id);
    };

    /** Alta rápida desde el pie de la columna: solo el título; el resto se completa después. */
    const quickAdd = (columnId: number, title: string, done: () => void) => {
        router.post(
            `/clientes/${client.id}/tablero/tarjetas`,
            { title, column_id: columnId, priority: 'media' },
            { preserveScroll: true, preserveState: true, only: ['columns'], onSuccess: done, onError: () => toast.error('No se pudo crear la tarjeta.') },
        );
    };

    /** Reprograma desde el calendario (o quita la fecha). Optimista, con vuelta atrás si el servidor rechaza. */
    const reschedule = (card: KanbanCardData, date: string | null) => {
        const before = columns;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const overdue = date !== null && !card.completed_at && new Date(date + 'T00:00:00') < today;

        setColumns((cols) => cols.map((c) => ({ ...c, cards: c.cards.map((x) => (x.id === card.id ? { ...x, due_date: date, overdue } : x)) })));
        router.put(
            `/clientes/${client.id}/tablero/tarjetas/${card.id}`,
            { title: card.title, description: card.description, assigned_user_id: card.assignee?.id ?? null, due_date: date, priority: card.priority },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['columns'],
                onSuccess: () => toast.success(date ? `«${card.title}» reprogramada para el ${new Date(date + 'T00:00:00').toLocaleDateString('es-AR', { day: 'numeric', month: 'long' })}.` : `«${card.title}» quedó sin fecha.`),
                onError: () => {
                    setColumns(before);
                    toast.error('No se pudo reprogramar la tarjeta.');
                },
            },
        );
    };

    const allCards = useMemo(() => columns.flatMap((c) => c.cards), [columns]);
    const stats = useMemo(() => {
        const open = allCards.filter((c) => !c.completed_at);
        const inWeek = (c: KanbanCardData) => !!c.due_date && daysUntil(c.due_date) >= 0 && daysUntil(c.due_date) <= 7;

        return {
            total: allCards.length,
            done: allCards.length - open.length,
            overdue: open.filter((c) => c.overdue).length,
            week: open.filter(inWeek).length,
            unassigned: open.filter((c) => !c.assignee).length,
            mine: allCards.filter((c) => c.assignee?.id === me).length,
        };
    }, [allCards, me]);
    const progress = stats.total ? Math.round((stats.done / stats.total) * 100) : 0;

    const matches = (card: KanbanCardData) => {
        if (query.trim() && !`${card.title} ${card.description ?? ''}`.toLowerCase().includes(query.trim().toLowerCase())) return false;
        switch (filter) {
            case 'mine':
                return card.assignee?.id === me;
            case 'overdue':
                return card.overdue && !card.completed_at;
            case 'week':
                return !card.completed_at && !!card.due_date && daysUntil(card.due_date) >= 0 && daysUntil(card.due_date) <= 7;
            case 'unassigned':
                return !card.assignee && !card.completed_at;
            default:
                return true;
        }
    };

    const visibleColumns = useMemo(
        () => columns.map((c) => ({ ...c, cards: filtersActive ? c.cards.filter(matches) : c.cards })),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [columns, filter, query, me],
    );
    const visibleCards = visibleColumns.flatMap((c) => c.cards);

    const chips: { id: Filter; label: string; count: number; icon?: LucideIcon; tone?: string }[] = [
        { id: 'all', label: 'Todas', count: stats.total },
        { id: 'mine', label: 'Mías', count: stats.mine },
        { id: 'overdue', label: 'Vencidas', count: stats.overdue, icon: AlertTriangle, tone: 'text-danger' },
        { id: 'week', label: 'Esta semana', count: stats.week, icon: CalendarClock },
        { id: 'unassigned', label: 'Sin responsable', count: stats.unassigned, icon: UserX },
    ];

    return (
        <AppLayout title={`Tablero · ${client.name}`}>
            <Link href={`/clientes/${client.id}`} className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-4" /> {client.name}
            </Link>

            {/* Encabezado con avance: cuánto de lo planificado ya salió */}
            <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
                <div className="min-w-64 flex-1">
                    <h2 className="font-display text-2xl font-extrabold tracking-tight">Tablero de contenido</h2>
                    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                        <div
                            role="progressbar"
                            aria-valuenow={progress}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-label="Contenido publicado"
                            className="flex h-2.5 w-full max-w-sm overflow-hidden rounded-full bg-muted"
                        >
                            {columns.map((c) => (
                                <div key={c.id} title={`${c.name}: ${c.cards.length}`} className={cn('h-full transition-[width] duration-500', accent(c.key))} style={{ width: stats.total ? `${(c.cards.length / stats.total) * 100}%` : 0 }} />
                            ))}
                        </div>
                        <p className="whitespace-nowrap text-sm text-muted-foreground">
                            <span className="font-medium text-foreground tabular-nums">{stats.done}</span> de {stats.total} publicadas · {progress}%
                            {can.manage ? '' : ' · solo lectura'}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <div role="tablist" aria-label="Vista" className="inline-flex rounded-md border bg-muted p-0.5">
                        {([['board', 'Tablero', KanbanSquare], ['calendar', 'Calendario', CalendarDays]] as const).map(([id, label, Icon]) => (
                            <button
                                key={id}
                                role="tab"
                                aria-selected={view === id}
                                onClick={() => setView(id)}
                                className={cn('inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors', view === id && 'bg-card text-foreground shadow-sm')}
                            >
                                <Icon className="size-4" /> {label}
                            </button>
                        ))}
                    </div>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-pressed={compact}
                        aria-label={compact ? 'Vista cómoda (atajo D)' : 'Vista compacta (atajo D)'}
                        title={compact ? 'Vista cómoda (D)' : 'Vista compacta (D)'}
                        onClick={() => setCompact(!compact)}
                        className={cn(compact && 'border-primary bg-accent text-accent-foreground')}
                    >
                        <Rows3 />
                    </Button>
                    {can.manage && (
                        <Button title="Atajo: N" onClick={() => setDialog({ mode: 'create', columnId: columns[0]?.id ?? 0 })}>
                            <Plus /> Nueva tarjeta
                        </Button>
                    )}
                </div>
            </div>

            {/* Filtros: chips con contador (cada uno muestra cuántas tarjetas cumplen) + búsqueda */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-56">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input ref={searchRef} className="h-8 pl-9 pr-8" placeholder="Buscar tarjeta…  ( / )" aria-label="Buscar tarjetas" value={query} onChange={(e) => setQuery(e.target.value)} />
                    {query && (
                        <button type="button" aria-label="Borrar búsqueda" onClick={() => setQuery('')} className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground">
                            <X className="size-3.5" />
                        </button>
                    )}
                </div>
                <div role="group" aria-label="Filtrar tarjetas" className="flex flex-wrap gap-1.5">
                    {chips.map((c) => (
                        <button
                            key={c.id}
                            type="button"
                            aria-pressed={filter === c.id}
                            onClick={() => setFilter(filter === c.id ? 'all' : c.id)}
                            className={cn(
                                'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                filter === c.id ? 'border-primary bg-accent font-medium text-accent-foreground' : 'bg-card text-muted-foreground',
                            )}
                        >
                            {c.icon && <c.icon className={cn('size-3.5', c.count > 0 && c.tone)} />}
                            {c.label}
                            <span className={cn('rounded-full bg-muted px-1.5 text-xs tabular-nums', c.count > 0 && c.tone, filter === c.id && 'bg-card')}>{c.count}</span>
                        </button>
                    ))}
                </div>
            </div>

            {filtersActive && can.manage && view === 'board' && (
                <p role="status" className="mb-4 rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                    Con un filtro activo el arrastre está desactivado (las posiciones se verían distintas a las reales). Usá el menú de cada tarjeta para moverla, o quitá el filtro.
                </p>
            )}

            {view === 'calendar' ? (
                <CalendarView
                    cards={visibleCards}
                    columns={columns}
                    can={can.manage}
                    onOpen={(card) => setDialog({ mode: 'edit', card })}
                    onCreate={(date) => setDialog({ mode: 'create', columnId: columns[0]?.id ?? 0, dueDate: date })}
                    onReschedule={reschedule}
                />
            ) : (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCorners}
                    onDragStart={onDragStart}
                    onDragOver={onDragOver}
                    onDragEnd={onDragEnd}
                    onDragCancel={() => {
                        setActive(null);
                        setColumns(snapshot.current);
                    }}
                    accessibility={{
                        announcements,
                        screenReaderInstructions: {
                            draggable:
                                'Para mover la tarjeta: presioná la barra espaciadora o Enter, usá las flechas para desplazarla entre posiciones y columnas, la barra espaciadora para soltarla o Escape para cancelar.',
                        },
                    }}
                >
                    <div className="relative flex max-w-full snap-x snap-mandatory items-start gap-3 overflow-x-auto pb-3 md:grid md:snap-none md:grid-cols-3 md:gap-4 md:overflow-visible md:pb-0">
                        {visibleColumns.map((col) => (
                            <Column
                                key={col.id}
                                column={col}
                                all={columns}
                                can={can.manage}
                                dragDisabled={dragDisabled}
                                filtered={filtersActive}
                                compact={compact}
                                collapsed={collapsed.includes(col.id)}
                                onToggle={() => setCollapsed(collapsed.includes(col.id) ? collapsed.filter((x) => x !== col.id) : [...collapsed, col.id])}
                                onAdd={() => setDialog({ mode: 'create', columnId: col.id })}
                                onQuickAdd={(title, done) => quickAdd(col.id, title, done)}
                                onOpen={(card) => setDialog({ mode: 'edit', card })}
                                onMove={moveToColumn}
                            />
                        ))}
                    </div>
                    <DragOverlay dropAnimation={null}>{active ? <CardView card={active} overlay /> : null}</DragOverlay>
                </DndContext>
            )}

            <Confetti burstKey={burst} />
            <CardDialog target={dialog} clientId={client.id} columns={columns} team={team} priorities={priorities} onClose={() => setDialog(null)} />
        </AppLayout>
    );
}

interface ColumnProps {
    column: KanbanColumnData;
    all: KanbanColumnData[];
    can: boolean;
    dragDisabled: boolean;
    filtered: boolean;
    compact: boolean;
    collapsed: boolean;
    onToggle: () => void;
    onAdd: () => void;
    onQuickAdd: (title: string, done: () => void) => void;
    onOpen: (card: KanbanCardData) => void;
    onMove: (card: KanbanCardData, columnId: number) => void;
}

function Column({ column, all, can, dragDisabled, filtered, compact, collapsed, onToggle, onAdd, onQuickAdd, onOpen, onMove }: ColumnProps) {
    const { setNodeRef, isOver } = useDroppable({ id: colId(column.id), disabled: dragDisabled || collapsed });
    const [title, setTitle] = useState('');
    const overdue = column.cards.filter((c) => c.overdue && !c.completed_at).length;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const value = title.trim();
        if (value) onQuickAdd(value, () => setTitle(''));
    };

    return (
        <section aria-label={`Columna ${column.name}`} className={cn('flex w-[85%] shrink-0 snap-center flex-col overflow-hidden rounded-xl border bg-muted/40 transition-shadow md:w-auto', !collapsed && 'min-h-48', isOver && 'shadow-lg ring-2 ring-primary/40')}>
            <div className={cn('h-1', accent(column.key))} aria-hidden="true" />
            <header className="flex items-center justify-between gap-2 px-3.5 py-3">
                <h3 className="flex items-center gap-2 text-sm font-semibold">
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-expanded={!collapsed}
                        aria-label={`${collapsed ? 'Expandir' : 'Contraer'} la columna ${column.name}`}
                        className="-ml-1 grid size-6 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <ChevronDown className={cn('size-4 transition-transform', collapsed && '-rotate-90')} />
                    </button>
                    {column.name}
                    <span className="grid min-w-5 place-items-center rounded-full bg-card px-1.5 text-xs font-medium tabular-nums text-muted-foreground ring-1 ring-border" aria-label={`${column.cards.length} tarjetas`}>
                        {column.cards.length}
                    </span>
                    {overdue > 0 && (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-danger">
                            <AlertTriangle className="size-3" /> {overdue} {overdue === 1 ? 'vencida' : 'vencidas'}
                        </span>
                    )}
                </h3>
                {can && (
                    <Button variant="ghost" size="icon" className="size-7" aria-label={`Agregar tarjeta con todos los datos en ${column.name}`} onClick={onAdd}>
                        <Plus />
                    </Button>
                )}
            </header>

            {!collapsed && (
            <SortableContext items={column.cards.map((c) => cardId(c.id))} strategy={verticalListSortingStrategy} disabled={dragDisabled}>
                <div ref={setNodeRef} className={cn('flex flex-1 flex-col gap-2.5 px-2.5 pb-2.5 transition-colors', isOver && 'bg-accent/50 ring-2 ring-inset ring-primary/30')}>
                    {column.cards.length === 0 && (
                        <p className="grid flex-1 place-items-center rounded-lg border border-dashed px-3 py-8 text-center text-sm text-muted-foreground">
                            {filtered ? 'Ninguna tarjeta coincide con el filtro.' : can ? 'Sin tarjetas. Arrastrá una acá o escribí abajo para crear una.' : 'Sin tarjetas.'}
                        </p>
                    )}
                    {column.cards.map((card) => (
                        <SortableCard key={card.id} compact={compact} card={card} columns={all} can={can} dragDisabled={dragDisabled} onOpen={onOpen} onMove={onMove} />
                    ))}
                </div>
            </SortableContext>
            )}

            {can && !collapsed && (
                <form onSubmit={submit} className="border-t bg-card/60 px-2.5 py-2">
                    <label htmlFor={`quick-${column.id}`} className="sr-only">
                        Título de la nueva tarjeta en {column.name}
                    </label>
                    <div className="relative">
                        <Plus className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input id={`quick-${column.id}`} className="h-8 border-transparent bg-transparent pl-8 shadow-none hover:bg-muted/60 focus-visible:bg-card" placeholder="Agregar tarjeta… y Enter" maxLength={160} value={title} onChange={(e) => setTitle(e.target.value)} />
                    </div>
                </form>
            )}
        </section>
    );
}

function SortableCard({ compact, card, columns, can, dragDisabled, onOpen, onMove }: { compact: boolean; card: KanbanCardData; columns: KanbanColumnData[]; can: boolean; dragDisabled: boolean; onOpen: (c: KanbanCardData) => void; onMove: (c: KanbanCardData, columnId: number) => void }) {
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: cardId(card.id), disabled: dragDisabled });

    return (
        <div ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }}>
            <CardView
                card={card}
                compact={compact}
                dragging={isDragging}
                onOpen={can ? () => onOpen(card) : undefined}
                handle={
                    !dragDisabled && (
                        <button
                            ref={setActivatorNodeRef}
                            type="button"
                            {...attributes}
                            {...listeners}
                            aria-label={`Reordenar la tarjeta ${card.title}`}
                            className="-ml-1 mt-0.5 grid size-6 shrink-0 cursor-grab touch-none place-items-center rounded text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing"
                        >
                            <GripVertical className="size-4" />
                        </button>
                    )
                }
                menu={
                    can && (
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                aria-label={`Acciones de la tarjeta ${card.title}`}
                                className="-mr-1 grid size-6 shrink-0 place-items-center rounded text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-muted"
                            >
                                <MoreHorizontal className="size-4" />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuItem onSelect={() => onOpen(card)}>
                                    <Pencil /> Editar tarjeta
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>Mover a…</DropdownMenuLabel>
                                {columns
                                    .filter((c) => c.id !== card.column_id)
                                    .map((c) => (
                                        <DropdownMenuItem key={c.id} onSelect={() => onMove(card, c.id)}>
                                            <ArrowRightLeft /> {c.name}
                                        </DropdownMenuItem>
                                    ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )
                }
            />
        </div>
    );
}
