import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Building2,
    ChevronDown,
    FileStack,
    FileText,
    HandCoins,
    Landmark,
    LayoutDashboard,
    LineChart,
    ListChecks,
    Megaphone,
    Menu,
    MessagesSquare,
    Package,
    PanelLeftClose,
    PanelLeftOpen,
    Plug,
    Receipt,
    ShieldCheck,
    SlidersHorizontal,
    UsersRound,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Toaster, toast } from 'sonner';
import { LaiaIso, LaiaLogo } from '@/components/brand/LaiaLogo';
import { ChatMenu, NotificationsMenu, PowerButton, ProfileMenu } from '@/components/layout/TopbarActions';
import { Button } from '@/components/ui/button';
import { useTheme } from '@/lib/theme';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

interface NavItem {
    label: string;
    href: string;
    icon: LucideIcon;
    permission?: string;
    /** Alcanza con tener cualquiera de estos permisos. */
    anyOf?: string[];
}

interface NavGroup {
    id: string;
    label: string;
    items: NavItem[];
    /** Solo visible para el superadministrador. */
    sysadminOnly?: boolean;
}

const groups: NavGroup[] = [
    {
        id: 'work',
        label: 'Trabajo',
        items: [
            { label: 'Inicio', href: '/', icon: LayoutDashboard },
            { label: 'Mensajes', href: '/mensajes', icon: MessagesSquare, permission: 'messages.use' },
            { label: 'Clientes', href: '/clientes', icon: Building2, permission: 'clients.view' },
            { label: 'Contenido', href: '/contenido', icon: FileStack, permission: 'content.view' },
            { label: 'Mis tareas', href: '/mis-tareas', icon: ListChecks, permission: 'content.view' },
        ],
    },
    {
        id: 'sales',
        label: 'Ventas y equipo',
        items: [
            { label: 'Presupuestos', href: '/presupuestos', icon: FileText, permission: 'quotes.view' },
            { label: 'Catálogo', href: '/catalogo', icon: Package, permission: 'quotes.view' },
            { label: 'Equipo', href: '/equipo', icon: UsersRound, permission: 'employees.manage' },
        ],
    },
    {
        id: 'finance',
        label: 'Finanzas',
        items: [
            { label: 'Resumen', href: '/finanzas', icon: LineChart, permission: 'finance.view' },
            { label: 'Cobros', href: '/cobros', icon: Receipt, permission: 'finance.view' },
            { label: 'Liquidaciones', href: '/liquidaciones', icon: HandCoins, anyOf: ['payouts.view_own', 'finance.view'] },
            { label: 'Egresos', href: '/egresos', icon: Landmark, permission: 'finance.view' },
            { label: 'Comunicados', href: '/comunicados', icon: Megaphone, permission: 'finance.view' },
            { label: 'LaiaTime', href: '/admin/laiatime', icon: Plug, permission: 'finance.view' },
        ],
    },
    {
        id: 'admin',
        label: 'Administración',
        sysadminOnly: true,
        items: [
            { label: 'Configuración', href: '/admin/configuracion', icon: SlidersHorizontal },
            { label: 'Permisos', href: '/admin/permisos', icon: ShieldCheck },
            { label: 'Sistema', href: '/admin/sistema', icon: Activity },
        ],
    },
];

