import type { ReactNode } from 'react';
import { LaiaLogo } from '@/components/brand/LaiaLogo';

export default function GuestLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    return (
        <div className="grid min-h-screen bg-background lg:grid-cols-[1.1fr_1fr]">
            <title>{title}</title>

            {/* Panel de marca (solo escritorio) */}
            <aside className="brand-gradient relative hidden flex-col justify-between overflow-hidden p-12 text-white lg:flex">
                <LaiaLogo tone="light" className="h-28" />
                <div className="relative z-10 max-w-md">
                    <p className="font-display text-4xl font-extrabold leading-[1.1] tracking-tight">
                        Ideas que se convierten en <span className="text-peach">marcas</span>.
                    </p>
                    <p className="mt-5 text-base leading-relaxed text-white/70">
                        El centro de operaciones de Laia Estudio Creativo Digital: clientes, contenido, presupuestos y cobros en un solo lugar.
                    </p>
                </div>
                <p className="text-xs text-white/50">© {new Date().getFullYear()} Laia Estudio Creativo Digital</p>
                <div className="pointer-events-none absolute -bottom-24 -right-24 size-80 rounded-full border border-white/10" aria-hidden="true" />
                <div className="pointer-events-none absolute -bottom-10 -right-10 size-52 rounded-full border border-white/10" aria-hidden="true" />
            </aside>

            <main className="flex items-center justify-center px-5 py-10">
                <div className="w-full max-w-sm">
                    <LaiaLogo className="mx-auto mb-8 h-24 lg:hidden" />
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{title}</h1>
                    {subtitle && <p className="mb-8 mt-2 text-muted-foreground">{subtitle}</p>}
                    {!subtitle && <div className="mb-8" />}
                    {children}
                </div>
            </main>
        </div>
    );
}
