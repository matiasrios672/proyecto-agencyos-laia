import { router, useForm } from '@inertiajs/react';
import { Loader2, Trash2 } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select, Textarea } from '@/components/ui/select';
import type { Options } from '@/types';
import type { KanbanCardData, KanbanColumnData, TeamMember } from '@/types/kanban';

export type DialogTarget = { mode: 'create'; columnId: number; dueDate?: string } | { mode: 'edit'; card: KanbanCardData } | null;

interface Props {
    target: DialogTarget;
    clientId: number;
    columns: KanbanColumnData[];
    team: TeamMember[];
    priorities: Options;
    onClose: () => void;
}

const blank = { title: '', description: '', assigned_user_id: '' as number | '', due_date: '', priority: 'media', column_id: '' as number | '' };

export function CardDialog({ target, clientId, columns, team, priorities, onClose }: Props) {
    const form = useForm(blank);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const card = target?.mode === 'edit' ? target.card : null;
    const base = `/clientes/${clientId}/tablero/tarjetas`;

    useEffect(() => {
        if (target === null) return;
        form.clearErrors();
        form.setData(
            target.mode === 'edit'
                ? {
                      title: target.card.title,
                      description: target.card.description ?? '',
                      assigned_user_id: target.card.assignee?.id ?? '',
                      due_date: target.card.due_date ?? '',
                      priority: target.card.priority,
                      column_id: target.card.column_id,
                  }
                : { ...blank, column_id: target.columnId, due_date: target.dueDate ?? '' },
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            title: d.title,
            description: d.description || null,
            assigned_user_id: d.assigned_user_id === '' ? null : d.assigned_user_id,
            due_date: d.due_date || null,
            priority: d.priority,
            // La columna solo se elige al crear; para mover una tarjeta existente se arrastra o se usa "Mover a…".
            ...(card ? {} : { column_id: d.column_id === '' ? null : d.column_id }),
        }));
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (card) form.put(`${base}/${card.id}`, opts);
        else form.post(base, opts);
    };

    return (
        <>
            <Dialog open={target !== null} onOpenChange={(o) => !o && onClose()}>
                <DialogContent>
                    <form onSubmit={submit} className="flex min-h-0 flex-col">
                        <DialogHeader>
                            <DialogTitle>{card ? 'Editar tarjeta' : 'Nueva tarjeta'}</DialogTitle>
                            <DialogDescription>El responsable recibe un aviso en su campana cuando se le asigna la tarjeta.</DialogDescription>
                        </DialogHeader>
                        <DialogBody>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="k-title">Título *</Label>
                                <Input id="k-title" required autoFocus value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Reel de lanzamiento" />
                                <FieldError message={form.errors.title} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="k-desc">Descripción</Label>
                                <Textarea id="k-desc" rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                                <FieldError message={form.errors.description} />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="k-assignee">Responsable</Label>
                                    <Select id="k-assignee" value={form.data.assigned_user_id} onChange={(e) => form.setData('assigned_user_id', e.target.value ? Number(e.target.value) : '')}>
                                        <option value="">Sin asignar</option>
                                        {team.map((m) => (
                                            <option key={m.id} value={m.id}>
                                                {m.name}
                                                {m.job_title ? ` · ${m.job_title}` : ''}
                                            </option>
                                        ))}
                                    </Select>
                                    <FieldError message={form.errors.assigned_user_id} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="k-due">Fecha límite</Label>
                                    <Input id="k-due" type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                                    <FieldError message={form.errors.due_date} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="k-priority">Prioridad</Label>
                                    <Select id="k-priority" value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                                        {Object.entries(priorities).map(([v, l]) => (
                                            <option key={v} value={v}>
                                                {l}
                                            </option>
                                        ))}
                                    </Select>
                                    <FieldError message={form.errors.priority} />
                                </div>
                                {!card && (
                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="k-column">Columna</Label>
                                        <Select id="k-column" value={form.data.column_id} onChange={(e) => form.setData('column_id', Number(e.target.value))}>
                                            {columns.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </Select>
                                        <FieldError message={form.errors.column_id} />
                                    </div>
                                )}
                            </div>
                        </DialogBody>
                        <DialogFooter className="justify-between">
                            {card ? (
                                <Button type="button" variant="ghost" className="text-danger hover:bg-danger/10 hover:text-danger" onClick={() => setConfirmDelete(true)}>
                                    <Trash2 /> Eliminar
                                </Button>
                            ) : (
                                <span />
                            )}
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" onClick={onClose}>
                                    Cancelar
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    {form.processing && <Loader2 className="animate-spin" />} Guardar
                                </Button>
                            </div>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title="¿Eliminar esta tarjeta?"
                description={`Se elimina "${card?.title ?? ''}" del tablero. No se puede deshacer.`}
                confirmLabel="Eliminar"
                destructive
                busy={deleting}
                onConfirm={() => {
                    if (!card) return;
                    setDeleting(true);
                    router.delete(`${base}/${card.id}`, {
                        preserveScroll: true,
                        onSuccess: () => {
                            setConfirmDelete(false);
                            onClose();
                        },
                        onFinish: () => setDeleting(false),
                    });
                }}
            />
        </>
    );
}
