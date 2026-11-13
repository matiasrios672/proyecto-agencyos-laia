import { useForm } from '@inertiajs/react';
import { FileText, Loader2, TriangleAlert, Upload } from 'lucide-react';
import { useEffect, useRef, useState, type DragEvent, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Select, Textarea } from '@/components/ui/select';
import { cn } from '@/lib/utils';

const MAX_MB = 25;

export interface UploadTarget {
    clientId?: number;
    month?: string;
    title?: string;
    notes?: string | null;
    /** El usuario eligió "Reemplazar" sobre una grilla existente: el mes queda fijo y no se vuelve a preguntar. */
    replacing?: boolean;
}

interface Props {
    open: boolean;
    onClose: () => void;
    /** Clientes entre los que elegir (panorama global). Si el cliente es fijo, no se pasa. */
    clients?: { id: number; name: string }[];
    fixedClient?: { id: number; name: string };
    defaultMonth: string;
    target?: UploadTarget | null;
}

const size = (bytes: number) => (bytes >= 1_048_576 ? `${(bytes / 1_048_576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

export function UploadGridDialog({ open, onClose, clients, fixedClient, defaultMonth, target }: Props) {
    const form = useForm({ month: defaultMonth, title: '', notes: '', replace: false, file: null as File | null });
    const [clientId, setClientId] = useState<number | ''>(fixedClient?.id ?? '');
    const [needsReplace, setNeedsReplace] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const replacing = target?.replacing === true;

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData({ month: target?.month ?? defaultMonth, title: target?.title ?? '', notes: target?.notes ?? '', replace: replacing, file: null });
        setClientId(fixedClient?.id ?? target?.clientId ?? '');
        setNeedsReplace(false);
        setLocalError(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, target]);

    const pick = (file: File | null | undefined) => {
        setLocalError(null);
        if (!file) return;
        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) return setLocalError('El archivo tiene que ser un PDF.');
        if (file.size > MAX_MB * 1024 * 1024) return setLocalError(`El PDF pesa ${size(file.size)}: el máximo es ${MAX_MB} MB.`);
        form.setData('file', file);
        form.clearErrors('file');
    };

    const onDrop = (e: DragEvent<HTMLLabelElement>) => {
        e.preventDefault();
        setDragging(false);
        pick(e.dataTransfer.files?.[0]);
    };

    const submit = (replace: boolean) => (e?: FormEvent) => {
        e?.preventDefault();
        const id = clientId || fixedClient?.id;
        if (!id) return setLocalError('Elegí el cliente.');
        if (!form.data.file) return setLocalError('Elegí el PDF de la grilla.');
        form.transform((d) => ({ ...d, replace: replace || replacing }));
        form.post(`/clientes/${id}/contenido`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onClose(),
            // Ya había una grilla de ese mes: no se pisa sin preguntar.
            onError: (errors) => setNeedsReplace(Boolean(errors.replace)),
        });
    };

    const errors = form.errors as Record<string, string | undefined>;
    const fileError = localError ?? errors.file;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl">
                <form onSubmit={submit(false)} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>{replacing ? 'Reemplazar grilla' : 'Subir grilla de contenido'}</DialogTitle>
                        <DialogDescription>
                            {replacing
                                ? 'El PDF nuevo reemplaza al actual (se borra) y la grilla vuelve a “Borrador”, porque una aprobación no vale para otro archivo.'
                                : `PDF armado en Canva, de hasta ${MAX_MB} MB. Hay una grilla por cliente y por mes.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        {!fixedClient && (
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="g-client">Cliente *</Label>
                                <Select id="g-client" required disabled={replacing} value={clientId} onChange={(e) => setClientId(e.target.value ? Number(e.target.value) : '')}>
                                    <option value="">Elegí un cliente…</option>
                                    {clients?.map((c) => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </Select>
                            </div>
                        )}

                        <div className="grid gap-4 sm:grid-cols-[180px_1fr]">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="g-month">Mes *</Label>
                                <Input id="g-month" type="month" required disabled={replacing} placeholder="AAAA-MM" pattern="20\d{2}-(0[1-9]|1[0-2])" value={form.data.month} onChange={(e) => form.setData('month', e.target.value)} />
                                <FieldError message={errors.month} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="g-title">Título (opcional)</Label>
                                <Input id="g-title" maxLength={160} placeholder="Ej.: Campaña de verano" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                                <FieldError message={errors.title} />
                            </div>
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="g-file">PDF *</Label>
                            <label
                                htmlFor="g-file"
                                onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                                onDragLeave={() => setDragging(false)}
                                onDrop={onDrop}
                                className={cn(
                                    'flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed px-4 py-7 text-center transition-colors focus-within:ring-2 focus-within:ring-ring hover:bg-muted/50',
                                    dragging && 'border-primary bg-accent/50',
                                    fileError && 'border-danger/50',
                                )}
                            >
                                {form.data.file ? (
                                    <>
                                        <FileText className="size-6 text-primary" />
                                        <span className="text-sm font-medium break-all">{form.data.file.name}</span>
                                        <span className="text-xs text-muted-foreground">{size(form.data.file.size)} · tocá para elegir otro</span>
                                    </>
                                ) : (
                                    <>
                                        <Upload className="size-6 text-muted-foreground" />
                                        <span className="text-sm font-medium">Arrastrá el PDF o tocá para elegirlo</span>
                                        <span className="text-xs text-muted-foreground">Solo PDF · máximo {MAX_MB} MB</span>
                                    </>
                                )}
                                <input ref={input} id="g-file" type="file" accept="application/pdf,.pdf" className="sr-only" onChange={(e) => pick(e.target.files?.[0])} />
                            </label>
                            <FieldError message={fileError} />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="g-notes">Notas internas (opcional)</Label>
                            <Textarea id="g-notes" rows={2} maxLength={2000} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                            <FieldError message={errors.notes} />
                        </div>

                        {needsReplace && (
                            <div role="alert" className="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3.5 text-sm">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                                <div className="flex flex-1 flex-col gap-2">
                                    <p>{errors.replace}</p>
                                    <div>
                                        <Button type="button" size="sm" variant="outline" disabled={form.processing} onClick={() => submit(true)()}>
                                            Reemplazar el PDF existente
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Loader2 className="animate-spin" /> : <Upload />} {replacing ? 'Reemplazar' : 'Subir grilla'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
