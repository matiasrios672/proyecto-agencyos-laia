import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Building2, CheckCircle2, Clock, History, PartyPopper, UserCircle2, UsersRound, type LucideIcon } from 'lucide-react';
import { AccountStatusBadge } from '@/components/status-badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';
import { timeAgo } from '@/lib/theme';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

interface Props {
    stats: { total: number; by_status: { al_dia: number; por_vencer: number; deuda: number }; team: number | null } | null;
    attention: { id: number; name: string; account_status: string }[];
    recent: { id: number; description: string; causer: string | null; at: string }[];
}

function Stat({ label, value, icon: Icon, tone }: { label: string; value: number; icon: LucideIcon; tone: string }) {
    return (
        <Card className="p-4 transition-shadow hover:shadow-md sm:p-5 max-sm:[&:last-child:nth-child(odd)]:col-span-2">
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">{label}</p>
                <span className={cn('grid size-9 place-items-center rounded-lg', tone)}>
                    <Icon className="size-[18px]" />
                </span>
            </div>
            <p className="mt-3 font-display text-3xl font-extrabold tracking-tight tabular-nums">{value}</p>
        </Card>
    );
}

export default function Dashboard({ stats, attention, recent }: Props) {
    const user = usePage<SharedProps>().props.auth!.user;
    const hour = new Date().getHours();
    const greeting = hour < 6 ? 'Buenas noches' : hour < 13 ? 'Buenos días' : hour < 20 ? 'Buenas tardes' : 'Buenas noches';
    const today = new Date().toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' });

    return (
        <AppLayout title="Inicio">
            <div className="brand-gradient relative mb-7 overflow-hidden rounded-2xl p-7 text-white sm:p-8">
                <p className="text-sm text-white/60 first-letter:uppercase">{today}</p>
                <h2 className="mt-1 font-display text-2xl font-extrabold tracking-tight sm:text-3xl">
                    {greeting}, {user.name.split(' ')[0]}
                </h2>
                <p className="mt-2 max-w-xl text-white/70">
                    {stats
                        ? stats.by_status.deuda + stats.by_status.por_vencer > 0
                            ? `Tenés ${stats.by_status.deuda + stats.by_status.por_vencer} cliente(s) que requieren atención.`
                            : 'Todos tus clientes están al día. ¡Buen trabajo!'
                        : 'Bienvenido al centro de operaciones de Laia.'}
                </p>
                <div className="pointer-events-none absolute -right-10 -top-10 size-44 rounded-full border border-white/10" aria-hidden="true" />
                <div className="pointer-events-none absolute -right-2 top-14 size-20 rounded-full border border-white/10" aria-hidden="true" />
            </div>

            {stats && (
                <div className={cn('mb-7 grid grid-cols-2 gap-3 sm:gap-4', stats.team !== null ? 'lg:grid-cols-5' : 'lg:grid-cols-4')}>
                    <Stat label="Clientes activos" value={stats.total} icon={Building2} tone="bg-accent text-accent-foreground" />
                    <Stat label="Al día" value={stats.by_status.al_dia} icon={CheckCircle2} tone="bg-success/15 text-success" />
                    <Stat label="Por vencer" value={stats.by_status.por_vencer} icon={Clock} tone="bg-warning/15 text-warning" />
                    <Stat label="Con deuda" value={stats.by_status.deuda} icon={AlertTriangle} tone="bg-danger/15 text-danger" />
                    {stats.team !== null && <Stat label="Equipo activo" value={stats.team} icon={UsersRound} tone="bg-accent text-accent-foreground" />}
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <Card>
                    <CardHeader className="flex-row items-center justify-between">
                        <div>
                            <CardTitle>Requieren atención</CardTitle>
                            <CardDescription>Clientes con deuda o próximos a vencer</CardDescription>
                        </div>
                        <Link href="/clientes" className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                            Ver todos <ArrowRight className="size-3.5" />
                        </Link>
                    </CardHeader>
                    <CardContent className="p-0">
                        {attention.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                                <span className="grid size-11 place-items-center rounded-full bg-success/15 text-success">
                                    <PartyPopper className="size-5" />
                                </span>
                                <p className="font-medium">Todo en orden</p>
                                <p className="text-sm text-muted-foreground">No hay clientes con pagos pendientes.</p>
                            </div>
                        ) : (
                            <ul className="divide-y">
                                {attention.map((c) => (
                                    <li key={c.id}>
                                        <Link href={`/clientes/${c.id}`} className="flex items-center justify-between gap-3 px-5 py-3.5 transition-colors hover:bg-muted/60">
                                            <span className="font-medium">{c.name}</span>
                                            <AccountStatusBadge status={c.account_status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <div className="flex flex-col gap-6">
                    {recent.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <History className="size-4 text-muted-foreground" /> Actividad reciente
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3.5">
                                {recent.map((a) => (
                                    <div key={a.id} className="flex items-start justify-between gap-3 text-sm">
                                        <div>
                                            <p>{a.description}</p>
                                            <p className="text-xs text-muted-foreground">{a.causer ?? 'Sistema'}</p>
                                        </div>
                                        <time className="shrink-0 text-xs text-muted-foreground">{timeAgo(a.at)}</time>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Accesos rápidos</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2">
                            {[
                                { href: '/clientes', label: 'Mis clientes', icon: Building2, show: !!stats },
                                { href: '/equipo', label: 'Gestionar equipo', icon: UsersRound, show: stats?.team != null },
                                { href: '/perfil', label: 'Mi perfil', icon: UserCircle2, show: true },
                            ]
                                .filter((l) => l.show)
                                .map((l) => (
                                    <Link key={l.href} href={l.href} className="group flex items-center gap-3 rounded-lg border p-3 transition-colors hover:border-primary/40 hover:bg-accent/40">
                                        <span className="grid size-8 place-items-center rounded-md bg-accent text-accent-foreground">
                                            <l.icon className="size-4" />
                                        </span>
                                        <span className="flex-1 text-sm font-medium">{l.label}</span>
                                        <ArrowRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                    </Link>
                                ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
