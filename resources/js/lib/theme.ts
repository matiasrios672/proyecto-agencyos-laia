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
