import { cn } from '@/lib/utils';

const gradients = [
    'from-violet-500 to-fuchsia-500',
    'from-indigo-500 to-sky-500',
    'from-rose-500 to-orange-400',
    'from-emerald-500 to-teal-400',
    'from-amber-500 to-pink-500',
    'from-blue-500 to-violet-500',
];

function hash(s: string) {
    let h = 0;
    for (const c of s) h = (h * 31 + c.charCodeAt(0)) | 0;
    return Math.abs(h);
}

export function initials(name: string) {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0])
        .join('')
        .toUpperCase();
}

/** Avatar con iniciales y un degradé estable por persona. */
export function Avatar({ name, className }: { name: string; className?: string }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'grid size-8 shrink-0 place-items-center rounded-full bg-gradient-to-br text-xs font-semibold text-white',
                gradients[hash(name) % gradients.length],
                className,
            )}
        >
            {initials(name)}
        </span>
    );
}
