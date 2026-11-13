import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FlashNotice } from '@/components/auth/Notice';
import { Button } from '@/components/ui/button';
import { FieldError, Input, Label } from '@/components/ui/input';
import GuestLayout from '@/layouts/GuestLayout';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/login', { onFinish: () => setData('password', '') });
    };

    return (
        <GuestLayout title="Iniciar sesión" subtitle="Accedé al backoffice de Laia">
            <FlashNotice />
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        autoFocus
                        required
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        aria-invalid={!!errors.email}
                    />
                    <FieldError message={errors.email} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <div className="flex items-center justify-between">
                        <Label htmlFor="password">Contraseña</Label>
                        <Link href="/olvide-mi-contrasena" className="text-xs font-medium text-primary hover:underline">
                            ¿Olvidaste tu contraseña?
                        </Link>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        required
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <FieldError message={errors.password} />
                </div>
                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                    <input
                        type="checkbox"
                        className="size-4 accent-[var(--primary)]"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                    />
                    Mantener la sesión iniciada
                </label>
                <Button type="submit" disabled={processing}>
                    {processing ? 'Ingresando…' : 'Ingresar'}
                </Button>
            </form>
        </GuestLayout>
    );
}
