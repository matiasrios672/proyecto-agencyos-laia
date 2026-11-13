import { DropdownMenu as Primitive } from 'radix-ui';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const DropdownMenu = Primitive.Root;
export const DropdownMenuTrigger = Primitive.Trigger;
export const DropdownMenuRadioGroup = Primitive.RadioGroup;

export function DropdownMenuContent({ className, sideOffset = 8, ...props }: ComponentProps<typeof Primitive.Content>) {
    return (
        <Primitive.Portal>
            <Primitive.Content
                sideOffset={sideOffset}
                className={cn(
                    'z-50 min-w-60 overflow-hidden rounded-xl border bg-popover p-1.5 text-popover-foreground shadow-[var(--shadow-pop)] data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-1',
                    className,
                )}
                {...props}
            />
        </Primitive.Portal>
    );
}

const itemClass =
    'relative flex cursor-default select-none items-center gap-2.5 rounded-md px-2.5 py-2 text-sm outline-none transition-colors data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[highlighted]:bg-muted [&_svg]:size-4 [&_svg]:shrink-0 [&_svg]:text-muted-foreground';

export function DropdownMenuItem({ className, ...props }: ComponentProps<typeof Primitive.Item>) {
    return <Primitive.Item className={cn(itemClass, className)} {...props} />;
}

export function DropdownMenuRadioItem({ className, children, ...props }: ComponentProps<typeof Primitive.RadioItem>) {
    return (
        <Primitive.RadioItem className={cn(itemClass, 'data-[state=checked]:font-medium data-[state=checked]:text-primary', className)} {...props}>
            {children}
            <Primitive.ItemIndicator className="ml-auto size-1.5 rounded-full bg-primary" />
        </Primitive.RadioItem>
    );
}

export function DropdownMenuLabel({ className, ...props }: ComponentProps<typeof Primitive.Label>) {
    return <Primitive.Label className={cn('px-2.5 py-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground', className)} {...props} />;
}

export function DropdownMenuSeparator({ className, ...props }: ComponentProps<typeof Primitive.Separator>) {
    return <Primitive.Separator className={cn('-mx-1.5 my-1.5 h-px bg-border', className)} {...props} />;
}
