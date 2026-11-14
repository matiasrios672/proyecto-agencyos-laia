import { useForm } from '@inertiajs/react';
import { Briefcase, Building2, CalendarDays, Landmark, Loader2, Mail } from 'lucide-react';
import type { FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { BankDetailsForm, type BankData } from '@/components/profile/BankDetailsForm';
import { TwoFactorCard, type TwoFactorState } from '@/components/profile/TwoFactorCard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldError, Input, Label } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout';

interface Props {
    profile: { name: string; email: string; job_title: string | null; role_label: string | null; member_since: string | null; clients_count: number };
    twoFactor: TwoFactorState;
    recoveryCodes: string[] | null;
    bank: BankData;
}

export default function Edit({ profile, twoFactor, recoveryCodes, bank }: Props) {
    const info = useForm({ name: profile.name, job_title: profile.job_title ?? '' });
    const pass = useForm({ current_password: '', password: '', password_confirmation: '' });

    const saveInfo = (e: FormEvent) => {
        e.preventDefault();
        info.put('/perfil', { preserveScroll: true });
    };

    const savePass = (e: FormEvent) => {
        e.preventDefault();
        pass.put('/perfil/contrasena', { preserveScroll: true, onSuccess: () => pass.reset() });
    };

    return (
        <AppLayout title="Mi perfil">
            <div className="grid gap-6 lg:grid-cols-[300px_1fr]">
                <Card className="h-fit">
                    <CardContent className="flex flex-col items-center gap-4 p-7 text-center">
                        <Avatar name={profile.name} className="size-20 text-2xl" />
                        <div>
                            <p className="font-display text-lg font-bold">{profile.name}</p>
                            <p className="text-sm text-muted-foreground">{profile.job_title ?? 'Sin puesto'}</p>
                        </div>
                        <Badge variant="primary">{profile.role_label}</Badge>
                        <dl className="mt-1 flex w-full flex-col gap-3 border-t pt-4 text-left text-sm">
                            <div className="flex items-center gap-2.5 text-muted-foreground"><Mail className="size-4" /><dd className="truncate text-foreground">{profile.email}</dd></div>
                            <div className="flex items-center gap-2.5 text-muted-foreground"><Building2 className="size-4" /><dd className="text-foreground">{profile.clients_count} {profile.clients_count === 1 ? 'cliente asignado' : 'clientes asignados'}</dd></div>
                            {profile.member_since && (
                                <div className="flex items-center gap-2.5 text-muted-foreground">
                                    <CalendarDays className="size-4" />
                                    <dd className="text-foreground">Desde {new Date(profile.member_since).toLocaleDateString('es-AR', { month: 'long', year: 'numeric' })}</dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                <div className="flex flex-col gap-6">
                    <Card>
                        <form onSubmit={saveInfo}>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2"><Briefcase className="size-4 text-muted-foreground" /> Datos personales</CardTitle>
                                <CardDescription>El email y el rol los administra Dirección.</CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2">
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="name">Nombre y apellido</Label>
                                    <Input id="name" required value={info.data.name} onChange={(e) => info.setData('name', e.target.value)} />
                                    <FieldError message={info.errors.name} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="job_title">Puesto</Label>
                                    <Input id="job_title" value={info.data.job_title} onChange={(e) => info.setData('job_title', e.target.value)} />
                                    <FieldError message={info.errors.job_title} />
                                </div>
                                <div className="flex flex-col gap-1.5 sm:col-span-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input id="email" value={profile.email} disabled readOnly />
                                </div>
                                <div className="flex justify-end sm:col-span-2">
                                    <Button type="submit" disabled={info.processing || !info.isDirty}>
                                        {info.processing && <Loader2 className="animate-spin" />} Guardar cambios
                                    </Button>
                                </div>
                            </CardContent>
                        </form>
                    </Card>

                    <Card>
                        <form onSubmit={savePass}>
                            <CardHeader>
                                <CardTitle>Contraseña</CardTitle>
                                <CardDescription>Al cambiarla se cerrarán tus otras sesiones abiertas.</CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2">
                                <div className="flex flex-col gap-1.5 sm:col-span-2">
                                    <Label htmlFor="current_password">Contraseña actual</Label>
                                    <Input id="current_password" type="password" autoComplete="current-password" required value={pass.data.current_password} onChange={(e) => pass.setData('current_password', e.target.value)} />
                                    <FieldError message={pass.errors.current_password} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="password">Nueva contraseña</Label>
                                    <Input id="password" type="password" autoComplete="new-password" required value={pass.data.password} onChange={(e) => pass.setData('password', e.target.value)} />
                                    <p className="text-xs text-muted-foreground">Mínimo 10 caracteres, con letras y números.</p>
                                    <FieldError message={pass.errors.password} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="password_confirmation">Repetir contraseña</Label>
                                    <Input id="password_confirmation" type="password" autoComplete="new-password" required value={pass.data.password_confirmation} onChange={(e) => pass.setData('password_confirmation', e.target.value)} />
                                </div>
                                <div className="flex justify-end sm:col-span-2">
                                    <Button type="submit" disabled={pass.processing}>
                                        {pass.processing && <Loader2 className="animate-spin" />} Actualizar contraseña
                                    </Button>
                                </div>
                            </CardContent>
                        </form>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2"><Landmark className="size-4 text-muted-foreground" /> Mis datos bancarios</CardTitle>
                            <CardDescription>Los clientes de los servicios que tenés a cargo transfieren a esta cuenta: se muestra en sus correos de cobro. Si lo dejás vacío, se usa la cuenta de la agencia.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <BankDetailsForm action="/perfil/datos-bancarios" initial={bank} idPrefix="me" />
                        </CardContent>
                    </Card>

                    <TwoFactorCard twoFactor={twoFactor} recoveryCodes={recoveryCodes} />
                </div>
            </div>
        </AppLayout>
    );
}
