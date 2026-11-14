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
    LayoutGrid,
    LineChart,
    ListChecks,
    Megaphone,
    Mail,
    MessagesSquare,
    Package,
    Plug,
    ClipboardCheck,
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

/** Orden de preferencia para la barra inferior del celular (se muestran hasta 4 con permiso). */
const tabHrefs = ['/', '/mensajes', '/clientes', '/contenido', '/mis-tareas', '/presupuestos', '/finanzas'];

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
            { label: 'Cobranzas', href: '/cobranzas', icon: ClipboardCheck, permission: 'finance.view' },
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
            { label: 'Plantillas de correo', href: '/admin/correos', icon: Mail },
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

    const visibleGroups = groups
        .filter((g) => !g.sysadminOnly || user.is_sysadmin)
        .map((g) => ({ ...g, visible: g.items.filter(allowed) }))
        .filter((g) => g.visible.length > 0);

    // Celular: barra inferior con los accesos principales a los que la persona tiene permiso + "Más".
    const allVisible = visibleGroups.flatMap((g) => g.visible);
    const tabs = tabHrefs
        .map((href) => allVisible.find((i) => i.href === href))
        .filter((i): i is NavItem => Boolean(i))
        .slice(0, 4);
    const tabActive = tabs.some((t) => isActive(t.href));

    const renderSidebar = () => (
        <div className="flex h-full flex-col gap-4 px-4 pb-4 pt-5">
            <div className="flex items-center justify-center px-1">
                <LaiaLogo tone="light" className="h-14" />
            </div>

            <div className="sidebar-scroll flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto">
                {visibleGroups.map((g) => {
                    const collapsed = closedGroups.includes(g.id);
                    return (
                        <nav key={g.id} aria-label={g.label} className="flex flex-col gap-0.5">
                            <button
                                type="button"
                                onClick={() => toggleGroup(g.id)}
                                aria-expanded={!collapsed}
                                className="flex items-center justify-between rounded-md px-3 pb-1.5 pt-3 text-[11px] font-semibold uppercase tracking-[0.14em] text-sidebar-muted/70 transition-colors hover:text-sidebar-foreground"
                            >
                                {g.label}
                                <ChevronDown className={cn('size-3.5 transition-transform', collapsed && '-rotate-90')} />
                            </button>
                            {!collapsed &&
                                g.visible.map((item) => {
                                    const active = isActive(item.href);
                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            aria-current={active ? 'page' : undefined}
                                            className={cn(
                                                'relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-sidebar-muted transition-colors hover:bg-sidebar-hover hover:text-sidebar-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                                                active && 'bg-sidebar-active text-white hover:bg-sidebar-active hover:text-white',
                                            )}
                                        >
                                            {active && <span className="absolute -left-4 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-peach" />}
                                            <item.icon className={cn('size-5 shrink-0', active && 'text-peach')} />
                                            {item.label}
                                        </Link>
                                    );
                                })}
                        </nav>
                    );
                })}
            </div>
        </div>
    );

    return (
        <div className="min-h-screen bg-background">
            <title>{title}</title>
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 bg-sidebar md:block">{renderSidebar()}</aside>

            {/* Celular: hoja "Más" con todas las secciones agrupadas */}
            {open && (
                <div className="fixed inset-0 z-40 md:hidden">
                    <div className="absolute inset-0 bg-black/50 backdrop-blur-[2px]" onClick={() => setOpen(false)} />
                    <section
                        role="dialog"
                        aria-label="Todas las secciones"
                        className="sidebar-scroll absolute inset-x-0 bottom-0 max-h-[85dvh] overflow-y-auto rounded-t-3xl bg-sidebar px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-3 shadow-2xl"
                    >
                        <div className="mx-auto mb-3 h-1.5 w-10 rounded-full bg-sidebar-border" aria-hidden="true" />
                        <div className="mb-2 flex items-center justify-between">
                            <LaiaLogo tone="light" className="h-9" />
                            <Button variant="ghost" size="icon" className="text-sidebar-foreground hover:bg-sidebar-hover" aria-label="Cerrar" onClick={() => setOpen(false)}>
                                <X />
                            </Button>
                        </div>
                        {visibleGroups.map((g) => (
                            <nav key={g.id} aria-label={g.label} className="mt-4">
                                <p className="mb-2 px-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-sidebar-muted/70">{g.label}</p>
                                <div className="grid grid-cols-3 gap-2">
                                    {g.visible.map((item) => {
                                        const active = isActive(item.href);
                                        return (
                                            <Link
                                                key={item.href}
                                                href={item.href}
                                                aria-current={active ? 'page' : undefined}
                                                className={cn(
                                                    'flex flex-col items-center gap-1.5 rounded-xl bg-sidebar-hover/60 px-2 py-3 text-center text-xs font-medium text-sidebar-foreground transition-colors active:bg-sidebar-hover',
                                                    active && 'bg-sidebar-active text-white',
                                                )}
                                            >
                                                <item.icon className={cn('size-6', active && 'text-peach')} />
                                                <span className="leading-tight">{item.label}</span>
                                            </Link>
                                        );
                                    })}
                                </div>
                            </nav>
                        ))}
                    </section>
                </div>
            )}

            <div className="md:pl-64">
                <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b bg-background/80 px-4 backdrop-blur-md md:px-8">
                    <LaiaIso className="size-8 shrink-0 md:hidden" />
                    <h1 className="truncate font-display text-base font-bold tracking-tight">{title}</h1>

                    <div className="ml-auto flex items-center gap-1">
                        <ChatMenu />
                        <NotificationsMenu />
                        <span className="mx-1.5 hidden h-6 w-px bg-border sm:block" aria-hidden="true" />
                        <ProfileMenu />
                        <PowerButton />
                    </div>
                </header>
                <main className="mx-auto w-full max-w-6xl px-4 py-7 pb-28 md:px-8 md:pb-7">{children}</main>
            </div>

            {/* Celular: barra inferior */}
            <nav aria-label="Navegación principal" className="fixed inset-x-0 bottom-0 z-30 border-t border-sidebar-border bg-sidebar pb-[env(safe-area-inset-bottom)] md:hidden">
                <ul className="flex items-stretch justify-around px-1">
                    {tabs.map((item) => {
                        const active = isActive(item.href);
                        return (
                            <li key={item.href} className="flex-1">
                                <Link
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={cn('flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium text-sidebar-muted', active && 'text-white')}
                                >
                                    <item.icon className={cn('size-6', active && 'text-peach')} />
                                    {item.label}
                                </Link>
                            </li>
                        );
                    })}
                    <li className="flex-1">
                        <button
                            type="button"
                            onClick={() => setOpen(true)}
                            aria-label="Más secciones"
                            className={cn('flex w-full flex-col items-center gap-0.5 py-2 text-[11px] font-medium text-sidebar-muted', !tabActive && 'text-white')}
                        >
                            <LayoutGrid className={cn('size-6', !tabActive && 'text-peach')} />
                            Más
                        </button>
                    </li>
                </ul>
            </nav>

            <Toaster
                position="top-right"
                offset={{ top: 76, right: 20 }}
                mobileOffset={{ top: 72, right: 12, left: 12 }}
                theme="light"
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
