/**
 * The amounts of an invoice, quote or order while typing, calculated exactly
 * like the server (App\Domain\Invoicing\InvoiceCalculator): whole öre with
 * BigInt, half away from zero, VAT per rate on the sum of that rate's rows,
 * and the total rounded to whole kronor. The server calculates again when the
 * document is saved; that result is the one that counts.
 */

import { parseOre } from './money.js';

/** Divides two BigInts, rounding half away from zero. */
function roundDiv(numerator, denominator) {
    const negative = (numerator < 0n) !== (denominator < 0n);
    const a = numerator < 0n ? -numerator : numerator;
    const b = denominator < 0n ? -denominator : denominator;
    const quotient = (a * 2n + b) / (2n * b);

    return negative ? -quotient : quotient;
}

/** "2", "1,5", "0.25" → thousandths as a BigInt, or null. */
export function parseQuantity(value) {
    const text = String(value ?? '').replace(/[\s ]/g, '').replace(',', '.');
    const match = text.match(/^(-?)(\d{1,9})(?:\.(\d{1,3}))?$/);

    if (!match) {
        return null;
    }

    const thousandths = BigInt(match[2]) * 1000n + BigInt((match[3] ?? '').padEnd(3, '0'));

    return match[1] === '-' ? -thousandths : thousandths;
}

/** "10", "12,5" → basis points (1250), 0 when empty, null when unreadable or over 100 %. */
export function parseDiscount(value) {
    const text = String(value ?? '').trim().replace(',', '.');

    if (text === '') {
        return 0;
    }

    const match = text.match(/^(\d{1,3})(?:\.(\d{1,2}))?$/);

    if (!match) {
        return null;
    }

    const basisPoints = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));

    return basisPoints <= 10000 ? basisPoints : null;
}

/** Quantity × price less discount, in öre, or null while the row cannot be read. */
export function rowNet(row, negate = false) {
    const quantity = parseQuantity(row.quantity);
    const discount = parseDiscount(row.discount);
    const price = String(row.price ?? '').trim() === '' ? null : parseOre(row.price);

    if (quantity === null || discount === null || price === null) {
        return null;
    }

    const signed = negate && quantity > 0n ? -quantity : quantity;

    return Number(roundDiv(BigInt(price) * signed * BigInt(10000 - discount), 10_000_000n));
}

/**
 * Totals in öre: net, VAT per rate (highest first), rounding and total. An
 * invoice in another currency is not rounded to whole units ($roundToKronor).
 */
export function totals(rows, negate = false, roundToKronor = true) {
    const byRate = new Map();

    for (const row of rows) {
        const net = rowNet(row, negate);

        if (net === null) {
            continue;
        }

        const rate = Number(row.vat_rate ?? 2500);
        byRate.set(rate, (byRate.get(rate) ?? 0) + net);
    }

    const vatByRate = [...byRate.entries()]
        .sort(([a], [b]) => b - a)
        .map(([rate, base]) => ({ rate, base, vat: Number(roundDiv(BigInt(base) * BigInt(rate), 10000n)) }));

    const net = vatByRate.reduce((sum, group) => sum + group.base, 0);
    const vat = vatByRate.reduce((sum, group) => sum + group.vat, 0);
    const total = roundToKronor ? Number(roundDiv(BigInt(net + vat), 100n)) * 100 : net + vat;

    return { net, vat, vatByRate, rounding: total - net - vat, total };
}