/** Estado de interfaz recordado en el navegador (si no hay almacenamiento, vive solo en memoria). */
function usePersisted<T>(key: string, initial: T): [T, (v: T) => void] {
    const [value, setValue] = useState<T>(() => {
        try {
            const raw = localStorage.getItem(key);
            return raw === null ? initial : (JSON.parse(raw) as T);
        } catch {
            return initial;
        }
    });
    const set = (v: T) => {
        setValue(v);
        try {
            localStorage.setItem(key, JSON.stringify(v));
        } catch {
            /* sin almacenamiento */
        }
    };
    return [value, set];
}

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const page = usePage<SharedProps>();
    const { auth, flash } = page.props;
    const current = page.url.split('?')[0];
    const [open, setOpen] = useState(false);
    const [theme, setTheme] = useTheme();
    const [rail, setRail] = usePersisted<boolean>('laia.sidebar.rail', false);
    const [closedGroups, setClosedGroups] = usePersisted<string[]>('laia.sidebar.closed', []);

    useEffect(() => setOpen(false), [current]);
    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash?.success, flash?.error]);

    const user = auth!.user;
    const can = (p?: string) => !p || user.is_sysadmin || auth!.permissions.includes(p);
    const allowed = (i: NavItem) => (i.anyOf ? i.anyOf.some((p) => can(p)) : can(i.permission));

    const isActive = (href: string) => (href === '/' ? current === '/' : current.startsWith(href));
    const toggleGroup = (id: string) => setClosedGroups(closedGroups.includes(id) ? closedGroups.filter((g) => g !== id) : [...closedGroups, id]);

    const renderItem = (item: NavItem, compact: boolean) => {
        const active = isActive(item.href);
        return (
            <Link
                key={item.href}
                href={item.href}
                aria-current={active ? 'page' : undefined}
                aria-label={compact ? item.label : undefined}
                className={cn(
                    'group/item relative flex items-center rounded-lg text-[13px] font-medium text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-sidebar-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                    compact ? 'justify-center py-2' : 'gap-2.5 px-2.5 py-1.5',
                    active && 'bg-sidebar-active text-white hover:bg-sidebar-active hover:text-white',
                )}
            >
                {active && <span className="absolute -left-3 top-1/2 h-4 w-1 -translate-y-1/2 rounded-r-full bg-peach" />}
                <item.icon className={cn('size-[17px] shrink-0 transition-colors', active ? 'text-peach' : 'group-hover/item:text-sidebar-foreground')} />
                {!compact && item.label}
                {compact && (
                    <span
                        role="tooltip"
                        className="pointer-events-none absolute left-full z-50 ml-3 whitespace-nowrap rounded-md bg-foreground px-2 py-1 text-xs font-medium text-background opacity-0 shadow-lg transition-opacity group-hover/item:opacity-100 group-focus-visible/item:opacity-100"
                    >
                        {item.label}
                    </span>
                )}
            </Link>
        );
    };

    /** `compact` = riel de íconos (solo escritorio); el menú móvil siempre es completo. */
    const renderSidebar = (compact: boolean) => (
        <div className="flex h-full flex-col gap-3 px-3 pb-3 pt-4">
            <div className="flex items-center justify-center px-1">
                {compact ? <LaiaIso tone="light" className="size-8" /> : <LaiaLogo tone="light" className="h-12" />}
            </div>

            <div className={cn('flex min-h-0 flex-1 flex-col gap-1', compact ? 'overflow-visible' : 'overflow-y-auto')}>
                {groups
                    .filter((g) => !g.sysadminOnly || user.is_sysadmin)
                    .map((g) => ({ ...g, visible: g.items.filter(allowed) }))
                    .filter((g) => g.visible.length > 0)
                    .map((g) => {
                        const collapsed = !compact && closedGroups.includes(g.id);
                        return (
                            <nav key={g.id} aria-label={g.label} className="flex flex-col gap-0.5">
                                {compact ? (
                                    <span className="mx-2 my-1.5 h-px bg-sidebar-border" aria-hidden="true" />
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => toggleGroup(g.id)}
                                        aria-expanded={!collapsed}
                                        className="flex items-center justify-between rounded-md px-2.5 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-sidebar-muted/70 transition-colors hover:text-sidebar-foreground"
                                    >
                                        {g.label}
                                        <ChevronDown className={cn('size-3 transition-transform', collapsed && '-rotate-90')} />
                                    </button>
                                )}
                                {!collapsed && g.visible.map((item) => renderItem(item, compact))}
                            </nav>
                        );
                    })}
            </div>

            <button
                type="button"
                onClick={() => setRail(!rail)}
                aria-label={rail ? 'Expandir barra lateral' : 'Contraer barra lateral'}
                className={cn(
                    'hidden items-center rounded-lg px-2.5 py-2 text-xs font-medium text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-sidebar-foreground md:flex',
                    compact ? 'justify-center' : 'gap-2.5',
                )}
            >
                {compact ? <PanelLeftOpen className="size-[17px]" /> : <PanelLeftClose className="size-[17px]" />}
                {!compact && 'Contraer'}
            </button>
        </div>
    );

    return (
        <div className="min-h-screen bg-background">
            <title>{title}</title>
            <aside className={cn('fixed inset-y-0 left-0 z-30 hidden bg-sidebar transition-[width] duration-200 md:block', rail ? 'w-16' : 'w-56')}>
                {renderSidebar(rail)}
            </aside>

            {open && (
                <div className="fixed inset-0 z-40 md:hidden">
                    <div className="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 w-64 bg-sidebar shadow-2xl">{renderSidebar(false)}</aside>
                </div>
            )}

            <div className={cn('transition-[padding] duration-200', rail ? 'md:pl-16' : 'md:pl-56')}>
                <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b bg-background/80 px-4 backdrop-blur-md md:px-8">
                    <Button variant="ghost" size="icon" className="md:hidden" aria-label={open ? 'Cerrar menú' : 'Abrir menú'} onClick={() => setOpen((o) => !o)}>
                        {open ? <X /> : <Menu />}
                    </Button>
                    <h1 className="truncate font-display text-base font-bold tracking-tight">{title}</h1>

                    <div className="ml-auto flex items-center gap-1">
                        <ChatMenu />
                        <NotificationsMenu />
                        <span className="mx-1.5 hidden h-6 w-px bg-border sm:block" aria-hidden="true" />
                        <ProfileMenu theme={theme} onTheme={setTheme} />
                        <PowerButton />
                    </div>
                </header>
                <main className="mx-auto w-full max-w-6xl px-4 py-7 md:px-8">{children}</main>
            </div>

            <Toaster
                position="top-right"
                offset={{ top: 76, right: 20 }}
                mobileOffset={{ top: 72, right: 12, left: 12 }}
                theme={theme}
                richColors
                closeButton
                toastOptions={{
                    classNames: {
                        toast: '!rounded-xl !border !shadow-[var(--shadow-pop)] !font-sans',
                        title: '!font-semibold',
                    },
                }}
            />
        </div>
    );
}
