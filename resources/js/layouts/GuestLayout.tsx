import type { ReactNode } from 'react';
import { LaiaLogo } from '@/components/brand/LaiaLogo';

export default function GuestLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    return (
        <div className="flex min-h-dvh flex-col bg-navy lg:grid lg:grid-cols-[1.1fr_1fr] lg:bg-background">
            <title>{title}</title>

            {/* Cabecera de marca (móvil) */}
            <header className="brand-gradient relative overflow-hidden px-6 pb-14 pt-[max(2.5rem,env(safe-area-inset-top))] text-white lg:hidden">
                <LaiaLogo tone="light" className="h-16" />
                <p className="relative z-10 mt-6 max-w-[16rem] font-display text-2xl font-extrabold leading-tight tracking-tight">
                    Ideas que se convierten en <span className="text-peach">marcas</span>.
                </p>
                <div className="pointer-events-none absolute -right-16 -top-16 size-56 rounded-full border border-white/10" aria-hidden="true" />
                <div className="pointer-events-none absolute -right-4 -top-4 size-32 rounded-full border border-white/10" aria-hidden="true" />
            </header>

            {/* Panel de marca (escritorio) */}
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

            {/* Formulario: en móvil es una hoja que sube sobre la cabecera */}
            <main className="relative z-10 -mt-8 flex flex-1 flex-col rounded-t-[2rem] bg-background px-6 pb-[max(2rem,env(safe-area-inset-bottom))] pt-9 shadow-[0_-8px_30px_rgb(0_0_0/0.15)] lg:mt-0 lg:items-center lg:justify-center lg:rounded-none lg:px-5 lg:py-10 lg:shadow-none">
                <div className="mx-auto w-full max-w-sm">
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{title}</h1>
                    {subtitle && <p className="mb-7 mt-1.5 text-muted-foreground lg:mb-8 lg:mt-2">{subtitle}</p>}
                    {!subtitle && <div className="mb-7 lg:mb-8" />}
                    {children}
                </div>
            </main>
        </div>
    );
}
