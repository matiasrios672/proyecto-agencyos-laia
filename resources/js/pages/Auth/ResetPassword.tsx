import { Link, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { Notice } from '@/components/auth/Notice';
import { Button } from '@/components/ui/button';
import { FieldError, Input, Label } from '@/components/ui/input';
import GuestLayout from '@/layouts/GuestLayout';

interface Props {
    token: string;
    email: string;
}

export default function ResetPassword({ token, email }: Props) {
    const { data, setData, post, processing, errors } = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/restablecer-contrasena', { onFinish: () => setData((d) => ({ ...d, password: '', password_confirmation: '' })) });
    };

    return (
        <GuestLayout title="Elegí una contraseña nueva" subtitle="El enlace sirve una sola vez y vence a los 60 minutos">
            {errors.token && (
                <Notice tone="error">
                    {errors.token}{' '}
                    <Link href="/olvide-mi-contrasena" className="font-medium text-primary underline-offset-2 hover:underline">
                        Pedir un enlace nuevo
                    </Link>
                </Notice>
            )}
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input id="email" type="email" autoComplete="username" required value={data.email} onChange={(e) => setData('email', e.target.value)} aria-invalid={!!errors.email} />
                    <FieldError message={errors.email} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="password">Nueva contraseña</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        autoFocus
                        required
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        aria-invalid={!!errors.password}
                    />
                    <p className="text-xs text-muted-foreground">Mínimo 10 caracteres, con letras y números. Tiene que ser distinta de la actual.</p>
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
                    {processing && <Loader2 className="animate-spin" />}
                    Guardar contraseña
                </Button>
                <p className="text-center text-xs text-muted-foreground">Al cambiarla se cierran tus otras sesiones abiertas.</p>
            </form>
        </GuestLayout>
    );
}
