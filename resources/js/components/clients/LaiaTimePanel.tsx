import { router } from '@inertiajs/react';
import axios from 'axios';
import { CalendarClock, Loader2, Plug, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { formatWhen, LaiaTimeStatusBadge, type LaiaTimeClient, type LaiaTimeError, type LaiaTimePolicy } from '@/components/laiatime/status';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface Payload {
    configured: boolean;
    linked: boolean;
    client: LaiaTimeClient | null;
    error: LaiaTimeError | null;
    policy: LaiaTimePolicy;
    can: { manage: boolean };
}

/** Estado de LaiaTime del cliente (ficha del cliente). Carga lo guardado; "Actualizar" consulta a LaiaTime. */
export function LaiaTimePanel({ clientId }: { clientId: number }) {
    const [data, setData] = useState<Payload | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [confirm, setConfirm] = useState<'suspend' | 'reactivate' | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(
        async (live = false) => {
            setLoading(true);
            try {
                const res = await axios.get<Payload>(`/clientes/${clientId}/laiatime`, { params: live ? { live: 1 } : {} });
                setData(res.data);
                setFailed(false);
            } catch {
                setFailed(true);
            } finally {
                setLoading(false);
            }
        },
        [clientId],
    );

    useEffect(() => {
        void load();
    }, [load]);

    const c = data?.client ?? null;

    const act = () => {
        if (!c || !confirm) return;
        setBusy(true);
        router.post(`/admin/laiatime/instancias/${encodeURIComponent(c.instance_id)}/${confirm === 'suspend' ? 'suspender' : 'reactivar'}`, {}, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setConfirm(null);
                void load();
            },
        });
    };

    return (
        <Card>
            <CardHeader className="flex-row items-start justify-between gap-3">
                <div>
                    <CardTitle className="flex items-center gap-2">
                        <Plug className="size-4 text-muted-foreground" /> LaiaTime
                    </CardTitle>
                    <CardDescription>Estado de la suscripción de turnos</CardDescription>
                </div>
                {data?.linked && (
                    <Button size="sm" variant="outline" disabled={loading} onClick={() => void load(true)} aria-label="Actualizar desde LaiaTime">
                        {loading ? <Loader2 className="animate-spin" /> : <RefreshCw />}
                    </Button>
                )}
            </CardHeader>
            <CardContent className="flex flex-col gap-3 text-sm">
                {loading && !data && <p className="text-muted-foreground">Cargando…</p>}
                {failed && <p className="text-muted-foreground">No se pudo cargar el estado de LaiaTime.</p>}

                {data && !data.linked && <p className="text-muted-foreground">Este cliente no tiene ID de instancia de LaiaTime. Cargalo al editar el cliente.</p>}

                {data && data.linked && c && (
                    <>
                        <div className="flex items-center justify-between gap-2">
                            <LaiaTimeStatusBadge status={c.laiatime_status} />
                            <code className="truncate rounded bg-muted px-1.5 py-0.5 text-xs">{c.instance_id}</code>
                        </div>

                        {c.overdue_days > 0 && (
                            <p className="flex items-start gap-2 text-muted-foreground">
                                <CalendarClock className="mt-0.5 size-4 shrink-0" />
                                {c.out_of_grace
                                    ? `Mora de ${c.overdue_days} días: superó la gracia de ${data.policy.grace_days}.`
                                    : `Mora de ${c.overdue_days} días: dentro de la gracia de ${data.policy.grace_days}.`}
                                {!data.policy.auto_suspend && ' La marca automática está desactivada.'}
                            </p>
                        )}
                        {c.override_until && <p className="text-muted-foreground">Prórroga manual hasta el {c.override_until}.</p>}
                        {c.suspended_at && (
                            <p className="text-muted-foreground">
                                En mora desde {formatWhen(c.suspended_at)} ({c.suspended_by_system ? 'informada por el sistema' : 'marcada a mano'}).
                            </p>
                        )}
                        {data.error && <p className="text-danger">{data.error.message}</p>}
                        {!data.error && c.last_error && <p className="text-danger">{c.last_error}</p>}
                        <p className="text-xs text-muted-foreground">Última sincronización: {formatWhen(c.synced_at)}</p>

                        {data.can.manage && (
                            <div className="flex gap-2 pt-1">
                                {c.laiatime_status === 'active' && (
                                    <Button size="sm" variant="outline" onClick={() => setConfirm('suspend')}>
                                        Informar mora
                                    </Button>
                                )}
                                {c.laiatime_status === 'past_due' && (
                                    <Button size="sm" onClick={() => setConfirm('reactivate')}>
                                        Reactivar
                                    </Button>
                                )}
                            </div>
                        )}
                    </>
                )}
            </CardContent>

            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(o) => !o && setConfirm(null)}
                title={confirm === 'suspend' ? 'Informar mora a LaiaTime' : 'Reactivar en LaiaTime'}
                description={
                    confirm === 'suspend'
                        ? 'La suscripción pasará a PAST_DUE y LaiaTime aplicará su propia política (pausa de reservas nuevas).'
                        : 'La suscripción volverá a ACTIVE. Si el cliente sigue en mora, se le concede un nuevo plazo de gracia.'
                }
                confirmLabel={confirm === 'suspend' ? 'Informar mora' : 'Reactivar'}
                destructive={confirm === 'suspend'}
                busy={busy}
                onConfirm={act}
            />
        </Card>
    );
}
