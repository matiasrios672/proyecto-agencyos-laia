import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Building2, FileStack, IdCard, KanbanSquare, KeyRound, Layers, Mail, MessageCircle, MessageSquare, Pencil, Phone, Receipt, Trash2, Users, Wallet, type LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { LaiaTimePanel } from '@/components/clients/LaiaTimePanel';
import { ThreadPanel } from '@/components/messages/ThreadPanel';
import { ServicesPanel, type ServiceOptions, type ServiceRow } from '@/components/clients/ServicesPanel';
import { TeamPanel } from '@/components/clients/TeamPanel';
import { VaultPanel } from '@/components/clients/VaultPanel';
import { AccountStatusBadge, InvoiceStatusBadge } from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { ClientDetail, Options, PersonRef, SharedProps, VaultRow } from '@/types';

interface Summary {
    services_active: number;
    monthly: string[];
    pending: string[];
    overdue_count: number;
}

interface Props {
    client: ClientDetail;
    team: PersonRef[];
    vault: VaultRow[];
    vaultCategories: Options;
    assignable: PersonRef[];
    services: ServiceRow[];
    summary: Summary | null;
    serviceOptions: ServiceOptions | null;
    invoices: { id: number; number: string; description: string; amount_label: string; due_date: string; status: string }[];
    can: { update: boolean; delete: boolean; assign: boolean; revealVault: boolean; manageVault: boolean; viewFinance: boolean; manageServices: boolean };
}

type Tab = 'datos' | 'servicios' | 'cobros' | 'boveda' | 'conversacion' | 'equipo';

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:gap-4">
            <dt className="w-40 shrink-0 text-sm text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words text-sm">{children || <span className="text-muted-foreground">—</span>}</dd>
        </div>
    );
}

function Section({ icon: Icon, title, children }: { icon: LucideIcon; title: string; children: ReactNode }) {
    return (
        <Card>
            <div className="flex items-center gap-2 border-b px-5 py-3">
                <span className="grid size-7 place-items-center rounded-md bg-accent text-accent-foreground"><Icon className="size-4" /></span>
                <h3 className="font-display text-sm font-bold">{title}</h3>
            </div>
            <CardContent className="divide-y pt-1">{children}</CardContent>
        </Card>
    );
}

function Metric({ label, value, icon: Icon, tone, alert }: { label: string; value: ReactNode; icon: LucideIcon; tone: string; alert?: boolean }) {
    return (
        <Card className={cn('flex items-center gap-3.5 p-4', alert && 'border-danger/40')}>
            <span className={cn('grid size-10 shrink-0 place-items-center rounded-xl', tone)}><Icon className="size-5" /></span>
            <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="truncate font-display text-lg font-extrabold leading-tight tabular-nums">{value}</p>
            </div>
        </Card>
    );
}

