import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

const escapeRegExp = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Cuerpo de un mensaje como TEXTO (React lo escapa): nunca se inserta HTML.
 * Resalta las @menciones que el servidor validó (solo esas, por nombre exacto).
 */
export function MessageText({ body, mentions, mine }: { body: string; mentions: { id: number; name: string }[]; mine?: boolean }) {
    if (mentions.length === 0) return <>{body}</>;

    // Los nombres más largos primero, para que "Ana María" no se corte en "Ana".
    const names = [...new Set(mentions.map((m) => m.name))].sort((a, b) => b.length - a.length).map(escapeRegExp);
    const parts = body.split(new RegExp(`(@(?:${names.join('|')}))`, 'g'));

    return (
        <>
            {parts.map((part, i): ReactNode =>
                i % 2 === 1 ? (
                    <span key={i} className={cn('rounded px-1 font-medium', mine ? 'bg-white/20 text-white' : 'bg-accent text-accent-foreground')}>
                        {part}
                    </span>
                ) : (
                    part
                ),
            )}
        </>
    );
}
