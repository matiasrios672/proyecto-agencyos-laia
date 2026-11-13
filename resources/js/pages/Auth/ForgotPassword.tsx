import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { FlashNotice } from '@/components/auth/Notice';
import { Button } from '@/components/ui/button';
import { FieldError, Input, Label } from '@/components/ui/input';
import GuestLayout from '@/layouts/GuestLayout';

export default function ForgotPassword() {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/olvide-mi-contrasena', { preserveScroll: true });
    };

    return (
        <GuestLayout title="Recuperar contraseña" subtitle="Ingresá tu email y te enviamos un enlace para elegir una nueva">
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
                <Button type="submit" disabled={processing}>
                    {processing && <Loader2 className="animate-spin" />}
                    Enviarme el enlace
                </Button>
                <Link href="/login" className="inline-flex items-center justify-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> Volver a iniciar sesión
                </Link>
            </form>
        </GuestLayout>
    );
}
