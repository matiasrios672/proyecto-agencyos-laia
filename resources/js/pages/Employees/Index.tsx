import { router, useForm } from '@inertiajs/react';
import { Loader2, MailPlus, Pencil, Plus, Send, ShieldCheck, ShieldOff, UserCheck, UserX, UsersRound } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { UserStateBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout';
import type { Options } from '@/types';

interface Employee {
    id: number;
    name: string;
    email: string;
    job_title: string | null;
    role: string | null;
    role_label: string | null;
    state: 'active' | 'pending' | 'inactive';
    two_factor: boolean;
    clients_count: number;
    invited_at: string | null;
    editable: boolean;
}

interface Props {
    employees: Employee[];
    assignableRoles: Options;
    invitationHours: number;
}

export default function Index({ employees, assignableRoles, invitationHours }: Props) {
    const [dialog, setDialog] = useState<Employee | 'new' | null>(null);
    const [toggling, setToggling] = useState<Employee | null>(null);
    const [resetting2fa, setResetting2fa] = useState<Employee | null>(null);
    const [busy, setBusy] = useState(false);

    return (
        <AppLayout title="Equipo">
            <div className="mb-5 flex items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    Al dar de alta a alguien le llega un correo con un enlace personal (vence en {invitationHours} h) para configurar su contraseña.
                </p>
                <Button onClick={() => setDialog('new')}>
                    <Plus /> Invitar empleado
                </Button>
            </div>

            <Card className="overflow-hidden">
                {employees.length === 0 ? (
                    <EmptyState icon={UsersRound} title="Todavía no hay equipo" description="Invitá a tu primer empleado para asignarle clientes." />
                ) : (
                    <ul className="divide-y">
                        {employees.map((e) => (
                            <li key={e.id} className="flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
                                <div className="min-w-48 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={`font-medium ${e.state === 'inactive' ? 'text-muted-foreground line-through' : ''}`}>{e.name}</span>
                                        <Badge variant="primary">{e.role_label}</Badge>
                                        {e.two_factor && (
                                            <Badge variant="success" title="Tiene verificación en dos pasos activa">
                                                <ShieldCheck className="size-3" /> 2FA
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-sm text-muted-foreground">{e.job_title ? `${e.job_title} · ` : ''}{e.email}</p>
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    {e.clients_count} {e.clients_count === 1 ? 'cliente' : 'clientes'}
                                </div>
                                <UserStateBadge state={e.state} />
                                {e.editable && (
                                    <div className="flex gap-1">
                                        {e.state === 'pending' && (
                                            <Button variant="ghost" size="sm" onClick={() => router.post(`/equipo/${e.id}/invitacion`, {}, { preserveScroll: true })}>
                                                <Send /> Reenviar
                                            </Button>
                                        )}
                                        {e.two_factor && (
                                            <Button variant="ghost" size="icon" className="size-8" aria-label={`Restablecer la verificación en dos pasos de ${e.name}`} title="Restablecer 2FA (dispositivo perdido)" onClick={() => setResetting2fa(e)}>
                                                <ShieldOff />
                                            </Button>
                                        )}
                                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Editar a ${e.name}`} onClick={() => setDialog(e)}>
                                            <Pencil />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8"
                                            aria-label={e.state === 'inactive' ? `Reactivar a ${e.name}` : `Dar de baja a ${e.name}`}
                                            onClick={() => setToggling(e)}
                                        >
                                            {e.state === 'inactive' ? <UserCheck /> : <UserX />}
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <EmployeeDialog target={dialog} roles={assignableRoles} onClose={() => setDialog(null)} />

            <ConfirmDialog
                open={resetting2fa !== null}
                onOpenChange={(o) => !o && setResetting2fa(null)}
                title="¿Restablecer la verificación en dos pasos?"
                description={`Se desactiva el 2FA de ${resetting2fa?.name ?? ''} (por ejemplo, porque perdió el celular) y se le avisa. Podrá ingresar solo con su contraseña y volver a activarlo desde su perfil. Queda registrado en la auditoría.`}
                confirmLabel="Restablecer 2FA"
                destructive
                busy={busy}
                onConfirm={() => {
                    if (!resetting2fa) return;
                    setBusy(true);
                    router.post(`/equipo/${resetting2fa.id}/2fa/restablecer`, {}, { preserveScroll: true, onFinish: () => { setBusy(false); setResetting2fa(null); } });
                }}
            />

            <ConfirmDialog
                open={toggling !== null}
                onOpenChange={(o) => !o && setToggling(null)}
                title={toggling?.state === 'inactive' ? '¿Reactivar empleado?' : '¿Dar de baja a este empleado?'}
                description={
                    toggling?.state === 'inactive'
                        ? `${toggling?.name} podrá volver a iniciar sesión.`
                        : `${toggling?.name} perderá el acceso de inmediato y se cerrará su sesión. Sus clientes asignados se conservan.`
                }
                confirmLabel={toggling?.state === 'inactive' ? 'Reactivar' : 'Dar de baja'}
                destructive={toggling?.state !== 'inactive'}
                busy={busy}
                onConfirm={() => {
                    if (!toggling) return;
                    setBusy(true);
                    router.post(`/equipo/${toggling.id}/estado`, {}, { preserveScroll: true, onFinish: () => { setBusy(false); setToggling(null); } });
                }}
            />
        </AppLayout>
    );
}

function EmployeeDialog({ target, roles, onClose }: { target: Employee | 'new' | null; roles: Options; onClose: () => void }) {
    const employee = target && target !== 'new' ? target : null;
    const form = useForm({ name: '', email: '', job_title: '', role: 'empleado' });

    useEffect(() => {
        if (target === null) return;
        form.clearErrors();
        form.setData({ name: employee?.name ?? '', email: employee?.email ?? '', job_title: employee?.job_title ?? '', role: employee?.role ?? 'empleado' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (employee) form.put(`/equipo/${employee.id}`, opts);
        else form.post('/equipo', opts);
    };

    return (
        <Dialog open={target !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>{employee ? 'Editar empleado' : 'Invitar empleado'}</DialogTitle>
                        <DialogDescription>{employee ? 'Cambiá sus datos o su rol.' : 'Le enviaremos un correo para que configure su contraseña.'}</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="e-name">Nombre y apellido *</Label>
                            <Input id="e-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <FieldError message={form.errors.name} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="e-email">Email *</Label>
                            <Input id="e-email" type="email" required disabled={!!employee} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            <FieldError message={form.errors.email} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="e-title">Puesto</Label>
                                <Input id="e-title" value={form.data.job_title} onChange={(e) => form.setData('job_title', e.target.value)} placeholder="Community Manager" />
                                <FieldError message={form.errors.job_title} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="e-role">Rol</Label>
                                <Select id="e-role" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                    {Object.entries(roles).map(([v, l]) => (
                                        <option key={v} value={v}>{l}</option>
                                    ))}
                                </Select>
                                <FieldError message={form.errors.role} />
                            </div>
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Loader2 className="animate-spin" /> : employee ? null : <MailPlus />}
                            {employee ? 'Guardar' : 'Enviar invitación'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
