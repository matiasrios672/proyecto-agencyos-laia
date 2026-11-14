import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, BellOff, BellRing, CalendarClock, Check, CheckCheck, CircleHelp, Eye, HandCoins, Landmark, Loader2, Mail, MailWarning, Send, Wallet, X } from 'lucide-react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FieldError, Input, Label } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout';
import type { Options } from '@/types';

interface Notice {
    key: string;
    invoice_id: number;
    type: 'antes' | 'mora';
    state: 'listo' | 'sin_email' | 'pausado';
    client_id: number;
    client: string;
    email: string | null;
    number: string;
    amount_label: string;
    due_date: string;
    account: { source: 'empleado' | 'agencia'; owner: string | null; missing_employee: boolean };
}

interface InvoiceRow {
    id: number;
    number: string;
    client: string;
    client_id: number;
    paused: boolean;
    has_email: boolean;
    description: string;
    amount_label: string;
    issue_date: string;
    due_date: string;
    days: number;
    reminders: { antes?: string; mora?: string };
}

interface PayoutGroup {
    user_id: number;
    user: string;
    count: number;
    totals: string[];
    ids: number[];
    items: { id: number; client: string; invoice: string; amount_label: string }[];
}

interface Stat { count: number; totals: string[] }

interface Props {
    auto: boolean;
    can: { manage: boolean };
    stats: { upcoming: Stat; overdue: Stat; to_settle: Stat; notices: number };
    notices: Notice[];
    overdue: InvoiceRow[];
    upcoming: InvoiceRow[];
    payouts: PayoutGroup[];
    history: { id: number; type: string; sent_at: string; by: string; client: string | null; number: string | null }[];
    paymentMethods: Options;
}

const fmt = (iso: string) => new Date(iso.slice(0, 10) + 'T00:00:00').toLocaleDateString('es-AR', { day: 'numeric', month: 'short' });
const today = () => new Date().toISOString().slice(0, 10);
const typeLabel = (t: string) => (t === 'mora' ? 'Aviso de mora' : 'Aviso previo');
const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;
const HELP_KEY = 'collections.help.dismissed';

function readDismissed(): boolean {
    try { return window.localStorage.getItem(HELP_KEY) === '1'; } catch { return false; }
}

function writeDismissed(v: boolean) {
    try { window.localStorage.setItem(HELP_KEY, v ? '1' : '0'); } catch { /* sin almacenamiento: solo en memoria */ }
}

/** Ayuda en contexto: un "?" que explica un término sin salir de la pantalla. */
function Hint({ children, label }: { children: ReactNode; label: string }) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <button type="button" aria-label={`Qué significa: ${label}`} className="inline-grid size-5 place-items-center rounded-full text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <CircleHelp className="size-4" />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-72 text-sm leading-relaxed" align="start">{children}</PopoverContent>
        </Popover>
    );
}

function SectionTitle({ id, step, title, hint, children }: { id: string; step: number; title: string; hint: ReactNode; children?: ReactNode }) {
    return (
        <div id={id} className="mb-3 flex scroll-mt-20 flex-wrap items-center gap-3">
            <span className="grid size-7 place-items-center rounded-full bg-navy text-xs font-bold text-white">{step}</span>
            <h2 className="font-display text-lg font-bold tracking-tight">{title}</h2>
            <Hint label={title}>{hint}</Hint>
            <div className="ml-auto flex items-center gap-2">{children}</div>
        </div>
    );
}

function Stat({ label, stat, icon: Icon, tone, target, extra }: { label: string; stat: Stat | number; icon: typeof Mail; tone: string; target: string; extra?: string }) {
    const count = typeof stat === 'number' ? stat : stat.count;
    const totals = typeof stat === 'number' ? [] : stat.totals;
    return (
        <a href={`#${target}`} className="group block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <Card className="h-full p-4 transition-shadow group-hover:shadow-md sm:p-5">
                <div className="flex items-center justify-between">
                    <p className="text-sm text-muted-foreground">{label}</p>
                    <span className={`grid size-9 place-items-center rounded-lg ${tone}`}><Icon className="size-[18px]" /></span>
                </div>
                <p className="mt-3 font-display text-3xl font-extrabold tabular-nums tracking-tight">{count}</p>
                <p className="mt-1 min-h-5 text-sm text-muted-foreground tabular-nums">{totals.length ? totals.join(' · ') : extra}</p>
            </Card>
        </a>
    );
}

