import { router } from '@inertiajs/react';
import axios from 'axios';
// axios envía el token XSRF automáticamente desde la cookie de Laravel.
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/utils';

interface UserRow {
    id: number;
    name: string;
    role: string | null;
    direct: string[];
    inherited: string[];
}

interface Props {
    catalog: Record<string, Record<string, string>>;
    roles: Record<string, string[]>;
    roleLabels: Record<string, string>;
    users: UserRow[];
}

type Tab = 'roles' | 'users';

export default function Permissions({ catalog, roles, roleLabels, users }: Props) {
    const [tab, setTab] = useState<Tab>('roles');
    const [roleState, setRoleState] = useState(roles);
    const [userState, setUserState] = useState(users);
    const [selected, setSelected] = useState<number | null>(users[0]?.id ?? null);
    const [busy, setBusy] = useState<string | null>(null);

    const toggleRole = async (role: string, permission: string, enabled: boolean) => {
        const prev = roleState;
        setRoleState({ ...roleState, [role]: enabled ? [...roleState[role], permission] : roleState[role].filter((p) => p !== permission) });
        setBusy(`${role}:${permission}`);
        try {
            await axios.post('/admin/permisos/rol', { role, permission, enabled });
            router.reload({ only: ['users'] });
        } catch {
            setRoleState(prev);
            toast.error('No se pudo guardar el permiso.');
        } finally {
            setBusy(null);
        }
    };

    const toggleUser = async (user: UserRow, permission: string, enabled: boolean) => {
        const prev = userState;
        setUserState((s) =>
            s.map((u) =>
                u.id !== user.id
                    ? u
                    : { ...u, direct: enabled ? [...u.direct, permission] : u.direct.filter((p) => p !== permission) },
            ),
        );
        setBusy(`${user.id}:${permission}`);
        try {
            await axios.post(`/admin/permisos/usuario/${user.id}`, { permission, enabled });
        } catch {
            setUserState(prev);
            toast.error('No se pudo guardar el permiso.');
        } finally {
            setBusy(null);
        }
    };

    const user = userState.find((u) => u.id === selected) ?? null;

    return (
        <AppLayout title="Matriz de permisos">
            <div className="mb-5 inline-flex rounded-md border bg-muted p-0.5" role="tablist">
                {(['roles', 'users'] as Tab[]).map((t) => (
                    <button
                        key={t}
                        role="tab"
                        aria-selected={tab === t}
                        onClick={() => setTab(t)}
                        className={cn(
                            'rounded px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors',
                            tab === t && 'bg-card text-foreground shadow-sm',
                        )}
                    >
                        {t === 'roles' ? 'Por rol' : 'Por usuario'}
                    </button>
                ))}
            </div>

            {tab === 'roles' && (
                <Card>
                    <CardHeader>
                        <CardTitle>Permisos por rol</CardTitle>
                        <CardDescription>El Superadministrador siempre tiene acceso total y no se puede restringir.</CardDescription>
                    </CardHeader>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground">
                                    <th className="px-5 py-2.5 font-medium">Permiso</th>
                                    {Object.keys(roleState).map((r) => (
                                        <th key={r} className="px-5 py-2.5 text-center font-medium">
                                            {roleLabels[r]}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {Object.entries(catalog).map(([group, perms]) => (
                                    <GroupRows key={group} group={group} cols={Object.keys(roleState).length + 1}>
                                        {Object.entries(perms).map(([key, label]) => (
                                            <tr key={key} className="border-b last:border-0">
                                                <td className="px-5 py-2.5">
                                                    <div>{label}</div>
                                                    <code className="text-xs text-muted-foreground">{key}</code>
                                                </td>
                                                {Object.keys(roleState).map((r) => (
                                                    <td key={r} className="px-5 py-2.5 text-center">
                                                        <Switch
                                                            aria-label={`${label} para ${roleLabels[r]}`}
                                                            checked={roleState[r].includes(key)}
                                                            disabled={busy === `${r}:${key}`}
                                                            onCheckedChange={(c) => toggleRole(r, key, c)}
                                                        />
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </GroupRows>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}

            {tab === 'users' && (
                <div className="grid gap-5 md:grid-cols-[240px_1fr]">
                    <Card className="h-fit">
                        <CardContent className="flex flex-col gap-0.5 p-2">
                            {userState.map((u) => (
                                <button
                                    key={u.id}
                                    onClick={() => setSelected(u.id)}
                                    className={cn(
                                        'flex flex-col rounded-md px-3 py-2 text-left transition-colors hover:bg-muted',
                                        selected === u.id && 'bg-accent text-accent-foreground hover:bg-accent',
                                    )}
                                >
                                    <span className="text-sm font-medium">{u.name}</span>
                                    <span className="text-xs text-muted-foreground">{u.role ? roleLabels[u.role] : 'Sin rol'}</span>
                                </button>
                            ))}
                        </CardContent>
                    </Card>

                    {user && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{user.name}</CardTitle>
                                <CardDescription>
                                    Los permisos heredados del rol se muestran como <Badge variant="primary">rol</Badge>. Activá uno
                                    extra para otorgarlo solo a esta persona.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-5">
                                {Object.entries(catalog).map(([group, perms]) => (
                                    <div key={group}>
                                        <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{group}</p>
                                        <div className="flex flex-col divide-y rounded-md border">
                                            {Object.entries(perms).map(([key, label]) => {
                                                const inherited = user.inherited.includes(key);
                                                return (
                                                    <div key={key} className="flex items-center justify-between gap-3 px-4 py-2.5">
                                                        <div className="flex items-center gap-2">
                                                            <span>{label}</span>
                                                            {inherited && <Badge variant="primary">rol</Badge>}
                                                        </div>
                                                        <Switch
                                                            aria-label={`${label} para ${user.name}`}
                                                            checked={inherited || user.direct.includes(key)}
                                                            disabled={inherited || busy === `${user.id}:${key}`}
                                                            onCheckedChange={(c) => toggleUser(user, key, c)}
                                                        />
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>
            )}
        </AppLayout>
    );
}

function GroupRows({ group, cols, children }: { group: string; cols: number; children: React.ReactNode }) {
    return (
        <>
            <tr className="bg-muted/50">
                <td colSpan={cols} className="px-5 py-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    {group}
                </td>
            </tr>
            {children}
        </>
    );
}
