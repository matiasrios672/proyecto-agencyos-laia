const nf = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function formatMoney(value: number, currency: string): string {
    return `${currency === 'USD' ? 'US$' : '$'} ${nf.format(value)}`;
}

/** "1500,5" / "1500.5" / 1500.5 → 1500.5 (0 si no es un número). */
export function toNumber(v: string | number | null | undefined): number {
    if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
    const n = parseFloat(String(v ?? '').replace(',', '.'));
    return Number.isFinite(n) ? n : 0;
}

/** Total de una línea en centavos (evita errores de punto flotante al sumar). */
export function lineCents(quantity: string | number, unitPrice: string | number): number {
    return Math.max(0, Math.round(toNumber(quantity))) * Math.round(toNumber(unitPrice) * 100);
}
