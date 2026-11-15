/**
 * Formats integer centavos as a peso string, matching App\Support\Money::format.
 * Integer math only; never pass floats through this helper.
 */
export function formatCents(cents: number): string {
    const abs = Math.abs(cents);
    const pesos = new Intl.NumberFormat('en-PH').format(Math.floor(abs / 100));
    const centavos = String(abs % 100).padStart(2, '0');

    return `${cents < 0 ? '-' : ''}₱${pesos}.${centavos}`;
}

/**
 * Parses peso input to integer centavos with the same rules as App\Support\Money::toCents
 * (commas only as thousands separators, at most 2 decimals). Returns null when invalid.
 * Display only: the server parses again and is the source of truth.
 */
export function parsePesos(input: string): number | null {
    const m = input.trim().match(/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/);
    if (!m) return null;
    const whole = m[1].replace(/,/g, '');
    if (whole.replace(/^0+/, '').length > 13) return null;
    return Number(whole) * 100 + Number((m[2] ?? '').padEnd(2, '0'));
}
