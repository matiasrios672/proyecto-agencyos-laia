import { Link, router, usePage } from '@inertiajs/react';
import { Loader2, Plug, RefreshCw, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { AccountStatusBadge } from '@/components/status-badge';
import { LaiaTimeStatusBadge, type LaiaTimeClient, type LaiaTimeError, type LaiaTimePolicy } from '@/components/laiatime/status';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';
import type { SharedProps } from '@/types';

interface Instance {
    id: string;
    name: string;
    status: string;
    plan: string | null;
    renews_at: string | null;
    client: LaiaTimeClient | null;
}

interface Props {
    configured: boolean;
    issue: 'not_configured' | 'malformed_key' | null;
    error: LaiaTimeError | null;
    instances: Instance[];
    dangling: LaiaTimeClient[];
    policy: LaiaTimePolicy;
    can: { manage: boolean };
}

export default function Index({ configured, issue, error, instances, dangling, policy, can }: Props) {
    const isSysadmin = !!usePage<SharedProps>().props.auth?.user.is_sysadmin;
    const [busy, setBusy] = useState<string | null>(null);
    const [confirm, setConfirm] = useState<{ instance: Instance; action: 'suspender' | 'reactivar' } | null>(null);

    const post = (url: string, key: string, onDone?: () => void) => {
        setBusy(key);
        router.post(url, {}, { preserveScroll: true, onFinish: () => { setBusy(null); onDone?.(); } });
    };

    const act = () => {
        if (!confirm) return;
        post(`/admin/laiatime/instancias/${encodeURIComponent(confirm.instance.id)}/${confirm.action}`, confirm.instance.id, () => setConfirm(null));
    };

    return (
        <AppLayout title="LaiaTime">
            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <p className="max-w-2xl text-sm text-muted-foreground">
                    Agency OS informa a LaiaTime el estado de pago de cada negocio (activa ↔ en mora). Marca automática: <strong>{policy.auto_suspend ? 'activada' : 'desactivada'}</strong> · gracia de{' '}
                    {policy.grace_days} días. Se configura en{' '}
                    {isSysadmin ? <Link href="/admin/configuracion" className="underline">Configuración</Link> : 'Configuración (lo gestiona el Superadministrador)'}.
                </p>
                {configured && (
                    <div className="flex gap-2">
                        <Button variant="outline" disabled={busy === 'ping'} onClick={() => post('/admin/laiatime/probar', 'ping')}>
                            {busy === 'ping' ? <Loader2 className="animate-spin" /> : <ShieldCheck />} Probar conexión
                        </Button>
                        {can.manage && (
                            <Button disabled={busy === 'sync'} onClick={() => post('/admin/laiatime/sincronizar', 'sync')}>
                                {busy === 'sync' ? <Loader2 className="animate-spin" /> : <RefreshCw />} Sincronizar ahora
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {!configured && (
                <Card>
                    <EmptyState
                        icon={Plug}
                        title={issue === 'malformed_key' ? 'La API key no tiene el formato esperado' : 'LaiaTime todavía no está configurado'}
                        description="Cargá la URL de la API y la API key (lt_live_<id>.<secreto>) en Configuración → LaiaTime. La integración es opcional."
                        action={isSysadmin ? <Link href="/admin/configuracion"><Button variant="outline">Ir a Configuración</Button></Link> : undefined}
                    />
                </Card>
            )}

            {configured && error && (
                <Card>
                    <EmptyState icon={Plug} title={error.kind === 'unavailable' ? 'LaiaTime no está disponible' : 'No se pudo leer LaiaTime'} description={error.message} />
                </Card>
            )}

            {configured && !error && (
                <Card>
                    {instances.length === 0 ? (
                        <EmptyState icon={Plug} title="LaiaTime no devolvió negocios" />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="p-3 font-medium">Negocio</th>
                                        <th className="p-3 font-medium">Suscripción</th>
                                        <th className="p-3 font-medium">Cliente en Agency OS</th>
                                        <th className="p-3 font-medium">Mora</th>
                                        {can.manage && <th className="p-3" />}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {instances.map((i) => (
                                        <tr key={i.id}>
                                            <td className="p-3">
                                                <p className="font-medium">{i.name}</p>
                                                <p className="text-xs text-muted-foreground">{i.plan ?? 'Sin plan'} · {i.id}</p>
                                            </td>
                                            <td className="p-3"><LaiaTimeStatusBadge status={i.status} /></td>
                                            <td className="p-3">
                                                {i.client ? (
                                                    <div className="flex items-center gap-2">
                                                        <Link href={`/clientes/${i.client.id}`} className="font-medium underline-offset-2 hover:underline">{i.client.name}</Link>
                                                        <AccountStatusBadge status={i.client.account_status} />
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground">Sin vincular</span>
                                                )}
                                            </td>
                                            <td className="p-3 text-muted-foreground">
                                                {i.client && i.client.overdue_days > 0 ? `${i.client.overdue_days} días${i.client.in_grace ? ' (gracia)' : ''}` : '—'}
                                            </td>
                                            {can.manage && (
                                                <td className="p-3 text-right">
                                                    {i.status === 'active' && <Button size="sm" variant="outline" onClick={() => setConfirm({ instance: i, action: 'suspender' })}>Informar mora</Button>}
                                                    {i.status === 'past_due' && <Button size="sm" onClick={() => setConfirm({ instance: i, action: 'reactivar' })}>Reactivar</Button>}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            )}

            {dangling.length > 0 && (
                <Card className="mt-6">
                    <CardContent>
                        <p className="mb-2 text-sm font-medium">Clientes con un ID que LaiaTime no conoce</p>
                        <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                            {dangling.map((c) => (
                                <li key={c.id}>
                                    <Link href={`/clientes/${c.id}/edit`} className="underline">{c.name}</Link> — <code>{c.instance_id}</code>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}

            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(o) => !o && setConfirm(null)}
                title={confirm?.action === 'suspender' ? 'Informar mora a LaiaTime' : 'Reactivar en LaiaTime'}
                description={
                    confirm?.action === 'suspender'
                        ? `${confirm.instance.name} pasará a PAST_DUE. LaiaTime aplicará su propia política.`
                        : `${confirm?.instance.name} volverá a ACTIVE. Si el cliente sigue en mora, se le concede un nuevo plazo de gracia.`
                }
                confirmLabel={confirm?.action === 'suspender' ? 'Informar mora' : 'Reactivar'}
                destructive={confirm?.action === 'suspender'}
                busy={busy !== null}
                onConfirm={act}
            />
        </AppLayout>
    );
}
