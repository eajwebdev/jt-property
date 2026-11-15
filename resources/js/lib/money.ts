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
