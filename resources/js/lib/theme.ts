import { useEffect, useState } from 'react';

export type Theme = 'light' | 'dark' | 'system';

export function getStoredTheme(): Theme {
    try {
        const t = localStorage.getItem('theme');
        return t === 'light' || t === 'dark' ? t : 'system';
    } catch {
        return 'system';
    }
}

export function applyTheme(theme: Theme) {
    const dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    try {
        localStorage.setItem('theme', theme);
    } catch {
        /* sin storage: el tema solo dura la sesión */
    }
}

/** Tema con persistencia; en modo "sistema" sigue los cambios del sistema operativo. */
export function useTheme() {
    const [theme, setThemeState] = useState<Theme>(getStoredTheme);

    useEffect(() => {
        if (theme !== 'system') return;
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => applyTheme('system');
        mq.addEventListener('change', onChange);
        return () => mq.removeEventListener('change', onChange);
    }, [theme]);

    const setTheme = (t: Theme) => {
        setThemeState(t);
        applyTheme(t);
    };

    return [theme, setTheme] as const;
}

const rtf = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });

/** "hace 5 minutos", "ayer"… */
export function timeAgo(iso: string): string {
    const diff = (new Date(iso).getTime() - Date.now()) / 1000;
    const steps: [number, Intl.RelativeTimeFormatUnit][] = [[60, 'second'], [3600, 'minute'], [86400, 'hour'], [604800, 'day']];
    let unitSeconds = 1;
    for (const [limit, unit] of steps) {
        if (Math.abs(diff) < limit) return rtf.format(Math.round(diff / unitSeconds), unit);
        unitSeconds = limit;
    }
    return new Date(iso).toLocaleDateString('es-AR', { day: 'numeric', month: 'short' });
}
