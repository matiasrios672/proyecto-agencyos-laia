import { AlertTriangle, CheckCircle2, CircleHelp, FlaskConical, Link2Off, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

const states = {
    active: { label: 'Activa', variant: 'success', Icon: CheckCircle2 },
    trial: { label: 'Prueba', variant: 'primary', Icon: FlaskConical },
    past_due: { label: 'En mora', variant: 'danger', Icon: AlertTriangle },
    canceled: { label: 'Cancelada', variant: 'default', Icon: XCircle },
    missing: { label: 'No existe en LaiaTime', variant: 'warning', Icon: Link2Off },
    unknown: { label: 'Desconocido', variant: 'default', Icon: CircleHelp },
} as const;

/** Estado de la suscripción en LaiaTime: siempre ícono + texto. */
export function LaiaTimeStatusBadge({ status }: { status: string | null }) {
    const cfg = states[(status ?? 'unknown') as keyof typeof states] ?? states.unknown;
    return (
        <Badge variant={cfg.variant}>
            <cfg.Icon className="size-3" />
            {cfg.label}
        </Badge>
    );
}

export interface LaiaTimeClient {
    id: number;
    name: string;
    instance_id: string;
    account_status: string;
    laiatime_status: string | null;
    synced_at: string | null;
    suspended_at: string | null;
    suspended_by_system: boolean;
    override_until: string | null;
    last_error: string | null;
    overdue_days: number;
    in_grace: boolean;
    out_of_grace: boolean;
}

export interface LaiaTimeError {
    kind: 'unconfigured' | 'credentials' | 'unavailable' | 'rate_limited' | 'error';
    message: string;
}

export interface LaiaTimePolicy {
    auto_suspend: boolean;
    grace_days: number;
}

export function formatWhen(iso: string | null) {
    return iso ? new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' }) : '—';
}
