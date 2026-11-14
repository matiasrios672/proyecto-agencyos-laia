import { Loader2, Send } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { MAX_MESSAGE_LENGTH, type ChatUser } from './types';
import { apiError } from './useMessaging';

interface Props {
    mentionables: ChatUser[];
    onSubmit: (body: string, mentions: number[]) => Promise<void>;
    placeholder?: string;
    initialValue?: string;
    initialMentions?: { id: number; name: string }[];
    autoFocus?: boolean;
    submitLabel?: string;
    onCancel?: () => void;
    disabled?: boolean;
}

/** "Camila Sosa" → "camila sosa" (sin tildes), para buscar sin importar acentos ni mayúsculas. */
const fold = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

/** ¿Hay una @mención a medio escribir justo antes del cursor? */
function detectMention(value: string, caret: number): { query: string; from: number } | null {
    const match = /(^|[\s(])@([\p{L}\p{N}._-]{0,30})$/u.exec(value.slice(0, caret));
    return match ? { query: match[2], from: caret - match[2].length - 1 } : null;
}

/**
 * Compositor con @menciones accesibles (combobox + listbox con flechas, Enter/Tab y Escape).
 * Enter envía, Shift+Enter hace un salto de línea. Solo se proponen las personas con acceso a la conversación.
 */
export function Composer({ mentionables, onSubmit, placeholder = 'Escribí un mensaje…', initialValue = '', initialMentions = [], autoFocus, submitLabel = 'Enviar', onCancel, disabled }: Props) {
    const [text, setText] = useState(initialValue);
    const [picked, setPicked] = useState<Map<string, number>>(() => new Map(initialMentions.map((m) => [m.name, m.id])));
    const [trigger, setTrigger] = useState<{ query: string; from: number } | null>(null);
    const [active, setActive] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const ref = useRef<HTMLTextAreaElement>(null);
    const listId = useId();

    const suggestions = useMemo(() => {
        if (!trigger) return [];
        const q = fold(trigger.query);
        return mentionables
            .filter((u) => q === '' || fold(u.name).split(/\s+/).some((w) => w.startsWith(q)) || fold(u.name).includes(q))
            .slice(0, 6);
    }, [trigger, mentionables]);
    const open = suggestions.length > 0;

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
    }, [text]);

    useEffect(() => {
        if (autoFocus) ref.current?.focus();
    }, [autoFocus]);

    const pick = (user: ChatUser) => {
        const el = ref.current;
        if (!el || !trigger) return;
        const before = text.slice(0, trigger.from);
        const after = text.slice(el.selectionStart);
        const inserted = `@${user.name} `;
        setText(before + inserted + after);
        setPicked((p) => new Map(p).set(user.name, user.id));
        setTrigger(null);
        requestAnimationFrame(() => {
            el.focus();
            const pos = (before + inserted).length;
            el.setSelectionRange(pos, pos);
        });
    };

    const submit = async () => {
        const body = text.trim();
        if (!body || busy || disabled) return;
        // Solo cuentan las menciones que siguen escritas en el texto (si borró "@Ana", no se la menciona).
        const mentions = [...picked].filter(([name]) => body.includes(`@${name}`)).map(([, id]) => id);
        setBusy(true);
        setError(null);
        try {
            await onSubmit(body, mentions);
            setText('');
            setPicked(new Map());
            ref.current?.focus();
        } catch (e) {
            setError(apiError(e, 'No se pudo enviar el mensaje. Tu texto sigue acá: probá de nuevo.'));
        } finally {
            setBusy(false);
        }
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.nativeEvent.isComposing) return;   // no cortar la composición de acentos / IME

        if (open) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : -1) + suggestions.length) % suggestions.length);
                return;
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                pick(suggestions[active] ?? suggestions[0]);
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                setTrigger(null);
                return;
            }
        }

        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void submit();
        } else if (e.key === 'Escape' && onCancel) {
            onCancel();
        }
    };

    const nearLimit = text.length > MAX_MESSAGE_LENGTH - 500;

    return (
        <form
            className="relative"
            onSubmit={(e) => {
                e.preventDefault();
                void submit();
            }}
        >
            {open && (
                <ul id={listId} role="listbox" aria-label="Personas a mencionar" className="absolute bottom-full left-0 z-20 mb-2 w-72 max-w-full overflow-hidden rounded-xl border bg-popover p-1 shadow-[var(--shadow-pop)]">
                    {suggestions.map((u, i) => (
                        <li
                            key={u.id}
                            id={`${listId}-${u.id}`}
                            role="option"
                            aria-selected={i === active}
                            // mousedown (no click) para no perder el foco del textarea antes de elegir
                            onMouseDown={(e) => {
                                e.preventDefault();
                                pick(u);
                            }}
                            onMouseEnter={() => setActive(i)}
                            className={cn('flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm', i === active && 'bg-muted')}
                        >
                            <Avatar name={u.name} className="size-6 text-[10px]" />
                            <span className="min-w-0">
                                <span className="block truncate font-medium">{u.name}</span>
                                {u.job_title && <span className="block truncate text-xs text-muted-foreground">{u.job_title}</span>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <div className={cn('flex items-end gap-2', !onCancel && 'rounded-3xl border border-input bg-card p-1.5 pl-4 shadow-xs transition-shadow focus-within:ring-2 focus-within:ring-ring')}>
                <textarea
                    ref={ref}
                    rows={1}
                    value={text}
                    maxLength={MAX_MESSAGE_LENGTH}
                    disabled={disabled}
                    placeholder={placeholder}
                    aria-label="Escribir mensaje"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded={open}
                    aria-controls={open ? listId : undefined}
                    aria-activedescendant={open && suggestions[active] ? `${listId}-${suggestions[active].id}` : undefined}
                    onChange={(e) => {
                        setText(e.target.value);
                        setTrigger(detectMention(e.target.value, e.target.selectionStart));
                        setActive(0);
                    }}
                    onSelect={(e) => setTrigger(detectMention(e.currentTarget.value, e.currentTarget.selectionStart))}
                    onKeyDown={onKeyDown}
                    className={cn(
                        'max-h-40 min-h-9 flex-1 resize-none bg-transparent py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none disabled:opacity-50',
                        onCancel && 'min-h-10 rounded-lg border border-input bg-card px-3 shadow-xs transition-colors focus-visible:ring-2 focus-visible:ring-ring',
                    )}
                />
                {onCancel && (
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancelar
                    </Button>
                )}
                <Button type="submit" disabled={busy || disabled || !text.trim()} aria-label={submitLabel} className={cn(!onCancel && 'size-9 shrink-0 rounded-full px-0')}>
                    {busy ? <Loader2 className="animate-spin" /> : <Send />}
                    {onCancel && <span>{submitLabel}</span>}
                </Button>
            </div>

            <div className="mt-1.5 flex items-start justify-between gap-3 px-3 text-xs text-muted-foreground">
                <p className="hidden sm:block">
                    <kbd className="font-sans">Enter</kbd> envía · <kbd className="font-sans">Shift + Enter</kbd> salto de línea · <kbd className="font-sans">@</kbd> menciona
                </p>
                {nearLimit && <span className={cn('tabular-nums', text.length >= MAX_MESSAGE_LENGTH && 'font-medium text-danger')}>{text.length}/{MAX_MESSAGE_LENGTH}</span>}
            </div>
            {error && (
                <p role="alert" className="mt-1 text-xs text-danger">
                    {error}
                </p>
            )}
        </form>
    );
}
