import { useId, useMemo, useState } from 'react';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

export interface MonthPoint {
    month: string;
    label: string;
    collected: number;
    payouts: number;
    expenses: number;
    net: number;
}

/**
 * Columnas apiladas por mes: de lo cobrado, cuánto queda como ganancia neta y cuánto se va en
 * liquidaciones al equipo y en egresos. Todo en centavos; el eje y la tabla muestran los mismos valores.
 * Marcas: columnas de 24 px máx., extremo superior redondeado de 4 px, 2 px de aire entre segmentos.
 */
const SERIES = [
    { key: 'net', label: 'Ganancia neta', color: 'var(--chart-1)' },
    { key: 'payouts', label: 'Liquidaciones al equipo', color: 'var(--chart-2)' },
    { key: 'expenses', label: 'Egresos', color: 'var(--chart-3)' },
] as const;

type SeriesKey = (typeof SERIES)[number]['key'];

const compact = new Intl.NumberFormat('es-AR', { notation: 'compact', maximumFractionDigits: 1 });

/** Techo "redondo" del eje (1, 2, 2.5, 5 × 10ⁿ) para que las marcas del eje sean números limpios. */
function niceMax(value: number): number {
    if (value <= 0) return 100;
    const exp = Math.pow(10, Math.floor(Math.log10(value)));
    const f = value / exp;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * exp;
}