export default function Show({ client, team, vault, vaultCategories, assignable, services, summary, serviceOptions, invoices, can }: Props) {
    const [tab, setTab] = useState<Tab>('datos');
    const [confirmDelete, setConfirmDelete] = useState(false);
    const auth = usePage<SharedProps>().props.auth!;
    const has = (permission: string) => auth.user.is_sysadmin || auth.permissions.includes(permission);

    const tabs: { id: Tab; label: string; icon: LucideIcon; count?: number; show: boolean }[] = [
        { id: 'datos', label: 'Datos', icon: IdCard, show: true },
        { id: 'servicios', label: 'Servicios', icon: Layers, count: services.length, show: true },
        { id: 'cobros', label: 'Cobros', icon: Receipt, count: invoices.length, show: can.viewFinance },
        { id: 'boveda', label: 'Bóveda', icon: KeyRound, count: can.revealVault ? vault.length : undefined, show: can.revealVault },
        { id: 'conversacion', label: 'Conversación', icon: MessageSquare, show: has('messages.use') },
        { id: 'equipo', label: 'Equipo', icon: Users, count: team.length, show: true },
    ];

    const wa = (client.whatsapp ?? '').replace(/\D/g, '');
    const ghost = 'border-white/20 bg-white/10 text-white hover:bg-white/20 hover:text-white';

    return (
        <AppLayout title={client.name}>
            <Link href="/clientes" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-4" /> Clientes
            </Link>

            {/* Cabecera */}
            <section className="relative mb-5 overflow-hidden rounded-2xl bg-gradient-to-br from-navy via-navy to-[#22389a] p-5 text-white shadow-lg sm:p-7">
                <span aria-hidden className="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full bg-sky/15 blur-2xl" />
                <span aria-hidden className="pointer-events-none absolute -bottom-20 right-24 size-56 rounded-full bg-peach/15 blur-2xl" />
                <div className="relative flex flex-wrap items-start gap-5">
                    <Avatar name={client.name} className="size-16 text-xl ring-4 ring-white/20" />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h2 className="font-display text-2xl font-extrabold tracking-tight">{client.name}</h2>
                            <span className="rounded-full bg-white"><AccountStatusBadge status={client.account_status} /></span>
                            {!client.is_active && <Badge>Inactivo</Badge>}
                        </div>
                        <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/75">
                            {client.name !== client.business_name && <span className="inline-flex items-center gap-1.5"><Building2 className="size-3.5" />{client.business_name}</span>}
                            {client.tax_id && <span className="tabular-nums">CUIT {client.tax_id}</span>}
                            {client.city && <span>{client.city}</span>}
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {wa && <a href={`https://wa.me/${wa}`} target="_blank" rel="noreferrer noopener"><Button variant="outline" size="sm" className={ghost}><MessageCircle /> WhatsApp</Button></a>}
                            {client.email && <a href={`mailto:${client.email}`}><Button variant="outline" size="sm" className={ghost}><Mail /> Email</Button></a>}
                            {client.phone && <a href={`tel:${client.phone}`}><Button variant="outline" size="sm" className={ghost}><Phone /> Llamar</Button></a>}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {has('content.view') && (
                            <>
                                <Link href={`/clientes/${client.id}/contenido`}><Button variant="outline" size="sm" className={ghost}><FileStack /> Contenido</Button></Link>
                                <Link href={`/clientes/${client.id}/tablero`}><Button variant="outline" size="sm" className={ghost}><KanbanSquare /> Tablero</Button></Link>
                            </>
                        )}
                        {can.update && <Link href={`/clientes/${client.id}/edit`}><Button variant="outline" size="sm" className={ghost}><Pencil /> Editar</Button></Link>}
                        {can.delete && (
                            <Button variant="outline" size="icon" className={cn('size-8', ghost)} aria-label="Eliminar cliente" onClick={() => setConfirmDelete(true)}><Trash2 /></Button>
                        )}
                    </div>
                </div>
            </section>

            {/* Indicadores */}
            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Metric label="Servicios activos" value={summary ? summary.services_active : services.length} icon={Layers} tone="bg-accent text-accent-foreground" />
                {summary && <Metric label="Abono mensual" value={summary.monthly.length ? summary.monthly.join(' + ') : '—'} icon={Wallet} tone="bg-sky/20 text-navy" />}
                {summary && (
                    <Metric
                        label={summary.overdue_count > 0 ? `En mora (${summary.overdue_count})` : 'Por cobrar'}
                        value={summary.pending.length ? summary.pending.join(' + ') : 'Nada pendiente'}
                        icon={summary.overdue_count > 0 ? AlertTriangle : Receipt}
                        tone={summary.overdue_count > 0 ? 'bg-danger/15 text-danger' : 'bg-success/15 text-success'}
                        alert={summary.overdue_count > 0}
                    />
                )}
                <Metric label="Equipo asignado" value={team.length === 0 ? 'Sin asignar' : `${team.length} ${team.length === 1 ? 'persona' : 'personas'}`} icon={Users} tone="bg-peach/25 text-navy" />
            </div>

            <div className="mb-5 flex overflow-x-auto border-b" role="tablist">
                {tabs.filter((t) => t.show).map((t) => (
                    <button
                        key={t.id}
                        role="tab"
                        aria-selected={tab === t.id}
                        onClick={() => setTab(t.id)}
                        className={cn(
                            '-mb-px inline-flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-4 py-2.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            tab === t.id && 'border-navy text-foreground',
                        )}
                    >
                        <t.icon className="size-4" />
                        {t.label}
                        {t.count !== undefined && <span className={cn('rounded-full px-1.5 text-xs tabular-nums', tab === t.id ? 'bg-accent text-accent-foreground' : 'bg-muted')}>{t.count}</span>}
                    </button>
                ))}
            </div>

            {tab === 'datos' && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <Section icon={Building2} title="Datos fiscales">
                        <Row label="Razón social">{client.business_name}</Row>
                        <Row label="CUIT"><span className="tabular-nums">{client.tax_id}</span></Row>
                        <Row label="Condición IVA">{client.tax_condition_label}</Row>
                        <Row label="Dirección">{[client.address, client.city].filter(Boolean).join(', ')}</Row>
                        <Row label="Sitio web">
                            {client.website && <a className="text-primary hover:underline" href={client.website} target="_blank" rel="noreferrer noopener">{client.website}</a>}
                        </Row>
                        <Row label="ID en LaiaTime">{client.laiatime_instance_id}</Row>
                    </Section>
                    <div className="flex flex-col gap-5">
                        <Section icon={Phone} title="Contacto">
                            <Row label="Contacto">{client.contact_name}</Row>
                            <Row label="Email">
                                {client.email && <a className="inline-flex items-center gap-1.5 text-primary hover:underline" href={`mailto:${client.email}`}><Mail className="size-3.5" />{client.email}</a>}
                            </Row>
                            <Row label="Teléfono">
                                {client.phone && <a className="inline-flex items-center gap-1.5 hover:underline" href={`tel:${client.phone}`}><Phone className="size-3.5" />{client.phone}</a>}
                            </Row>
                        </Section>
                        {client.notes && (
                            <Card className="border-peach/50 bg-peach/10">
                                <CardContent>
                                    <p className="mb-1.5 text-sm font-semibold">Notas internas</p>
                                    <p className="whitespace-pre-wrap text-sm text-muted-foreground">{client.notes}</p>
                                </CardContent>
                            </Card>
                        )}
                        {can.viewFinance && <LaiaTimePanel clientId={client.id} />}
                    </div>
                </div>
            )}

            {tab === 'conversacion' && has('messages.use') && <ThreadPanel subjectType="client" subjectId={client.id} />}

            {tab === 'servicios' && (
                <ServicesPanel clientId={client.id} services={services} options={serviceOptions} assignable={assignable} canFinance={can.viewFinance} canManage={can.manageServices} />
            )}

            {tab === 'cobros' && can.viewFinance && (
                <Card className="overflow-hidden">
                    {invoices.length === 0 ? (
                        <p className="p-8 text-center text-sm text-muted-foreground">Este cliente todavía no tiene cobros.</p>
                    ) : (
                        <ul className="divide-y">
                            {invoices.map((i) => (
                                <li key={i.id} className="flex flex-wrap items-center gap-x-6 gap-y-1 p-4">
                                    <div className="min-w-56 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{i.description}</span>
                                            <InvoiceStatusBadge status={i.status} />
                                        </div>
                                        <p className="text-sm text-muted-foreground"><span className="tabular-nums">{i.number}</span> · vence el {new Date(i.due_date + 'T00:00:00').toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                                    </div>
                                    <p className="font-display text-base font-bold tabular-nums">{i.amount_label}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="border-t bg-muted/30 px-4 py-2.5 text-right">
                        <Link href={`/cobros?client=${client.id}`} className="text-sm font-medium text-primary hover:underline">Ver todos los cobros</Link>
                    </div>
                </Card>
            )}

            {tab === 'boveda' && can.revealVault && (
                <VaultPanel clientId={client.id} items={vault} categories={vaultCategories} canManage={can.manageVault} />
            )}

            {tab === 'equipo' && <TeamPanel clientId={client.id} team={team} assignable={assignable} canAssign={can.assign} />}

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title="¿Eliminar este cliente?"
                description={`Se eliminará "${client.name}". Podés pedirle a un sysadmin que lo recupere si fue un error.`}
                confirmLabel="Eliminar cliente"
                destructive
                onConfirm={() => router.delete(`/clientes/${client.id}`)}
            />
        </AppLayout>
    );
}
