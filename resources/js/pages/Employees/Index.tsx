import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { Check, Copy, KeyRound, Loader2, MailPlus, Pencil, Plus, Send, ShieldCheck, ShieldOff, UserCheck, UserX, UsersRound } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
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
    const [passwordFor, setPasswordFor] = useState<Employee | null>(null);
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
                                        {e.state !== 'inactive' && (
                                            <Button variant="ghost" size="icon" className="size-8" aria-label={`Contraseña de ${e.name}`} title="Generar o definir una contraseña nueva" onClick={() => setPasswordFor(e)}>
                                                <KeyRound />
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
            <PasswordDialog target={passwordFor} onClose={() => setPasswordFor(null)} />

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

/** Cambiar la contraseña de alguien del equipo: generar una (se ve una sola vez) o definir una. La actual no se puede ver (está cifrada). */
function PasswordDialog({ target, onClose }: { target: Employee | null; onClose: () => void }) {
    const [mode, setMode] = useState<'generate' | 'set'>('generate');
    const [generated, setGenerated] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const [busy, setBusy] = useState(false);
    const [fields, setFields] = useState({ password: '', password_confirmation: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});

    // Al abrir o cerrar se borra todo: la clave generada no queda en pantalla.
    useEffect(() => {
        setMode('generate');
        setGenerated(null);
        setCopied(false);
        setFields({ password: '', password_confirmation: '' });
        setErrors({});
    }, [target]);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!target) return;
        setBusy(true);
        setErrors({});
        try {
            const { data } = await axios.post<{ password?: string }>(`/equipo/${target.id}/clave`, mode === 'generate' ? { mode } : { mode, ...fields });
            if (data.password) {
                setGenerated(data.password);
            } else {
                toast.success(`Contraseña de ${target.name} actualizada.`);
                onClose();
            }
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                const e422 = err.response.data as { message?: string; errors?: Record<string, string[]> };
                setErrors(Object.fromEntries(Object.entries(e422.errors ?? { form: [e422.message ?? 'No se pudo cambiar la contraseña.'] }).map(([k, v]) => [k, v[0]])));
            } else {
                setErrors({ form: 'No se pudo cambiar la contraseña. Probá de nuevo.' });
            }
        } finally {
            setBusy(false);
        }
    };

    const copy = async () => {
        if (!generated) return;
        try {
            await navigator.clipboard.writeText(generated);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('No se pudo copiar: seleccionala y copiala a mano.');
        }
    };

    return (
        <Dialog open={target !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Contraseña de {target?.name}</DialogTitle>
                        <DialogDescription>
                            La contraseña actual no se puede ver: se guarda cifrada, ni siquiera nosotros podemos leerla. Podés generar una nueva o definir una.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        {generated ? (
                            <div className="flex flex-col gap-3">
                                <Label>Contraseña nueva</Label>
                                <div className="flex items-center gap-2">
                                    <code className="flex-1 select-all rounded-md border bg-muted px-3 py-2 text-center font-mono text-lg tracking-wider">{generated}</code>
                                    <Button type="button" variant="outline" onClick={copy} aria-label="Copiar contraseña">
                                        {copied ? <Check /> : <Copy />} {copied ? 'Copiada' : 'Copiar'}
                                    </Button>
                                </div>
                                <p className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm">
                                    Se muestra <strong>una sola vez</strong>: al cerrar esta ventana no hay forma de volver a verla. Pasásela por un medio seguro; {target?.name} ya recibió un aviso del cambio (sin la clave) y puede cambiarla desde su perfil.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1" role="tablist">
                                    {([['generate', 'Generar una nueva'], ['set', 'Definir una']] as const).map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            role="tab"
                                            aria-selected={mode === value}
                                            onClick={() => { setMode(value); setErrors({}); }}
                                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${mode === value ? 'bg-card shadow-xs' : 'text-muted-foreground hover:text-foreground'}`}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                                {mode === 'generate' ? (
                                    <p className="text-sm text-muted-foreground">Se crea una clave aleatoria de 14 caracteres (sin letras que se confunden) y la ves en pantalla para copiarla.</p>
                                ) : (
                                    <>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="pw-new">Contraseña nueva</Label>
                                            <Input id="pw-new" type="password" autoComplete="new-password" value={fields.password} onChange={(e) => setFields({ ...fields, password: e.target.value })} />
                                            <p className="text-xs text-muted-foreground">Mínimo 10 caracteres, con letras y números.</p>
                                            <FieldError message={errors.password} />
                                        </div>
                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="pw-conf">Repetila</Label>
                                            <Input id="pw-conf" type="password" autoComplete="new-password" value={fields.password_confirmation} onChange={(e) => setFields({ ...fields, password_confirmation: e.target.value })} />
                                        </div>
                                    </>
                                )}
                                <FieldError message={errors.form} />
                            </>
                        )}
                    </DialogBody>
                    <DialogFooter>
                        {generated ? (
                            <Button type="button" onClick={onClose}>Listo, ya la copié</Button>
                        ) : (
                            <>
                                <Button type="button" variant="ghost" onClick={onClose}>Cancelar</Button>
                                <Button type="submit" disabled={busy || (mode === 'set' && !fields.password)}>
                                    {busy ? <Loader2 className="animate-spin" /> : <KeyRound />} {mode === 'generate' ? 'Generar contraseña' : 'Guardar contraseña'}
                                </Button>
                            </>
                        )}
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
