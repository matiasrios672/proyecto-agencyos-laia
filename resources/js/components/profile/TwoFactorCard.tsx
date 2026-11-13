import { router, useForm } from '@inertiajs/react';
import { Check, Copy, Download, KeyRound, Loader2, RefreshCw, ShieldAlert, ShieldCheck, ShieldOff } from 'lucide-react';
import QRCode from 'qrcode';
import { useEffect, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';

export interface TwoFactorState {
    enabled: boolean;
    pending: boolean;
    required: boolean;
    recovery_remaining: number;
    secret: string | null;
    otpauth: string | null;
}

interface Props {
    twoFactor: TwoFactorState;
    recoveryCodes: string[] | null;
}

/** Verificación en dos pasos (TOTP): alta con QR, códigos de recuperación y desactivación con contraseña. */
export function TwoFactorCard({ twoFactor, recoveryCodes }: Props) {
    const [passwordDialog, setPasswordDialog] = useState<'disable' | 'regenerate' | null>(null);

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2">
                        <ShieldCheck className="size-4 text-muted-foreground" /> Verificación en dos pasos
                    </CardTitle>
                    {twoFactor.enabled && <Badge variant="success">Activada</Badge>}
                    {twoFactor.pending && <Badge variant="warning">Alta sin confirmar</Badge>}
                    {!twoFactor.enabled && !twoFactor.pending && <Badge>Desactivada</Badge>}
                </div>
                <CardDescription>
                    Además de la contraseña, te pedimos un código de 6 dígitos de una app (Google Authenticator, Authy, 1Password…). Protege tu cuenta aunque alguien conozca tu clave.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
                {twoFactor.required && !twoFactor.enabled && (
                    <div role="alert" className="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/10 px-3.5 py-3 text-sm">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                        <p>Tu rol <strong>exige</strong> la verificación en dos pasos. Activala para seguir usando Agency OS.</p>
                    </div>
                )}

                {recoveryCodes && recoveryCodes.length > 0 && <RecoveryCodes codes={recoveryCodes} />}

                {!twoFactor.enabled && !twoFactor.pending && (
                    <div>
                        <Button onClick={() => router.post('/perfil/2fa', {}, { preserveScroll: true })}>
                            <ShieldCheck /> Activar verificación en dos pasos
                        </Button>
                    </div>
                )}

                {twoFactor.pending && twoFactor.secret && twoFactor.otpauth && <Setup secret={twoFactor.secret} otpauth={twoFactor.otpauth} />}

                {twoFactor.enabled && (
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-muted-foreground">
                            Te quedan <strong className={twoFactor.recovery_remaining <= 2 ? 'text-warning' : 'text-foreground'}>{twoFactor.recovery_remaining}</strong>{' '}
                            {twoFactor.recovery_remaining === 1 ? 'código de recuperación' : 'códigos de recuperación'} (cada uno sirve una sola vez).
                            {twoFactor.recovery_remaining <= 2 && ' Generá códigos nuevos pronto.'}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={() => setPasswordDialog('regenerate')}>
                                <RefreshCw /> Generar códigos nuevos
                            </Button>
                            <Button variant="outline" className="text-danger hover:bg-danger/10 hover:text-danger" disabled={twoFactor.required} title={twoFactor.required ? 'Tu rol exige la verificación en dos pasos' : undefined} onClick={() => setPasswordDialog('disable')}>
                                <ShieldOff /> Desactivar
                            </Button>
                        </div>
                    </div>
                )}
            </CardContent>

            <PasswordDialog mode={passwordDialog} onClose={() => setPasswordDialog(null)} />
        </Card>
    );
}

