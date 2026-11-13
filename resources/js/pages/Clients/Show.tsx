import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, FileStack, KanbanSquare, Mail, MessageCircle, Pencil, Phone, Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
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

interface Props {
    client: ClientDetail;
    team: PersonRef[];
    vault: VaultRow[];
    vaultCategories: Options;
    assignable: PersonRef[];
    services: ServiceRow[];
    serviceOptions: ServiceOptions | null;
    invoices: { id: number; number: string; description: string; amount_label: string; due_date: string; status: string }[];
    can: { update: boolean; delete: boolean; assign: boolean; revealVault: boolean; manageVault: boolean; viewFinance: boolean; manageServices: boolean };
}

type Tab = 'datos' | 'servicios' | 'cobros' | 'boveda' | 'conversacion' | 'equipo';

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5 py-2.5 sm:flex-row sm:gap-4">
            <dt className="w-44 shrink-0 text-sm text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words text-sm">{children || <span className="text-muted-foreground">—</span>}</dd>
        </div>
    );
}

export default function Show({ client, team, vault, vaultCategories, assignable, services, serviceOptions, invoices, can }: Props) {
    const [tab, setTab] = useState<Tab>('datos');
    const [confirmDelete, setConfirmDelete] = useState(false);
    const auth = usePage<SharedProps>().props.auth!;
    const has = (permission: string) => auth.user.is_sysadmin || auth.permissions.includes(permission);

    const tabs: { id: Tab; label: string; show: boolean }[] = [
        { id: 'datos', label: 'Datos', show: true },
        { id: 'servicios', label: `Servicios (${services.length})`, show: true },
        { id: 'cobros', label: `Cobros (${invoices.length})`, show: can.viewFinance },
        { id: 'boveda', label: `Bóveda${can.revealVault ? ` (${vault.length})` : ''}`, show: can.revealVault },
        { id: 'conversacion', label: 'Conversación', show: has('messages.use') },
        { id: 'equipo', label: `Equipo (${team.length})`, show: true },
    ];

    return (
        <AppLayout title={client.name}>
            <Link href="/clientes" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-4" /> Clientes
            </Link>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h2 className="text-2xl font-semibold tracking-tight">{client.name}</h2>
                        <AccountStatusBadge status={client.account_status} />
                        {!client.is_active && <Badge>Inactivo</Badge>}
                    </div>
                    {client.name !== client.business_name && <p className="mt-1 text-sm text-muted-foreground">{client.business_name}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                    {has('content.view') && (
                        <>
                            <Link href={`/clientes/${client.id}/contenido`}>
                                <Button variant="outline" size="sm"><FileStack /> Contenido</Button>
                            </Link>
                            <Link href={`/clientes/${client.id}/tablero`}>
                                <Button variant="outline" size="sm"><KanbanSquare /> Tablero</Button>
                            </Link>
                        </>
                    )}
                    {client.whatsapp && (
                        <a href={`https://wa.me/${client.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noreferrer noopener">
                            <Button variant="outline" size="sm"><MessageCircle /> WhatsApp</Button>
                        </a>
                    )}
                    {can.update && (
                        <Link href={`/clientes/${client.id}/edit`}>
                            <Button variant="outline" size="sm"><Pencil /> Editar</Button>
                        </Link>
                    )}
                    {can.delete && (
                        <Button variant="ghost" size="icon" className="size-8" aria-label="Eliminar cliente" onClick={() => setConfirmDelete(true)}>
                            <Trash2 />
                        </Button>
                    )}
                </div>
            </div>

            <div className="mb-5 flex w-fit max-w-full overflow-x-auto rounded-md border bg-muted p-0.5" role="tablist">
                {tabs.filter((t) => t.show).map((t) => (
                    <button
                        key={t.id}
                        role="tab"
                        aria-selected={tab === t.id}
                        onClick={() => setTab(t.id)}
                        className={cn('shrink-0 whitespace-nowrap rounded px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors', tab === t.id && 'bg-card text-foreground shadow-sm')}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            {tab === 'datos' && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <Card>
                        <CardContent className="divide-y">
                            <Row label="Razón social">{client.business_name}</Row>
                            <Row label="CUIT"><span className="tabular-nums">{client.tax_id}</span></Row>
                            <Row label="Condición IVA">{client.tax_condition_label}</Row>
                            <Row label="Dirección">{[client.address, client.city].filter(Boolean).join(', ')}</Row>
                            <Row label="Sitio web">
                                {client.website && <a className="text-primary hover:underline" href={client.website} target="_blank" rel="noreferrer noopener">{client.website}</a>}
                            </Row>
                            <Row label="ID en LaiaTime">{client.laiatime_instance_id}</Row>
                        </CardContent>
                    </Card>
                    <div className="flex flex-col gap-5">
                        <Card>
                            <CardContent className="divide-y">
                                <Row label="Contacto">{client.contact_name}</Row>
                                <Row label="Email">
                                    {client.email && <a className="inline-flex items-center gap-1.5 text-primary hover:underline" href={`mailto:${client.email}`}><Mail className="size-3.5" />{client.email}</a>}
                                </Row>
                                <Row label="Teléfono">
                                    {client.phone && <a className="inline-flex items-center gap-1.5 hover:underline" href={`tel:${client.phone}`}><Phone className="size-3.5" />{client.phone}</a>}
                                </Row>
                            </CardContent>
                        </Card>
                        {client.notes && (
                            <Card>
                                <CardContent>
                                    <p className="mb-1.5 text-sm font-medium">Notas internas</p>
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