export function MoneyFlowChart({ data, currency }: { data: MonthPoint[]; currency: string }) {
    const [active, setActive] = useState<number | null>(null);
    const [table, setTable] = useState(false);
    const tableId = useId();

    // Las partes de cada columna: la ganancia solo se apila si es positiva; si fue negativa, la columna son los costos.
    const parts = useMemo(
        () => data.map((d) => ({ net: Math.max(0, d.net), payouts: d.payouts, expenses: d.expenses })),
        [data],
    );
    const totals = parts.map((p) => p.net + p.payouts + p.expenses);
    const max = niceMax(Math.max(...totals, 0) / 100) * 100;
    const empty = totals.every((t) => t === 0);
    const ticks = [0, 0.5, 1].map((f) => max * f);
    const money = (cents: number) => formatMoney(cents / 100, currency);
    const last = data.length - 1;

    if (empty) {
        return (
            <p className="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">
                Todavía no hay cobros registrados en estos meses. Cuando marques un cobro como pagado, vas a ver acá a dónde va.
            </p>
        );
    }

    return (
        <div>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                {/* Leyenda: siempre visible con ≥ 2 series (la identidad nunca depende solo del color) */}
                <ul className="flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm text-muted-foreground" aria-label="Referencias del gráfico">
                    {SERIES.map((s) => (
                        <li key={s.key} className="flex items-center gap-2">
                            <span className="size-2.5 rounded-[3px]" style={{ background: s.color }} aria-hidden="true" />
                            {s.label}
                        </li>
                    ))}
                </ul>
                <button
                    type="button"
                    onClick={() => setTable((t) => !t)}
                    aria-expanded={table}
                    aria-controls={tableId}
                    className="rounded-md px-2 py-1 text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {table ? 'Ver gráfico' : 'Ver como tabla'}
                </button>
            </div>

            {table ? (
                <div id={tableId} className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <caption className="sr-only">Cobrado, liquidaciones, egresos y ganancia neta por mes</caption>
                        <thead>
                            <tr className="border-b text-left text-xs text-muted-foreground">
                                <th scope="col" className="py-2 pr-4 font-medium">Mes</th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">Cobrado</th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">Liquidaciones</th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">Egresos</th>
                                <th scope="col" className="py-2 pl-3 text-right font-medium">Ganancia neta</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((d) => (
                                <tr key={d.month} className="border-b last:border-0">
                                    <th scope="row" className="py-2 pr-4 text-left font-medium">{d.label}</th>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(d.collected)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(d.payouts)}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(d.expenses)}</td>
                                    <td className={cn('py-2 pl-3 text-right font-medium tabular-nums', d.net < 0 && 'text-danger')}>{money(d.net)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div id={tableId} className="relative pt-5" onPointerLeave={() => setActive(null)}>
                    <div className="flex h-56 gap-3">
                        {/* Eje y: marcas redondas, texto en tinta secundaria */}
                        <div className="relative w-12 shrink-0 text-right text-xs tabular-nums text-muted-foreground" aria-hidden="true">
                            {ticks.map((t) => (
                                <span key={t} className="absolute right-0 -translate-y-1/2" style={{ bottom: `${(t / max) * 100}%` }}>
                                    {t === 0 ? '0' : compact.format(t / 100)}
                                </span>
                            ))}
                        </div>

                        <div className="relative flex-1">
                            {/* Líneas de guía: finas, sólidas, recesivas */}
                            {ticks.map((t) => (
                                <div key={t} className="absolute inset-x-0 h-px bg-chart-grid" style={{ bottom: `${(t / max) * 100}%` }} aria-hidden="true" />
                            ))}

                            <div className="absolute inset-0 flex">
                                {data.map((d, i) => {
                                    const total = totals[i];
                                    return (
                                        <button
                                            key={d.month}
                                            type="button"
                                            // El área de interacción es la franja completa del mes, mucho más grande que la columna.
                                            className="group relative flex h-full flex-1 items-end justify-center focus-visible:outline-none"
                                            aria-label={`${d.label}: cobrado ${money(d.collected)}, liquidaciones ${money(d.payouts)}, egresos ${money(d.expenses)}, ganancia neta ${money(d.net)}`}
                                            onPointerEnter={() => setActive(i)}
                                            onFocus={() => setActive(i)}
                                            onBlur={() => setActive(null)}
                                        >
                                            <span className={cn('absolute inset-0 rounded-md transition-colors', active === i && 'bg-muted/60')} aria-hidden="true" />
                                            <span
                                                className="relative flex w-6 flex-col-reverse gap-[2px] group-focus-visible:ring-2 group-focus-visible:ring-ring group-focus-visible:ring-offset-2 group-focus-visible:ring-offset-card"
                                                style={{ height: `${(total / max) * 100}%` }}
                                            >
                                                {SERIES.map((s, n) => {
                                                    const value = parts[i][s.key as SeriesKey];
                                                    if (value <= 0) return null;
                                                    const isTop = SERIES.slice(n + 1).every((o) => parts[i][o.key as SeriesKey] <= 0);
                                                    return (
                                                        <span
                                                            key={s.key}
                                                            className={cn('block w-full', isTop && 'rounded-t-[4px]')}
                                                            style={{ background: s.color, height: `${(value / total) * 100}%`, filter: active === i ? 'brightness(1.08)' : undefined }}
                                                        />
                                                    );
                                                })}
                                            </span>
                                            {/* Etiqueta directa solo en el último mes (nunca un número en cada columna) */}
                                            {i === last && total > 0 && (
                                                <span className="absolute left-1/2 -translate-x-1/2 text-xs font-medium tabular-nums text-foreground" style={{ bottom: `calc(${(total / max) * 100}% + 6px)` }}>
                                                    {compact.format(d.collected / 100)}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    {/* Eje x */}
                    <div className="mt-2 flex gap-3 pl-[3.75rem] text-xs text-muted-foreground" aria-hidden="true">
                        {data.map((d) => (
                            <span key={d.month} className={cn('flex-1 text-center', d.net < 0 && 'font-medium text-danger')}>
                                {d.label}
                                {d.net < 0 && ' ▼'}
                            </span>
                        ))}
                    </div>

                    {/* Tooltip único: valor primero, nombre después; filas con una línea del color de la serie */}
                    {active !== null && (
                        <div
                            role="status"
                            className="pointer-events-none absolute top-3 z-10 w-72 rounded-lg border bg-popover p-3 text-sm shadow-[var(--shadow-pop)]"
                            // Va al costado de la columna (a la derecha en la mitad izquierda del gráfico y viceversa),
                            // así nunca tapa las barras que se están mirando. El eje y ocupa 3.75rem.
                            style={{
                                left: `calc(3.75rem + (100% - 3.75rem) * ${(active + 0.5) / data.length})`,
                                transform: active >= data.length / 2 ? 'translateX(calc(-100% - 1.5rem))' : 'translateX(1.5rem)',
                            }}
                        >
                            <p className="mb-2 font-medium">{data[active].label}</p>
                            <dl className="flex flex-col gap-1.5">
                                <div className="flex items-baseline justify-between gap-3 border-b pb-1.5">
                                    <dd className="order-2 whitespace-nowrap font-semibold tabular-nums">{money(data[active].collected)}</dd>
                                    <dt className="text-muted-foreground">Cobrado</dt>
                                </div>
                                {SERIES.map((s) => (
                                    <div key={s.key} className="flex items-center justify-between gap-3">
                                        <dt className="flex items-center gap-2 text-muted-foreground">
                                            <span className="h-0.5 w-3 rounded-full" style={{ background: s.color }} aria-hidden="true" />
                                            {s.label}
                                        </dt>
                                        <dd className={cn('whitespace-nowrap font-semibold tabular-nums', s.key === 'net' && data[active].net < 0 && 'text-danger')}>
                                            {money(s.key === 'net' ? data[active].net : data[active][s.key])}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
