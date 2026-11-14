import { Link, useForm } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { FlashNotice } from '@/components/auth/Notice';
import { Button } from '@/components/ui/button';
import { FieldError, Input, Label } from '@/components/ui/input';
import GuestLayout from '@/layouts/GuestLayout';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '', remember: false });

    const [show, setShow] = useState(false);

    const submit =(e: FormEvent) => {
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
                        inputMode="email"
                        autoCapitalize="none"
                        spellCheck={false}
                        className="h-12 lg:h-9"
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
                    <div className="relative">
                        <Input
                            id="password"
                            type={show ? 'text' : 'password'}
                            autoComplete="current-password"
                            required
                            className="h-12 pr-12 lg:h-9 lg:pr-10"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        <button
                            type="button"
                            onClick={() => setShow((s) => !s)}
                            aria-label={show ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                            className="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-muted-foreground active:text-foreground lg:w-10"
                        >
                            {show ? <EyeOff className="size-5 lg:size-4" /> : <Eye className="size-5 lg:size-4" />}
                        </button>
                    </div>
                    <FieldError message={errors.password} />
                </div>
                <label className="flex items-center gap-3 py-1 text-sm text-muted-foreground">
                    <input
                        type="checkbox"
                        className="size-5 accent-[var(--primary)] lg:size-4"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                    />
                    Mantener la sesión iniciada
                </label>
                <Button type="submit" disabled={processing} className="h-12 text-base shadow-pop active:scale-[0.98] lg:h-9 lg:text-sm">
                    {processing ? 'Ingresando…' : 'Ingresar'}
                </Button>
            </form>
        </GuestLayout>
    );
}
