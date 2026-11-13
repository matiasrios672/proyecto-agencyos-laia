import type { InputHTMLAttributes, LabelHTMLAttributes, Ref } from 'react';
import { cn } from '@/lib/utils';

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement> & { ref?: Ref<HTMLInputElement> }) {
    return (
        <input
            className={cn(
                'flex h-9 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

export function Label({ className, ...props }: LabelHTMLAttributes<HTMLLabelElement>) {
    return <label className={cn('text-sm font-medium leading-none', className)} {...props} />;
}

export function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return (
        <p role="alert" className="text-xs text-danger">
            {message}
        </p>
    );
}
