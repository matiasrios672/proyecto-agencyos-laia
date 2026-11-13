import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { Copy, ExternalLink, Eye, EyeOff, KeyRound, Loader2, Pencil, Plus, ShieldCheck, StickyNote, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select, Textarea } from '@/components/ui/select';
import type { Options, VaultRow } from '@/types';

const REVEAL_SECONDS = 20;

interface Props {
    clientId: number;
    items: VaultRow[];
    categories: Options;
    canManage: boolean;
}

type Revealed = { secret?: string; notes?: string };

export function VaultPanel({ clientId, items, categories, canManage }: Props) {
    const base = `/clientes/${clientId}/boveda`;
    const [revealed, setRevealed] = useState<Record<number, Revealed>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [editing, setEditing] = useState<VaultRow | 'new' | null>(null);
    const [deleting, setDeleting] = useState<VaultRow | null>(null);
    const timers = useRef<Record<string, ReturnType<typeof setTimeout>>>({});

    useEffect(() => {
        const t = timers.current;
        return () => Object.values(t).forEach(clearTimeout);
    }, []);

    const fetchValue = async (item: VaultRow, action: 'read' | 'copy', field: 'secret' | 'notes') => {
        const { data } = await axios.post<{ value: string | null }>(`${base}/${item.id}/revelar`, { action, field });
        return data.value ?? '';
    };

    const reveal = async (item: VaultRow, field: 'secret' | 'notes') => {
        const key = `${item.id}:${field}`;
        setBusy(key);
        try {
            const value = await fetchValue(item, 'read', field);
            setRevealed((r) => ({ ...r, [item.id]: { ...r[item.id], [field]: value } }));
            clearTimeout(timers.current[key]);
            timers.current[key] = setTimeout(() => hide(item.id, field), REVEAL_SECONDS * 1000);
        } catch {
            toast.error('No se pudo revelar la credencial.');
        } finally {
            setBusy(null);
        }
    };

    const hide = (id: number, field: 'secret' | 'notes') => {
        clearTimeout(timers.current[`${id}:${field}`]);
        setRevealed((r) => {
            const next = { ...r[id] };
            delete next[field];
            return { ...r, [id]: next };
        });
    };

    const copy = async (item: VaultRow) => {
        setBusy(`${item.id}:copy`);
        try {
            const value = await fetchValue(item, 'copy', 'secret');
            await navigator.clipboard.writeText(value);
            toast.success('Copiado al portapapeles. El acceso quedó registrado.');
        } catch {
            toast.error('No se pudo copiar la credencial.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <ShieldCheck className="size-4 text-success" />
                    Cifrada en la base de datos. Cada lectura o copia queda registrada con tu usuario.
                </p>
                {canManage && (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus /> Nueva credencial
                    </Button>
                )}
            </div>

            <Card className="overflow-hidden">
                {items.length === 0 ? (
                    <EmptyState
                        icon={KeyRound}
                        title="La bóveda está vacía"
                        description={canManage ? 'Guardá accesos a redes sociales, hosting, cPanel y códigos de respaldo 2FA.' : 'Todavía no se cargaron credenciales para este cliente.'}
                    />
                ) : (
                    <ul className="divide-y">
                        {items.map((item) => {
                            const r = revealed[item.id] ?? {};
                            return (
                                <li key={item.id} className="flex flex-col gap-3 p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">{item.label}</span>
                                                <Badge variant="primary">{item.category_label}</Badge>
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                                {item.username && <span>Usuario: <span className="text-foreground">{item.username}</span></span>}
                                                {item.url && (
                                                    <a href={item.url} target="_blank" rel="noreferrer noopener" className="inline-flex items-center gap-1 hover:text-foreground hover:underline">
                                                        <ExternalLink className="size-3" /> Abrir
                                                    </a>
                                                )}
                                            </div>
                                        </div>
                                        {canManage && (
                                            <div className="flex gap-1">
                                                <Button variant="ghost" size="icon" className="size-8" aria-label={`Editar ${item.label}`} onClick={() => setEditing(item)}>
                                                    <Pencil />
                                                </Button>
                                                <Button variant="ghost" size="icon" className="size-8" aria-label={`Eliminar ${item.label}`} onClick={() => setDeleting(item)}>
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        )}
                                    </div>

                                    {item.has_secret && (
                                        <div className="flex flex-wrap items-center gap-2">
                                            <code
                                                className="min-h-9 min-w-0 flex-1 whitespace-pre-wrap break-all rounded-md bg-muted px-3 py-2 text-sm"
                                                aria-live="polite"
                                            >
                                                {r.secret !== undefined ? r.secret : '••••••••••••'}
                                            </code>
                                            {r.secret !== undefined ? (
                                                <Button variant="outline" size="sm" onClick={() => hide(item.id, 'secret')}>
                                                    <EyeOff /> Ocultar
                                                </Button>
                                            ) : (
                                                <Button variant="outline" size="sm" disabled={busy === `${item.id}:secret`} onClick={() => reveal(item, 'secret')}>
                                                    {busy === `${item.id}:secret` ? <Loader2 className="animate-spin" /> : <Eye />} Revelar
                                                </Button>
                                            )}
                                            <Button variant="outline" size="sm" disabled={busy === `${item.id}:copy`} onClick={() => copy(item)}>
                                                {busy === `${item.id}:copy` ? <Loader2 className="animate-spin" /> : <Copy />} Copiar
                                            </Button>
                                        </div>
                                    )}
                                    {r.secret !== undefined && (
                                        <p className="text-xs text-muted-foreground">Se vuelve a ocultar sola en {REVEAL_SECONDS} segundos.</p>
                                    )}

                                    {item.has_notes && (
                                        <div className="flex items-start gap-2 text-sm">
                                            <StickyNote className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                            {r.notes !== undefined ? (
                                                <p className="whitespace-pre-wrap">{r.notes}</p>
                                            ) : (
                                                <button
                                                    className="text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                                    onClick={() => reveal(item, 'notes')}
                                                >
                                                    Ver notas (se registra la lectura)
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>

            <VaultFormDialog base={base} categories={categories} editing={editing} onClose={() => setEditing(null)} />

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(o) => !o && setDeleting(null)}
                title="¿Eliminar esta credencial?"
                description={`Se borrará "${deleting?.label ?? ''}" de la bóveda. Esta acción queda registrada y no se puede deshacer.`}
                confirmLabel="Eliminar"
                destructive
                onConfirm={() => deleting && router.delete(`${base}/${deleting.id}`, { preserveScroll: true, onSuccess: () => setDeleting(null) })}
            />
        </div>
    );
}

function VaultFormDialog({ base, categories, editing, onClose }: { base: string; categories: Options; editing: VaultRow | 'new' | null; onClose: () => void }) {
    const item = editing && editing !== 'new' ? editing : null;
    const form = useForm({ category: 'red_social', label: '', url: '', username: '', secret: '', notes: '' });

    useEffect(() => {
        if (editing === null) return;
        form.clearErrors();
        form.setData({
            category: item?.category ?? 'red_social',
            label: item?.label ?? '',
            url: item?.url ?? '',
            username: item?.username ?? '',
            secret: '',
            notes: '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } };
        if (item) form.put(`${base}/${item.id}`, opts);
        else form.post(base, opts);
    };

    return (
        <Dialog open={editing !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>{item ? 'Editar credencial' : 'Nueva credencial'}</DialogTitle>
                        <DialogDescription>El secreto y las notas se guardan cifrados.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="v-category">Tipo</Label>
                                <Select id="v-category" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                    {Object.entries(categories).map(([v, l]) => (
                                        <option key={v} value={v}>{l}</option>
                                    ))}
                                </Select>
                                <FieldError message={form.errors.category} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="v-label">Nombre *</Label>
                                <Input id="v-label" required value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="Instagram, cPanel…" />
                                <FieldError message={form.errors.label} />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="v-username">Usuario / email</Label>
                                <Input id="v-username" autoComplete="off" value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} />
                                <FieldError message={form.errors.username} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="v-url">URL de acceso</Label>
                                <Input id="v-url" value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} placeholder="https://" />
                                <FieldError message={form.errors.url} />
                            </div>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="v-secret">Contraseña / secreto</Label>
                            <Textarea
                                id="v-secret"
                                rows={3}
                                autoComplete="off"
                                spellCheck={false}
                                value={form.data.secret}
                                onChange={(e) => form.setData('secret', e.target.value)}
                                placeholder={item?.has_secret ? 'Dejar vacío para conservar el actual' : 'Contraseña o códigos de respaldo (uno por línea)'}
                            />
                            <FieldError message={form.errors.secret} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="v-notes">Notas (cifradas)</Label>
                            <Textarea
                                id="v-notes"
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder={item?.has_notes ? 'Dejar vacío para conservar las actuales' : 'Preguntas de seguridad, 2FA, etc.'}
                            />
                            <FieldError message={form.errors.notes} />
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2 className="animate-spin" />}
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
