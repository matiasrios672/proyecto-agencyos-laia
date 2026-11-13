import { router } from '@inertiajs/react';
import { Loader2, Users } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { PersonRef } from '@/types';

function initials(name: string) {
    return name.split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();
}

interface Props {
    clientId: number;
    team: PersonRef[];
    assignable: PersonRef[];
    canAssign: boolean;
}

export function TeamPanel({ clientId, team, assignable, canAssign }: Props) {
    const [selected, setSelected] = useState<number[]>(team.map((t) => t.id));
    const [saving, setSaving] = useState(false);

    const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const dirty = [...selected].sort().join() !== team.map((t) => t.id).sort().join();

    const save = () => {
        setSaving(true);
        router.put(`/clientes/${clientId}/equipo`, { user_ids: selected }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    if (!canAssign) {
        return (
            <Card>
                {team.length === 0 ? (
                    <EmptyState icon={Users} title="Sin equipo asignado" />
                ) : (
                    <ul className="divide-y">
                        {team.map((p) => (
                            <li key={p.id} className="flex items-center gap-3 p-4">
                                <span className="grid size-9 place-items-center rounded-full bg-accent text-xs font-semibold text-accent-foreground">{initials(p.name)}</span>
                                <div>
                                    <p className="text-sm font-medium">{p.name}</p>
                                    <p className="text-xs text-muted-foreground">{p.job_title}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Equipo asignado</CardTitle>
                <CardDescription>Solo las personas marcadas ven este cliente, sus servicios y su bóveda.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-1">
                {assignable.map((p) => (
                    <label key={p.id} className="flex cursor-pointer items-center gap-3 rounded-md p-2.5 transition-colors hover:bg-muted">
                        <input type="checkbox" className="size-4 accent-[var(--primary)]" checked={selected.includes(p.id)} onChange={() => toggle(p.id)} />
                        <span className="grid size-8 place-items-center rounded-full bg-accent text-xs font-semibold text-accent-foreground">{initials(p.name)}</span>
                        <span className="text-sm">
                            <span className="font-medium">{p.name}</span>
                            {p.job_title && <span className="text-muted-foreground"> · {p.job_title}</span>}
                        </span>
                    </label>
                ))}
                <div className="mt-3 flex justify-end">
                    <Button disabled={!dirty || saving} onClick={save}>
                        {saving && <Loader2 className="animate-spin" />}
                        Guardar equipo
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
