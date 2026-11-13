import { usePage } from '@inertiajs/react';
import { CheckCircle2, TriangleAlert } from 'lucide-react';
import type { SharedProps } from '@/types';

/** Aviso en pantalla para páginas de invitados (no hay barra de avisos): muestra el mensaje flash de la sesión. */
export function FlashNotice() {
    const { flash } = usePage<SharedProps>().props;

    if (flash?.error) return <Notice tone="error">{flash.error}</Notice>;
    if (flash?.success) return <Notice tone="success">{flash.success}</Notice>;

    return null;
}

export function Notice({ tone, children }: { tone: 'success' | 'error'; children: React.ReactNode }) {
    const Icon = tone === 'success' ? CheckCircle2 : TriangleAlert;

    return (
        <div
            role={tone === 'error' ? 'alert' : 'status'}
            className={
                tone === 'success'
                    ? 'mb-5 flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/10 px-3.5 py-3 text-sm'
                    : 'mb-5 flex items-start gap-2.5 rounded-lg border border-danger/30 bg-danger/10 px-3.5 py-3 text-sm'
            }
        >
            <Icon className={tone === 'success' ? 'mt-0.5 size-4 shrink-0 text-success' : 'mt-0.5 size-4 shrink-0 text-danger'} />
            <p>{children}</p>
        </div>
    );
}
