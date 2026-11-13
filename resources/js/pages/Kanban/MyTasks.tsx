import { Link } from '@inertiajs/react';
import { ListChecks, PartyPopper } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { DueChip, PriorityBadge } from '@/components/kanban/CardView';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { Priority } from '@/types/kanban';

interface Task {
    id: number;
    title: string;
    priority: Priority;
    due_date: string | null;
    overdue: boolean;
    column: string;
    client: { id: number; name: string };
}

interface Props {
    groups: { key: string; label: string; cards: Task[] }[];
    total: number;
}

export default function MyTasks({ groups, total }: Props) {
    const visible = groups.filter((g) => g.cards.length > 0);

    return (
        <AppLayout title="Mis tareas">
            <div className="mb-6">
                <h2 className="font-display text-2xl font-extrabold tracking-tight">Mis tareas</h2>
                <p className="text-sm text-muted-foreground">
                    {total === 0 ? 'No tenés tarjetas pendientes.' : `${total} ${total === 1 ? 'tarjeta pendiente' : 'tarjetas pendientes'} entre todos tus clientes.`}
                </p>
            </div>

            {total > 0 && (
                <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {groups.map((g) => (
                        <div key={g.key} className={cn('rounded-xl border bg-card p-3.5 shadow-xs', g.key === 'vencidas' && g.cards.length > 0 && 'border-danger/40 bg-danger/5')}>
                            <p className={cn('font-display text-2xl font-extrabold tabular-nums', g.key === 'vencidas' && g.cards.length > 0 && 'text-danger')}>{g.cards.length}</p>
                            <p className="text-xs text-muted-foreground">{g.label}</p>
                        </div>
                    ))}
                </div>
            )}

            {total === 0 ? (
                <Card>
                    <EmptyState icon={PartyPopper} title="¡Todo al día!" description="Cuando te asignen una tarjeta en el tablero de un cliente, aparece acá agrupada por vencimiento." />
                </Card>
            ) : (
                <div className="flex flex-col gap-6">
                    {visible.map((group) => (
                        <section key={group.key} aria-labelledby={`g-${group.key}`}>
                            <h3 id={`g-${group.key}`} className={cn('mb-2 flex items-center gap-2 text-sm font-semibold', group.key === 'vencidas' && 'text-danger')}>
                                <ListChecks className="size-4" /> {group.label}
                                <Badge variant={group.key === 'vencidas' ? 'danger' : 'default'}>{group.cards.length}</Badge>
                            </h3>
                            <Card className="overflow-hidden">
                                <ul className="divide-y">
                                    {group.cards.map((t) => (
                                        <li key={t.id}>
                                            <Link href={`/clientes/${t.client.id}/tablero`} className={cn('flex flex-wrap items-center gap-x-5 gap-y-1.5 border-l-4 p-4 transition-colors hover:bg-muted/50', t.priority === 'alta' ? 'border-l-danger' : t.priority === 'media' ? 'border-l-peach' : 'border-l-input')}>
                                                <div className="min-w-56 flex-1">
                                                    <p className="font-medium">{t.title}</p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {t.client.name} · {t.column}
                                                    </p>
                                                </div>
                                                <PriorityBadge priority={t.priority} />
                                                <DueChip card={{ due_date: t.due_date, overdue: t.overdue, completed_at: null }} />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        </section>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
