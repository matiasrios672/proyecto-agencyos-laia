import { AlertTriangle, CheckCircle2, Clock, FilePen, Mail, Send, UserX, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

const account = {
    al_dia: { label: 'Al día', variant: 'success', Icon: CheckCircle2 },
    por_vencer: { label: 'Por vencer', variant: 'warning', Icon: Clock },
    deuda: { label: 'Deuda', variant: 'danger', Icon: AlertTriangle },
} as const;

export type AccountStatusValue = keyof typeof account;

/** Semáforo de estado de cuenta: siempre ícono + texto, nunca solo color. */
export function AccountStatusBadge({ status }: { status: string }) {
    const cfg = account[status as AccountStatusValue] ?? { label: status, variant: 'default', Icon: Clock };
    return (
        <Badge variant={cfg.variant}>
            <cfg.Icon className="size-3" />
            {cfg.label}
        </Badge>
    );
}

const quote = {
    borrador: { label: 'Borrador', variant: 'default', Icon: FilePen },
    enviado: { label: 'Enviado', variant: 'primary', Icon: Send },
    aprobado: { label: 'Aprobado', variant: 'success', Icon: CheckCircle2 },
    rechazado: { label: 'Rechazado', variant: 'danger', Icon: XCircle },
    vencido: { label: 'Vencido', variant: 'warning', Icon: Clock },
} as const;

export function QuoteStatusBadge({ status }: { status: string }) {
    const cfg = quote[status as keyof typeof quote] ?? quote.borrador;
    return (
        <Badge variant={cfg.variant}>
            <cfg.Icon className="size-3" />
            {cfg.label}
        </Badge>
    );
}

const invoice = {
    pendiente: { label: 'Pendiente', variant: 'primary', Icon: Clock },
    vencida: { label: 'Vencida', variant: 'danger', Icon: AlertTriangle },
    pagada: { label: 'Pagada', variant: 'success', Icon: CheckCircle2 },
    anulada: { label: 'Anulada', variant: 'default', Icon: XCircle },
} as const;

export function InvoiceStatusBadge({ status }: { status: string }) {
    const cfg = invoice[status as keyof typeof invoice] ?? invoice.pendiente;
    return (
        <Badge variant={cfg.variant}>
            <cfg.Icon className="size-3" />
            {cfg.label}
        </Badge>
    );
}

const people = {
    active: { label: 'Activo', variant: 'success', Icon: CheckCircle2 },
    pending: { label: 'Invitación pendiente', variant: 'warning', Icon: Mail },
    inactive: { label: 'Dado de baja', variant: 'default', Icon: UserX },
} as const;

export function UserStateBadge({ state }: { state: string }) {
    const cfg = people[state as keyof typeof people] ?? people.active;
    return (
        <Badge variant={cfg.variant}>
            <cfg.Icon className="size-3" />
            {cfg.label}
        </Badge>
    );
}
