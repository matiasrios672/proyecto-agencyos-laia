import { router } from '@inertiajs/react';
import { Check, Loader2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Label } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout';

interface Field {
    key: string;
    label: string;
    type: 'text' | 'password' | 'number' | 'boolean' | 'textarea';
    secret: boolean;
    value: string;
    configured: boolean;
}

export default function Settings({ groups }: { groups: Record<string, Field[]> }) {
    const allFields = Object.values(groups).flat();
    const [values, setValues] = useState<Record<string, string>>(Object.fromEntries(allFields.map((f) => [f.key, f.value])));
    const [saving, setSaving] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setSaving(true);
        router.put('/admin/configuracion', { values }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
            // Tras guardar, los secretos se vacían del formulario: nunca quedan en memoria del navegador.
            onSuccess: () =>
                setValues((v) => ({ ...v, ...Object.fromEntries(allFields.filter((f) => f.secret).map((f) => [f.key, ''])) })),
        });
    };

    return (
        <AppLayout title="Configuración del sistema">
            <form onSubmit={submit} className="flex flex-col gap-6">
                <p className="text-sm text-muted-foreground">
                    Estos valores se guardan en la base de datos y se cachean en Redis. No hace falta tocar el <code>.env</code>.
                    Los secretos se almacenan cifrados y nunca se muestran de nuevo.
                </p>

                {Object.entries(groups).map(([group, fields]) => (
                    <Card key={group}>
                        <CardHeader>
                            <CardTitle>{group}</CardTitle>
                            <CardDescription>
                                {fields.length} {fields.length === 1 ? 'ajuste' : 'ajustes'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            {fields.map((f) => (
                                <div key={f.key} className="flex flex-col gap-1.5">
                                    <div className="flex items-center gap-2">
                                        <Label htmlFor={f.key}>{f.label}</Label>
                                        {f.secret && f.configured && (
                                            <Badge variant="success">
                                                <Check className="size-3" /> Configurado
                                            </Badge>
                                        )}
                                    </div>
                                    {f.type === 'boolean' ? (
                                        <div className="flex h-9 items-center">
                                            <Switch
                                                id={f.key}
                                                checked={values[f.key] === '1'}
                                                onCheckedChange={(c) => setValues((v) => ({ ...v, [f.key]: c ? '1' : '0' }))}
                                            />
                                        </div>
                                    ) : (
                                        <Input
                                            id={f.key}
                                            type={f.type === 'password' ? 'password' : f.type === 'number' ? 'number' : 'text'}
                                            autoComplete="off"
                                            placeholder={f.secret && f.configured ? '•••••••• (dejar vacío para no cambiar)' : ''}
                                            value={values[f.key] ?? ''}
                                            onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                        />
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ))}

                <div className="flex justify-end">
                    <Button type="submit" disabled={saving}>
                        {saving && <Loader2 className="animate-spin" />}
                        Guardar cambios
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
