import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { FieldError, Input, Label } from '@/components/ui/input';
import GuestLayout from '@/layouts/GuestLayout';

interface Props {
    user: { name: string; email: string };
    action: string;
}

export default function AcceptInvitation({ user, action }: Props) {
    const { data, setData, post, processing, errors } = useForm({ password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post(action);
    };

    return (
        <GuestLayout title={`Bienvenido, ${user.name.split(' ')[0]}`} subtitle="Configurá tu contraseña para empezar">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input id="email" value={user.email} disabled readOnly />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="password">Nueva contraseña</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        required
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">Mínimo 10 caracteres, con letras y números.</p>
                    <FieldError message={errors.password} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="password_confirmation">Repetir contraseña</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        required
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                </div>
                <Button type="submit" disabled={processing}>
                    {processing ? 'Guardando…' : 'Guardar y entrar'}
                </Button>
            </form>
        </GuestLayout>
    );
}
