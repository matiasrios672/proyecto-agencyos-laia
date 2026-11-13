import { Link, useForm } from '@inertiajs/react';
import { KeyRound, Loader2, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { FlashNotice } from '@/components/auth/Notice';
import { Button } from '@/components/ui/button';
import { FieldError, Input, Label } from '@/components/ui/input';
import GuestLayout from '@/layouts/GuestLayout';

export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ code: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/verificacion-dos-pasos', { onError: () => setData('code', '') });
    };

    const switchMode = () => {
        setRecovery((r) => !r);
        reset();
        clearErrors();
    };

    return (
        <GuestLayout
            title="Verificación en dos pasos"
            subtitle={recovery ? 'Ingresá uno de tus códigos de recuperación' : 'Ingresá el código de 6 dígitos de tu app de autenticación'}
        >
            <FlashNotice />
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="code">{recovery ? 'Código de recuperación' : 'Código de verificación'}</Label>
                    <Input
                        id="code"
                        // Un solo campo: el servidor distingue por el formato (6 dígitos = TOTP; si no, recuperación).
                        inputMode={recovery ? 'text' : 'numeric'}
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        spellCheck={false}
                        maxLength={recovery ? 20 : 7}
                        placeholder={recovery ? 'xxxxx-xxxxx' : '123 456'}
                        className={recovery ? 'font-mono tracking-wider' : 'text-center font-mono text-lg tracking-[0.35em]'}
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        aria-invalid={!!errors.code}
                    />
                    <FieldError message={errors.code} />
                </div>
                <Button type="submit" disabled={processing || data.code.trim() === ''}>
                    {processing ? <Loader2 className="animate-spin" /> : <ShieldCheck />}
                    Verificar e ingresar
                </Button>
                <button
                    type="button"
                    onClick={switchMode}
                    className="inline-flex items-center justify-center gap-1.5 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <KeyRound className="size-4" />
                    {recovery ? 'Usar el código de mi app' : 'Perdí mi dispositivo: usar un código de recuperación'}
                </button>
                <Link href="/login" className="text-center text-sm text-muted-foreground hover:text-foreground">
                    Volver a iniciar sesión
                </Link>
            </form>
        </GuestLayout>
    );
}
