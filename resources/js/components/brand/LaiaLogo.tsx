import { cn } from '@/lib/utils';

/**
 * Logo oficial de Laia Estudio Creativo Digital.
 * `light`: versión blanca para fondos oscuros (barra lateral, portadas). `dark`: versión a color para fondos claros.
 */
export function LaiaLogo({ className, tone = 'dark' }: { className?: string; tone?: 'dark' | 'light' }) {
    return (
        <img
            src={tone === 'light' ? '/brand/logo-white.png' : '/brand/logo-color.png'}
            alt="Laia Estudio Creativo Digital"
            width={981}
            height={617}
            draggable={false}
            // self-start + shrink-0: dentro de un flex en columna el logo no se estira y conserva su proporción.
            className={cn('h-14 w-auto max-w-none shrink-0 select-none self-start object-contain', className)}
        />
    );
}

/** Isotipo (los dos círculos orbitando) para espacios pequeños. */
export function LaiaIso({ className, tone = 'dark' }: { className?: string; tone?: 'dark' | 'light' }) {
    return (
        <img
            src={tone === 'light' ? '/brand/isotipo-blanco.png' : '/brand/isotipo.png'}
            alt=""
            aria-hidden="true"
            width={345}
            height={319}
            draggable={false}
            className={cn('size-8 select-none object-contain', className)}
        />
    );
}