function HowItWorks({ onClose }: { onClose: () => void }) {
    const steps = [
        { icon: Mail, title: 'Avisás al cliente', text: 'Unos días antes del vencimiento (y si se pasa la fecha) te proponemos el correo. Lo revisás y lo mandás con un clic.' },
        { icon: Wallet, title: 'Registrás el pago', text: 'Cuando el cliente transfiere, marcás el cobro como pagado. Eso lo deja al día y calcula lo que le toca al equipo.' },
        { icon: HandCoins, title: 'Liquidás al equipo', text: 'Cada pago registrado genera una liquidación. Cuando le pagás a la persona, la marcás como liquidada.' },
    ];
    return (
        <Card className="mb-6 border-sky/40 bg-sky/5 p-5">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-display text-lg font-bold">Cómo funciona Cobranzas</h2>
                    <p className="mt-1 text-sm text-muted-foreground">Esta pantalla te dice qué hacer hoy, en orden. Solo seguí los pasos de arriba hacia abajo.</p>
                </div>
                <Button variant="ghost" size="icon" className="size-8 shrink-0" aria-label="Cerrar la guía" onClick={onClose}><X /></Button>
            </div>
            <ol className="mt-4 grid gap-4 md:grid-cols-3">
                {steps.map((s, i) => (
                    <li key={s.title} className="flex gap-3">
                        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-navy text-white"><s.icon className="size-[18px]" /></span>
                        <div>
                            <p className="text-sm font-semibold">{i + 1}. {s.title}</p>
                            <p className="mt-0.5 text-sm text-muted-foreground">{s.text}</p>
                        </div>
                    </li>
                ))}
            </ol>
            <div className="mt-4 flex justify-end"><Button size="sm" onClick={onClose}><Check /> Entendido</Button></div>
        </Card>
    );
}

function ModeCard({ auto, canManage }: { auto: boolean; canManage: boolean }) {
    const [busy, setBusy] = useState(false);
    return (
        <Card className="mb-6 flex flex-wrap items-center gap-4 p-4 sm:p-5">
            <span className={`grid size-10 shrink-0 place-items-center rounded-lg ${auto ? 'bg-success/15 text-success' : 'bg-accent text-accent-foreground'}`}>
                {auto ? <BellRing className="size-5" /> : <Eye className="size-5" />}
            </span>
            <div className="min-w-56 flex-1">
                <p className="font-medium">{auto ? 'Avisos automáticos' : 'Avisos con revisión (recomendado para empezar)'}</p>
                <p className="text-sm text-muted-foreground">
                    {auto
                        ? 'Los avisos salen solos todos los días a las 9:00. Podés pausar a un cliente puntual.'
                        : 'El sistema prepara los avisos y vos decidís cuándo mandarlos. No sale ningún correo sin tu confirmación.'}
                </p>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <Switch
                    checked={auto}
                    disabled={!canManage || busy}
                    aria-label="Enviar avisos automáticamente"
                    onCheckedChange={(v) => { setBusy(true); router.post('/cobranzas/modo', { auto: v }, { preserveScroll: true, onFinish: () => setBusy(false) }); }}
                />
                Enviar solos
            </label>
        </Card>
    );
}

function Timeline({ row }: { row: InvoiceRow }) {
    const late = row.days < 0;
    const steps = [
        { label: 'Emitido', date: row.issue_date, done: true },
        { label: 'Aviso previo', date: row.reminders.antes, done: !!row.reminders.antes },
        { label: late ? 'Venció' : 'Vence', date: row.due_date, done: late, alert: late },
        { label: 'Aviso de mora', date: row.reminders.mora, done: !!row.reminders.mora, alert: late && !row.reminders.mora },
        { label: 'Pago', date: undefined, done: false },
    ];
    return (
        <ol className="mt-3 flex items-start gap-0" aria-label="Estado del cobro">
            {steps.map((s, i) => (
                <li key={s.label} className="relative flex-1 text-center">
                    {i > 0 && <span aria-hidden className={`absolute right-1/2 top-[7px] h-0.5 w-full ${s.done ? 'bg-navy' : 'bg-border'}`} />}
                    <span className={`relative z-10 mx-auto block size-4 rounded-full border-2 ${s.done ? 'border-navy bg-navy' : s.alert ? 'border-danger bg-background' : 'border-border bg-background'}`} />
                    <p className={`mt-1 text-[11px] leading-tight ${s.alert ? 'font-semibold text-danger' : 'text-muted-foreground'}`}>{s.label}</p>
                    <p className="text-[11px] tabular-nums text-muted-foreground">{s.date ? fmt(s.date) : '—'}</p>
                </li>
            ))}
        </ol>
    );
}