/** Alta pendiente: QR (generado en el navegador, el secreto no pasa por ningún servicio externo) + confirmación con un código. */
function Setup({ secret, otpauth }: { secret: string; otpauth: string }) {
    const [qr, setQr] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const form = useForm({ code: '' });

    useEffect(() => {
        let alive = true;
        QRCode.toDataURL(otpauth, { width: 192, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#162660', light: '#ffffff' } })
            .then((url) => alive && setQr(url))
            .catch(() => alive && setQr(null));

        return () => {
            alive = false;
        };
    }, [otpauth]);

    const copySecret = async () => {
        try {
            await navigator.clipboard.writeText(secret);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('No se pudo copiar. Seleccionalo y copialo a mano.');
        }
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/perfil/2fa/confirmar', { preserveScroll: true, onError: () => form.setData('code', '') });
    };

    return (
        <div className="grid gap-6 md:grid-cols-[auto_1fr]">
            <div className="flex flex-col items-center gap-2">
                <div className="grid size-48 place-items-center rounded-lg border bg-white p-1">
                    {qr ? <img src={qr} alt="Código QR para agregar Agency OS a tu app de autenticación" width={192} height={192} /> : <Loader2 className="size-6 animate-spin text-muted-foreground" />}
                </div>
                <p className="text-xs text-muted-foreground">Escanealo con tu app</p>
            </div>

            <div className="flex flex-col gap-4">
                <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                    <li>Abrí tu app de autenticación y agregá una cuenta nueva escaneando el QR.</li>
                    <li>¿No podés escanear? Ingresá esta clave a mano:</li>
                </ol>
                <div className="flex items-center gap-2">
                    <code className="min-w-0 flex-1 select-all break-all rounded-md bg-muted px-3 py-2 font-mono text-sm tracking-wider">{secret}</code>
                    <Button type="button" variant="outline" size="icon" aria-label="Copiar la clave" onClick={copySecret}>
                        {copied ? <Check /> : <Copy />}
                    </Button>
                </div>
                <form onSubmit={submit} className="flex flex-col gap-3">
                    <div className="flex flex-col gap-1.5 sm:max-w-xs">
                        <Label htmlFor="tf-code">3. Ingresá el código de 6 dígitos que te muestra la app</Label>
                        <Input
                            id="tf-code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={7}
                            placeholder="123 456"
                            className="font-mono text-lg tracking-[0.3em]"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                            aria-invalid={!!form.errors.code}
                        />
                        <FieldError message={form.errors.code} />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={form.processing || form.data.code.trim() === ''}>
                            {form.processing && <Loader2 className="animate-spin" />} Confirmar y activar
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => router.delete('/perfil/2fa/pendiente', { preserveScroll: true })}>
                            Cancelar
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

/** Códigos de recuperación recién generados: se muestran una sola vez. */
function RecoveryCodes({ codes }: { codes: string[] }) {
    const [hidden, setHidden] = useState(false);
    const [copied, setCopied] = useState(false);

    if (hidden) return null;

    const text = ['Códigos de recuperación de Agency OS (Laia Estudio Creativo Digital)', 'Cada código sirve una sola vez. Guardalos en un lugar seguro.', '', ...codes].join('\n');

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('No se pudo copiar. Descargalos como archivo.');
        }
    };

    const download = () => {
        const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'agency-os-codigos-de-recuperacion.txt';
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <section aria-labelledby="rc-title" className="rounded-lg border border-warning/40 bg-warning/5 p-4">
            <h3 id="rc-title" className="flex items-center gap-2 text-sm font-semibold">
                <KeyRound className="size-4 text-warning" /> Tus códigos de recuperación
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
                Guardalos <strong className="text-foreground">ahora</strong>: no se vuelven a mostrar. Si perdés tu celular, cada código te deja entrar una vez.
            </p>
            <ul className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
                {codes.map((c) => (
                    <li key={c} className="select-all rounded-md border bg-card px-2 py-1.5 text-center font-mono text-sm tracking-wide">
                        {c}
                    </li>
                ))}
            </ul>
            <div className="mt-4 flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" onClick={copy}>
                    {copied ? <Check /> : <Copy />} {copied ? 'Copiados' : 'Copiar'}
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={download}>
                    <Download /> Descargar .txt
                </Button>
                <Button type="button" size="sm" className="ml-auto" onClick={() => setHidden(true)}>
                    Ya los guardé
                </Button>
            </div>
        </section>
    );
}

/** Operaciones sensibles (desactivar, regenerar códigos): piden la contraseña actual. */
function PasswordDialog({ mode, onClose }: { mode: 'disable' | 'regenerate' | null; onClose: () => void }) {
    const form = useForm({ password: '' });

    useEffect(() => {
        if (mode) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mode]);

    const disable = mode === 'disable';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onClose() };
        if (disable) form.delete('/perfil/2fa', opts);
        else form.post('/perfil/2fa/codigos', opts);
    };

    return (
        <Dialog open={mode !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>{disable ? 'Desactivar la verificación en dos pasos' : 'Generar códigos de recuperación nuevos'}</DialogTitle>
                        <DialogDescription>
                            {disable ? 'Tu cuenta volverá a protegerse solo con la contraseña.' : 'Los códigos anteriores dejan de servir. Confirmá con tu contraseña.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="tf-password">Contraseña actual</Label>
                            <Input id="tf-password" type="password" autoComplete="current-password" autoFocus required value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} aria-invalid={!!form.errors.password} />
                            <FieldError message={form.errors.password} />
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant={disable ? 'danger' : 'default'} disabled={form.processing || form.data.password === ''}>
                            {form.processing && <Loader2 className="animate-spin" />} {disable ? 'Desactivar' : 'Generar códigos'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
