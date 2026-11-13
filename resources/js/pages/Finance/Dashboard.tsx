import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CalendarClock, Coins, HandCoins, Landmark, PartyPopper, Receipt, Repeat, TrendingDown, TrendingUp, type LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { MoneyFlowChart, type MonthPoint } from '@/components/finance/MoneyFlowChart';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

type Metric = { cents: number; label: string };
type Overview = Record<string, Record<string, Metric>>;

interface DueRow {
    id: number;
    number: string;
    client: string;
    client_id: number;
    description: string;
    amount_label: string;
    due_date: string;
    days: number;
}

interface Props {
    currencies: { code: string; label: string }[];
    overview: Overview;
    consolidated: Record<string, Metric> | null;
    rate: number | null;
    series: Record<string, MonthPoint[]>;
    monthLabel: string;
    overdue: DueRow[];
    upcoming: DueRow[];
}

function Tile({ label, value, icon: Icon, hint, tone = 'bg-accent text-accent-foreground' }: { label: string; value: string; icon: LucideIcon; hint?: string; tone?: string }) {
    return (
        <Card className="p-4 sm:p-5">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">{label}</p>
                <span className={cn('grid size-9 shrink-0 place-items-center rounded-lg', tone)}>
                    <Icon className="size-[18px]" />
                </span>
            </div>
            <p className="mt-3 font-display text-xl font-extrabold tracking-tight tabular-nums sm:text-2xl">{value}</p>
            {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
        </Card>
    );
}

const dueText = (days: number) => (days === 0 ? 'Vence hoy' : days === 1 ? 'Vence mañana' : days > 0 ? `Vence en ${days} días` : `Venció hace ${Math.abs(days)} ${Math.abs(days) === 1 ? 'día' : 'días'}`);

export default function Dashboard({ currencies, overview, consolidated, rate, series, monthLabel, overdue, upcoming }: Props) {
    const options = [...currencies.map((c) => ({ code: c.code, label: c.code === 'ARS' ? 'Pesos (ARS)' : 'Dólares (USD)' })), ...(consolidated ? [{ code: 'ALL', label: 'Consolidado en ARS' }] : [])];
    const [view, setView] = useState('ARS');

    const metrics: Record<string, Metric> = view === 'ALL' && consolidated ? consolidated : overview[view];
    const currency = view === 'ALL' ? 'ARS' : view;
    const money = (cents: number) => formatMoney(cents / 100, currency);

    // Consolidado: pesos + dólares × cotización, mes a mes (mismos números que el resumen).
    const chartData = useMemo<MonthPoint[]>(() => {
        if (view !== 'ALL' || !rate) return series[view] ?? [];
        return series.ARS.map((m, i) => {
            const u = series.USD[i];
            const sum = (k: 'collected' | 'payouts' | 'expenses' | 'net') => m[k] + Math.round(u[k] * rate);
            return { ...m, collected: sum('collected'), payouts: sum('payouts'), expenses: sum('expenses'), net: sum('net') };
        });
    }, [view, rate, series]);

    const net = metrics.net?.cents ?? 0;

    return (
        <AppLayout title="Finanzas">
            <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p className="text-sm text-muted-foreground">{monthLabel}</p>
                    <h2 className="font-display text-2xl font-extrabold tracking-tight">Resumen financiero</h2>
                </div>
                {/* Filtro: una sola fila sobre el contenido; escopea todo lo que está debajo */}
                <div role="tablist" aria-label="Moneda" className="inline-flex rounded-md border bg-muted p-0.5">
                    {options.map((o) => (
                        <button
                            key={o.code}
                            role="tab"
                            aria-selected={view === o.code}
                            onClick={() => setView(o.code)}
                            className={cn('rounded px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors', view === o.code && 'bg-card text-foreground shadow-sm')}
                        >
                            {o.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Cifra principal (una por vista): la ganancia neta real del mes */}
            <Card className="brand-gradient mb-6 overflow-hidden border-0 p-6 text-white sm:p-8">
                <p className="text-sm font-medium text-white/70">Ganancia neta real · {monthLabel}</p>
                <p className="mt-2 flex flex-wrap items-baseline gap-x-4 gap-y-1 font-display text-5xl font-extrabold tracking-tight tabular-nums sm:text-6xl">
                    {net < 0 && <span aria-hidden="true">−</span>}
                    {money(Math.abs(net))}
                    <span className="inline-flex items-center gap-1.5 text-base font-medium text-white/80">
                        {net >= 0 ? <TrendingUp className="size-4" /> : <TrendingDown className="size-4" />}
                        {net >= 0 ? 'a favor' : 'en contra'}
                    </span>
                </p>
                <p className="mt-3 max-w-2xl text-sm text-white/70">
                    Cobrado {money(metrics.collected.cents)} − liquidaciones al equipo {money(metrics.payouts.cents)} − egresos {money(metrics.expenses.cents)}.
                    {view === 'ALL' && rate && <> Cotización USD: {formatMoney(rate, 'ARS')}.</>}
                </p>
            </Card>

            <div className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
                <Tile label="MRR" value={metrics.mrr.label} icon={Repeat} hint="Ingreso mensual recurrente" />
                <Tile label="Cobrado en el mes" value={metrics.collected.label} icon={Coins} tone="bg-success/15 text-success" />
                <Tile label="Pendiente de cobro" value={metrics.pending.label} icon={Receipt} tone="bg-warning/15 text-warning" />
                <Tile label="En mora" value={metrics.overdue.label} icon={AlertTriangle} tone="bg-danger/15 text-danger" hint={metrics.overdue.cents > 0 ? 'Cobros vencidos' : 'Sin mora'} />
                <Tile label="Liquidaciones pendientes" value={metrics.payouts_pending.label} icon={HandCoins} hint="A pagar al equipo" />
                <Tile label="Egresos del mes" value={metrics.expenses.label} icon={Landmark} hint="Suscripciones y gastos" />
            </div>

            <Card className="mb-6">
                <CardHeader>
                    <CardTitle>A dónde va lo que se cobra</CardTitle>
                    <CardDescription>Últimos 6 meses. Cada columna es lo cobrado en el mes, repartido entre ganancia neta, liquidaciones y egresos.</CardDescription>
                </CardHeader>
                <CardContent>
                    <MoneyFlowChart data={chartData} currency={currency} />
                </CardContent>
            </Card>

            <div className="grid gap-6 lg:grid-cols-2">
                <DueList title="En mora" description="Cobros vencidos sin pagar" icon={AlertTriangle} rows={overdue} empty="Nadie debe nada. ¡Excelente!" tone="danger" />
                <DueList title="Próximos vencimientos" description="Lo que se viene a cobrar" icon={CalendarClock} rows={upcoming} empty="No hay vencimientos próximos." tone="muted" />
            </div>
        </AppLayout>
    );
}

function DueList({ title, description, icon: Icon, rows, empty, tone }: { title: string; description: string; icon: LucideIcon; rows: DueRow[]; empty: string; tone: 'danger' | 'muted' }) {
    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between">
                <div>
                    <CardTitle className="flex items-center gap-2"><Icon className={cn('size-4', tone === 'danger' ? 'text-danger' : 'text-muted-foreground')} /> {title}</CardTitle>
                    <CardDescription>{description}</CardDescription>
                </div>
                <Link href="/cobros" className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                    Ver cobros <ArrowRight className="size-3.5" />
                </Link>
            </CardHeader>
            <CardContent className="p-0">
                {rows.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-6 py-10 text-center">
                        <PartyPopper className="size-6 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">{empty}</p>
                    </div>
                ) : (
                    <ul className="divide-y">
                        {rows.map((r) => (
                            <li key={r.id}>
                                <Link href={`/clientes/${r.client_id}`} className="flex items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-muted/60">
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">{r.client}</span>
                                        <span className={cn('block text-xs', r.days < 0 ? 'text-danger' : 'text-muted-foreground')}>{dueText(r.days)} · {r.number}</span>
                                    </span>
                                    <span className="shrink-0 font-medium tabular-nums">{r.amount_label}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
