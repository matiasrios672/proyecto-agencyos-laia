import { Popover as Primitive } from 'radix-ui';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const Popover = Primitive.Root;
export const PopoverTrigger = Primitive.Trigger;
export const PopoverClose = Primitive.Close;

export function PopoverContent({ className, sideOffset = 8, align = 'end', ...props }: ComponentProps<typeof Primitive.Content>) {
    return (
        <Primitive.Portal>
            <Primitive.Content
                align={align}
                sideOffset={sideOffset}
                className={cn(
                    'z-50 w-[min(24rem,calc(100vw-1.5rem))] overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-[var(--shadow-pop)] outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-1',
                    className,
                )}
                {...props}
            />
        </Primitive.Portal>
    );
}