export default function Index({ auto, can, stats, notices, overdue, upcoming, payouts, history, paymentMethods }: Props) {
    const [showHelp, setShowHelp] = useState(false);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [previewing, setPreviewing] = useState<Notice | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [paying, setPaying] = useState<InvoiceRow | null>(null);
    const [settling, setSettling] = useState<PayoutGroup | null>(null);

    useEffect(() => { setShowHelp(!readDismissed()); }, []);

    const ready = notices.filter((n) => n.state === 'listo');
    // Si la lista cambia (se envió, se pausó), se descartan las selecciones que ya no existen.
    useEffect(() => { setSelected((prev) => new Set([...prev].filter((k) => ready.some((n) => n.key === k)))); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [notices]);

    const chosen = ready.filter((n) => selected.has(n.key));
    const allChosen = ready.length > 0 && chosen.length === ready.length;
    const toggle = (key: string) => setSelected((prev) => { const next = new Set(prev); if (next.has(key)) next.delete(key); else next.add(key); return next; });

    const closeHelp = () => { setShowHelp(false); writeDismissed(true); };
    const openHelp = () => { setShowHelp(true); writeDismissed(false); };

    const send = (items: Notice[], onDone?: () => void) =>
        router.post('/cobranzas/avisos', { items: items.map((n) => ({ invoice_id: n.invoice_id, type: n.type })) }, { preserveScroll: true, onFinish: onDone });
    const pause = (clientId: number, paused: boolean) => router.post(`/clientes/${clientId}/avisos`, { paused }, { preserveScroll: true });

    const nothingToDo = notices.length === 0 && overdue.length === 0 && upcoming.length === 0 && payouts.length === 0;

    return (
        <AppLayout title="Cobranzas">
            <div className="mb-5 flex flex-wrap items-center gap-3">
                <p className="max-w-2xl text-sm text-muted-foreground">Todo lo que hay que hacer con la plata de los clientes y del equipo, en orden y sin vueltas.</p>
                {!showHelp && <Button variant="outline" size="sm" className="ml-auto" onClick={openHelp}><CircleHelp /> ¿Cómo funciona?</Button>}
            </div>

            {showHelp && <HowItWorks onClose={closeHelp} />}

            <div className="mb-6 grid gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
                <Stat label="Avisos por enviar" stat={stats.notices} icon={Mail} tone="bg-accent text-accent-foreground" target="avisos" extra={stats.notices === 0 ? 'Todo al día' : 'Esperan tu revisión'} />
                <Stat label="Vencen esta semana" stat={stats.upcoming} icon={CalendarClock} tone="bg-sky/20 text-navy" target="cobros" extra="Nada por vencer" />
                <Stat label="En mora" stat={stats.overdue} icon={AlertTriangle} tone="bg-danger/15 text-danger" target="cobros" extra="Sin deuda vencida" />
                <Stat label="Para liquidar al equipo" stat={stats.to_settle} icon={HandCoins} tone="bg-success/15 text-success" target="liquidar" extra="Nada pendiente" />
            </div>

            <ModeCard auto={auto} canManage={can.manage} />

            {nothingToDo && (
                <Card className="mb-6">
                    <EmptyState
                        icon={CheckCheck}
                        title="Estás al día"
                        description="No hay avisos, cobros pendientes ni liquidaciones. Los cobros mensuales se generan solos unos días antes del vencimiento, o podés crear uno manual desde Cobros."
                        action={<Button variant="outline" size="sm" onClick={() => router.get('/cobros')}>Ir a Cobros</Button>}
                    />
                </Card>
            )}

            {/* 1 · Avisos */}
            <section className="mb-8">
                <SectionTitle id="avisos" step={1} title="Avisar a los clientes" hint={<>Un <b>aviso</b> es el correo que le recuerda al cliente un pago. Mandamos uno <b>previo</b> (antes del vencimiento) y uno de <b>mora</b> (si ya se pasó la fecha). Cada uno se manda una sola vez por cobro.</>}>
                    {can.manage && ready.length > 0 && (
                        <>
                            <Button variant="outline" size="sm" onClick={() => setSelected(allChosen ? new Set() : new Set(ready.map((n) => n.key)))}>{allChosen ? 'Quitar selección' : `Elegir los ${ready.length}`}</Button>
                            <Button size="sm" disabled={chosen.length === 0} onClick={() => setConfirming(true)}><Send /> Enviar {chosen.length > 0 ? plural(chosen.length, 'aviso', 'avisos') : 'elegidos'}</Button>
                        </>
                    )}
                </SectionTitle>
                <Card className="overflow-hidden">
                    {notices.length === 0 ? (
                        <EmptyState icon={Mail} title="No hay avisos pendientes" description="Cuando un cobro esté por vencer o se venza, el aviso aparece acá para que lo revises y lo mandes." />
                    ) : (
                        <ul className="divide-y">
                            {notices.map((n) => (
                                <li key={n.key} className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
                                    {can.manage && (
                                        <input
                                            type="checkbox"
                                            className="size-4 accent-[var(--brand-navy)]"
                                            aria-label={`Elegir aviso a ${n.client}`}
                                            disabled={n.state !== 'listo'}
                                            checked={selected.has(n.key)}
                                            onChange={() => toggle(n.key)}
                                        />
                                    )}
                                    <div className="min-w-52 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link href={`/clientes/${n.client_id}`} className="font-medium hover:underline">{n.client}</Link>
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${n.type === 'mora' ? 'bg-danger/15 text-danger' : 'bg-accent text-accent-foreground'}`}>{typeLabel(n.type)}</span>
                                        </div>
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            <span className="tabular-nums">{n.number}</span> · vence el {fmt(n.due_date)} · {n.email ?? 'sin email cargado'}
                                        </p>
                                        <p className={`mt-0.5 flex items-center gap-1.5 text-sm ${n.account.missing_employee ? 'text-warning' : 'text-muted-foreground'}`}>
                                            <Landmark className="size-4" />
                                            {n.account.source === 'empleado'
                                                ? <>El cliente transfiere a la cuenta de <span className="font-medium text-foreground">{n.account.owner}</span></>
                                                : n.account.missing_employee
                                                    ? <>Va la cuenta de la agencia: quien tiene el servicio a cargo todavía no cargó la suya (<Link href="/equipo" className="font-medium underline">Equipo</Link>)</>
                                                    : 'El cliente transfiere a la cuenta de la agencia'}
                                        </p>
                                        {n.state === 'sin_email' && (
                                            <p className="mt-1 flex items-center gap-1.5 text-sm text-danger"><MailWarning className="size-4" /> No se puede avisar: <Link href={`/clientes/${n.client_id}`} className="font-medium underline">cargá su email</Link>.</p>
                                        )}
                                        {n.state === 'pausado' && (
                                            <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground"><BellOff className="size-4" /> Avisos pausados para este cliente.</p>
                                        )}
                                    </div>
                                    <p className="font-display text-base font-bold tabular-nums">{n.amount_label}</p>
                                    <div className="flex gap-1">
                                        {n.state !== 'sin_email' && <Button variant="outline" size="sm" onClick={() => setPreviewing(n)}><Eye /> Ver correo</Button>}
                                        {can.manage && n.state === 'pausado' && <Button variant="outline" size="sm" onClick={() => pause(n.client_id, false)}><BellRing /> Reanudar</Button>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </section>

            {/* 2 · Cobros */}
            <section className="mb-8">
                <SectionTitle id="cobros" step={2} title="Registrar los pagos" hint={<>Cuando el cliente te transfiere, tocá <b>Registrar pago</b>. Si pasa mucho tiempo, el cobro queda <b>en mora</b> y el cliente figura con deuda.</>}>
                    <Button variant="ghost" size="sm" onClick={() => router.get('/cobros')}>Ver todos los cobros</Button>
                </SectionTitle>
                {overdue.length === 0 && upcoming.length === 0 ? (
                    <Card><EmptyState icon={Wallet} title="No hay cobros esperando pago" description="Los cobros pendientes (en mora o por vencer esta semana) aparecen acá con su línea de tiempo." /></Card>
                ) : (
                    <div className="grid gap-4">
                        <InvoiceList title="En mora" tone="danger" rows={overdue} canManage={can.manage} onPay={setPaying} onPause={pause} />
                        <InvoiceList title="Vencen esta semana" tone="neutral" rows={upcoming} canManage={can.manage} onPay={setPaying} onPause={pause} />
                    </div>
                )}
            </section>

            {/* 3 · Liquidaciones */}
            <section className="mb-8">
                <SectionTitle id="liquidar" step={3} title="Liquidar al equipo" hint={<>Una <b>liquidación</b> es lo que le corresponde a cada persona del equipo por un pago que el cliente ya hizo. Se genera sola al registrar el pago; vos solo la marcás cuando le transferís.</>}>
                    <Button variant="ghost" size="sm" onClick={() => router.get('/liquidaciones')}>Ver historial</Button>
                </SectionTitle>
                {payouts.length === 0 ? (
                    <Card><EmptyState icon={HandCoins} title="No hay nada para liquidar" description="Cuando registres un pago de un servicio con empleado asignado, su parte aparece acá." /></Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2">
                        {payouts.map((g) => (
                            <Card key={g.user_id} className="p-4 sm:p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium">{g.user}</p>
                                        <p className="text-sm text-muted-foreground">{plural(g.count, 'liquidación pendiente', 'liquidaciones pendientes')}</p>
                                    </div>
                                    <p className="text-right font-display text-lg font-extrabold tabular-nums">{g.totals.map((t) => <span key={t} className="block">{t}</span>)}</p>
                                </div>
                                <ul className="mt-3 space-y-1 border-t pt-3 text-sm text-muted-foreground">
                                    {g.items.map((it) => <li key={it.id} className="flex justify-between gap-3"><span className="truncate">{it.client} · {it.invoice}</span><span className="tabular-nums">{it.amount_label}</span></li>)}
                                </ul>
                                {can.manage && <Button className="mt-4 w-full" onClick={() => setSettling(g)}><HandCoins /> Ya le pagué a {g.user.split(' ')[0]}</Button>}
                            </Card>
                        ))}
                    </div>
                )}
            </section>

            {/* Historial */}
            <section className="mb-4">
                <h2 className="mb-3 font-display text-lg font-bold tracking-tight">Últimos avisos enviados</h2>
                <Card className="overflow-hidden">
                    {history.length === 0 ? (
                        <EmptyState icon={Mail} title="Todavía no se envió ningún aviso" />
                    ) : (
                        <ul className="divide-y text-sm">
                            {history.map((h) => (
                                <li key={h.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                                    <span className="font-medium">{h.client ?? 'Cliente'}</span>
                                    <span className="text-muted-foreground">{typeLabel(h.type)} · <span className="tabular-nums">{h.number}</span></span>
                                    <span className="ml-auto text-muted-foreground">{fmt(h.sent_at)} · {h.by}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </section>

            <PreviewDialog notice={previewing} onClose={() => setPreviewing(null)} canSend={can.manage} onSend={(n) => { send([n], () => setPreviewing(null)); }} />
            <ConfirmSendDialog open={confirming} notices={chosen} onClose={() => setConfirming(false)} onConfirm={(done) => send(chosen, () => { done(); setConfirming(false); setSelected(new Set()); })} />
            <PayDialog invoice={paying} methods={paymentMethods} onClose={() => setPaying(null)} />
            <SettleDialog group={settling} onClose={() => setSettling(null)} />
        </AppLayout>
    );
}

function InvoiceList({ title, tone, rows, canManage, onPay, onPause }: { title: string; tone: 'danger' | 'neutral'; rows: InvoiceRow[]; canManage: boolean; onPay: (r: InvoiceRow) => void; onPause: (clientId: number, paused: boolean) => void }) {
    if (rows.length === 0) return null;
    return (
        <Card className="overflow-hidden">
            <div className={`border-b px-4 py-2.5 text-sm font-semibold ${tone === 'danger' ? 'bg-danger/10 text-danger' : 'bg-muted/50'}`}>{title} · {rows.length}</div>
            <ul className="divide-y">
                {rows.map((r) => (
                    <li key={r.id} className="p-4">
                        <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                            <div className="min-w-52 flex-1">
                                <Link href={`/clientes/${r.client_id}`} className="font-medium hover:underline">{r.client}</Link>
                                <p className="mt-0.5 text-sm text-muted-foreground">{r.description} · <span className="tabular-nums">{r.number}</span></p>
                                <p className={`mt-0.5 text-sm font-medium ${r.days < 0 ? 'text-danger' : 'text-foreground'}`}>
                                    {r.days < 0 ? `Venció hace ${plural(-r.days, 'día', 'días')}` : r.days === 0 ? 'Vence hoy' : r.days === 1 ? 'Vence mañana' : `Vence en ${r.days} días`}
                                </p>
                            </div>
                            <p className="font-display text-base font-bold tabular-nums">{r.amount_label}</p>
                            {canManage && (
                                <div className="flex items-center gap-1">
                                    <Button variant="ghost" size="icon" className="size-8" aria-label={r.paused ? `Reanudar avisos a ${r.client}` : `Pausar avisos a ${r.client}`} title={r.paused ? 'Avisos pausados: tocá para reanudar' : 'Pausar avisos a este cliente'} onClick={() => onPause(r.client_id, !r.paused)}>
                                        {r.paused ? <BellOff className="text-danger" /> : <BellRing />}
                                    </Button>
                                    <Button size="sm" onClick={() => onPay(r)}><Wallet /> Registrar pago</Button>
                                </div>
                            )}
                        </div>
                        <Timeline row={r} />
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function PreviewDialog({ notice, onClose, canSend, onSend }: { notice: Notice | null; onClose: () => void; canSend: boolean; onSend: (n: Notice) => void }) {
    const [data, setData] = useState<{ to: string | null; subject: string; html: string } | null>(null);
    const [failed, setFailed] = useState(false);
    const [sending, setSending] = useState(false);

    useEffect(() => {
        if (!notice) return;
        setData(null);
        setFailed(false);
        setSending(false);
        const ctrl = new AbortController();
        fetch(`/cobranzas/avisos/${notice.invoice_id}/${notice.type}/vista-previa`, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('preview'))))
            .then(setData)
            .catch((e) => { if (e.name !== 'AbortError') setFailed(true); });
        return () => ctrl.abort();
    }, [notice]);

    return (
        <Dialog open={notice !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Así lo va a ver {notice?.client}</DialogTitle>
                    <DialogDescription>Es una vista previa: todavía no se envió nada.{data?.to ? ` Va a ${data.to}.` : ''}</DialogDescription>
                </DialogHeader>
                <DialogBody>
                    {failed && <p role="alert" className="rounded-md border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger">No pudimos cargar la vista previa. Probá de nuevo.</p>}
                    {!data && !failed && <div className="grid h-64 place-items-center text-muted-foreground"><Loader2 className="animate-spin" /></div>}
                    {data && (
                        <>
                            <p className="mb-2 text-sm"><span className="text-muted-foreground">Asunto:</span> <span className="font-medium">{data.subject}</span></p>
                            <iframe title="Vista previa del correo" srcDoc={data.html} sandbox="" className="h-[420px] w-full rounded-md border bg-white" />
                        </>
                    )}
                </DialogBody>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cerrar</Button>
                    {canSend && notice && notice.state === 'listo' && (
                        <Button disabled={!data || sending} onClick={() => { setSending(true); onSend(notice); }}>{sending ? <Loader2 className="animate-spin" /> : <Send />} Enviar este aviso</Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ConfirmSendDialog({ open, notices, onClose, onConfirm }: { open: boolean; notices: Notice[]; onClose: () => void; onConfirm: (done: () => void) => void }) {
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (open) setBusy(false); }, [open]);
    return (
        <Dialog open={open} onOpenChange={(o) => !o && !busy && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Enviar {plural(notices.length, 'aviso', 'avisos')}</DialogTitle>
                    <DialogDescription>Revisá a quién le va a llegar. Un correo enviado no se puede deshacer.</DialogDescription>
                </DialogHeader>
                <DialogBody>
                    <ul className="divide-y rounded-md border text-sm">
                        {notices.map((n) => (
                            <li key={n.key} className="flex flex-wrap items-center justify-between gap-x-3 px-3 py-2">
                                <span><span className="font-medium">{n.client}</span> <span className="text-muted-foreground">· {typeLabel(n.type)}</span></span>
                                <span className="text-muted-foreground">{n.email}</span>
                            </li>
                        ))}
                    </ul>
                </DialogBody>
                <DialogFooter>
                    <Button variant="outline" disabled={busy} onClick={onClose}>Cancelar</Button>
                    <Button disabled={busy || notices.length === 0} onClick={() => { setBusy(true); onConfirm(() => setBusy(false)); }}>{busy ? <Loader2 className="animate-spin" /> : <Send />} Sí, enviar</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function PayDialog({ invoice, methods, onClose }: { invoice: InvoiceRow | null; methods: Options; onClose: () => void }) {
    const form = useForm({ payment_method: 'transferencia', payment_reference: '', paid_at: today() });
    const err = (k: string) => (form.errors as Record<string, string | undefined>)[k];

    useEffect(() => { if (invoice) { form.clearErrors(); form.setData({ payment_method: 'transferencia', payment_reference: '', paid_at: today() }); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [invoice]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (invoice) form.post(`/cobros/${invoice.id}/pagar`, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={invoice !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Registrar pago</DialogTitle>
                        <DialogDescription>{invoice?.client} · <span className="font-medium text-foreground">{invoice?.amount_label}</span>. Se deja al cliente al día y, si el servicio tiene empleado asignado, se genera su liquidación.</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        {err('invoice') && <p role="alert" className="rounded-md border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger">{err('invoice')}</p>}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="c-method">Medio de pago</Label>
                                <Select id="c-method" value={form.data.payment_method} onChange={(e) => form.setData('payment_method', e.target.value)}>
                                    {Object.entries(methods).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </Select>
                                <FieldError message={form.errors.payment_method} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="c-date">Fecha en que pagó</Label>
                                <Input id="c-date" type="date" max={today()} value={form.data.paid_at} onChange={(e) => form.setData('paid_at', e.target.value)} />
                                <FieldError message={form.errors.paid_at} />
                            </div>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="c-ref">Referencia (opcional)</Label>
                            <Input id="c-ref" placeholder="N.º de transferencia, comprobante…" value={form.data.payment_reference} onChange={(e) => form.setData('payment_reference', e.target.value)} />
                            <FieldError message={form.errors.payment_reference} />
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing ? <Loader2 className="animate-spin" /> : <Wallet />} Registrar pago</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function SettleDialog({ group, onClose }: { group: PayoutGroup | null; onClose: () => void }) {
    const form = useForm({ ids: [] as number[], note: '' });
    const err = (k: string) => (form.errors as Record<string, string | undefined>)[k];

    useEffect(() => { if (group) { form.clearErrors(); form.setData({ ids: group.ids, note: '' }); } /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [group]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/liquidaciones/liquidar', { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={group !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <form onSubmit={submit} className="flex min-h-0 flex-col">
                    <DialogHeader>
                        <DialogTitle>Marcar como liquidado</DialogTitle>
                        <DialogDescription>
                            {group && <>Vas a marcar {plural(group.count, 'liquidación', 'liquidaciones')} de <span className="font-medium text-foreground">{group.user}</span> por <span className="font-medium text-foreground">{group.totals.join(' + ')}</span>. Hacelo cuando ya le hayas transferido. La persona recibe un aviso.</>}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        {err('ids') && <p role="alert" className="rounded-md border border-danger/30 bg-danger/10 px-3 py-2 text-sm text-danger">{err('ids')}</p>}
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="s-note">Nota (opcional)</Label>
                            <Input id="s-note" maxLength={200} placeholder="Ej.: transferencia del 15/11" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                            <FieldError message={form.errors.note} />
                        </div>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Cancelar</Button>
                        <Button type="submit" disabled={form.processing}>{form.processing ? <Loader2 className="animate-spin" /> : <HandCoins />} Confirmar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
