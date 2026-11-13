import { router } from '@inertiajs/react';
import { CheckCircle2, RefreshCw, Trash2, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';

interface Props {
    health: Record<'database' | 'redis' | 'cache', boolean>;
    queues: { pending: number; failed: number };
    failedJobs: { id: number; queue: string; failed_at: string; error: string }[];
    logLines: string[];
    activity: { id: number; log: string | null; description: string; causer: string | null; at: string }[];
    versions: { php: string; laravel: string };
}

const healthLabels = { database: 'PostgreSQL', redis: 'Redis', cache: 'Caché' } as const;

export default function System({ health, queues, failedJobs, logLines, activity, versions }: Props) {
    return (
        <AppLayout title="Estado del sistema">
            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    PHP {versions.php} · Laravel {versions.laravel}
                </p>
                <Button variant="outline" size="sm" onClick={() => router.reload()}>
                    <RefreshCw /> Actualizar
                </Button>
            </div>

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                {(Object.keys(healthLabels) as (keyof typeof healthLabels)[]).map((k) => (
                    <Card key={k}>
                        <CardContent className="flex items-center gap-3 p-4">
                            {health[k] ? (
                                <CheckCircle2 className="size-5 text-success" />
                            ) : (
                                <XCircle className="size-5 text-danger" />
                            )}
                            <div>
                                <p className="text-sm font-medium">{healthLabels[k]}</p>
                                <p className="text-xs text-muted-foreground">{health[k] ? 'Operativo' : 'Sin conexión'}</p>
                            </div>
                        </CardContent>
                    </Card>
                ))}
                <Card>
                    <CardContent className="p-4">
                        <p className="text-xs text-muted-foreground">En cola</p>
                        <p className="text-2xl font-semibold">{queues.pending}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="p-4">
                        <p className="text-xs text-muted-foreground">Fallidos</p>
                        <p className="text-2xl font-semibold">{queues.failed}</p>
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader className="flex-row items-center justify-between">
                        <div>
                            <CardTitle>Trabajos fallidos</CardTitle>
                            <CardDescription>Últimos 10</CardDescription>
                        </div>
                        {failedJobs.length > 0 && (
                            <div className="flex gap-2">
                                <Button size="sm" variant="outline" onClick={() => router.post('/admin/sistema/reintentar')}>
                                    Reintentar todos
                                </Button>
                                <Button size="sm" variant="ghost" aria-label="Eliminar fallidos" onClick={() => router.delete('/admin/sistema/fallidos')}>
                                    <Trash2 />
                                </Button>
                            </div>
                        )}
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        {failedJobs.length === 0 && <p className="text-sm text-muted-foreground">Sin trabajos fallidos. 🎉</p>}
                        {failedJobs.map((j) => (
                            <div key={j.id} className="rounded-md border p-3 text-sm">
                                <div className="mb-1 flex items-center gap-2">
                                    <Badge variant="danger">{j.queue}</Badge>
                                    <span className="text-xs text-muted-foreground">{j.failed_at}</span>
                                </div>
                                <p className="break-words text-xs text-muted-foreground">{j.error}</p>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Actividad reciente</CardTitle>
                        <CardDescription>Auditoría de acciones sensibles</CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2.5">
                        {activity.length === 0 && <p className="text-sm text-muted-foreground">Todavía no hay actividad registrada.</p>}
                        {activity.map((a) => (
                            <div key={a.id} className="flex items-start justify-between gap-3 text-sm">
                                <div>
                                    <p>{a.description}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {a.causer ?? 'Sistema'} · {a.log}
                                    </p>
                                </div>
                                <time className="shrink-0 text-xs text-muted-foreground">
                                    {new Date(a.at).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })}
                                </time>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>Log de la aplicación</CardTitle>
                    <CardDescription>Últimas entradas de laravel.log</CardDescription>
                </CardHeader>
                <CardContent>
                    {logLines.length === 0 ? (
                        <p className="text-sm text-muted-foreground">El log está vacío.</p>
                    ) : (
                        <pre className="max-h-80 overflow-auto rounded-md bg-muted p-3 text-xs leading-relaxed">{logLines.join('\n')}</pre>
                    )}
                </CardContent>
            </Card>
        </AppLayout>
    );
}
