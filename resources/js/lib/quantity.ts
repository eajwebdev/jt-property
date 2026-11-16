/**
 * Meter readings (thousandths) and peso rates (ten-thousandths) as integers, matching App\Support\Quantity.
 * BigInt so reading x rate never loses precision. Display only: the server parses again and decides.
 */
function scaled(input: string, places: number): bigint | null {
    const m = input.trim().match(new RegExp(`^(\\d{1,9})(?:\\.(\\d{1,${places}}))?$`));
    if (!m) return null;
    return BigInt(m[1]) * 10n ** BigInt(places) + BigInt((m[2] ?? '').padEnd(places, '0'));
}

export const parseReading = (input: string) => scaled(input, 3);
export const parseRate = (input: string) => scaled(input, 4);

export function formatFixed(value: bigint | number, places: number): string {
    const v = BigInt(value);
    const f = 10n ** BigInt(places);
    return `${v / f}.${String(v % f).padStart(places, '0')}`;
}

/** round_half_up(used_e3 * rate_e4 / 100000) + base, in centavos. Mirrors UtilityAllocationService::amountFor. */
export function meteredAmountCents(usedE3: bigint, rateE4: bigint, baseCents: number): number {
    return Number((2n * usedE3 * rateE4 + 100000n) / 200000n) + baseCents;
}
