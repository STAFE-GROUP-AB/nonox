/**
 * The editor's price table (ADR-082 P3, App\Domain\Pricing\PriceTable): per
 * article number, its sales unit and its prices from each quantity step.
 * A row gets the price of the highest step its quantity reaches (decision 7);
 * below the lowest step, the lowest step's price. A row in another unit than
 * the table's is not priced here: the server prices it when the document is
 * saved. An agreed discount is already folded into a tier's price, so the
 * discount field only ever holds a discount typed by hand, and the browser
 * and the server give the same amount.
 */

import { formatOre } from './money.js';
import { parseQuantity } from './sales-math.js';

/** A typed quantity in milli-units, without its sign; an empty field counts as 1. */
export function quantityMilli(quantity) {
    if (String(quantity ?? '').trim() === '') {
        return 1000;
    }

    const milli = parseQuantity(String(quantity));

    if (milli === null) {
        return null;
    }

    return Number(milli < 0n ? -milli : milli);
}

/** The step of the table that prices the row, or null. */
export function tierFor(table, articleNumber, unitId, milli) {
    const entry = table?.[articleNumber];

    if (!entry || milli === null || String(entry.unit_id ?? '') !== String(unitId ?? '')) {
        return null;
    }

    const tiers = [...(entry.tiers ?? [])].sort((a, b) => a.min - b.min);

    if (tiers.length === 0) {
        return null;
    }

    return tiers.filter((tier) => tier.min <= milli).pop() ?? tiers[0];
}

/** Basis points as the discount field writes them: 1000 → "10", 1250 → "12,5", 0 → "". */
export function discountInput(basisPoints) {
    if (!basisPoints) {
        return '';
    }

    const whole = Math.trunc(basisPoints / 100);
    const rest = String(basisPoints % 100).padStart(2, '0').replace(/0+$/, '');

    return rest === '' ? String(whole) : `${whole},${rest}`;
}

/**
 * The net unit price from a gross one, rounded down, with the row's own VAT
 * rate in basis points (the same as DocumentPricing::consumerNet on the server).
 */
export function consumerNet(grossMinor, vatBp) {
    const numerator = BigInt(grossMinor) * 10000n;
    const denominator = BigInt(10000 + vatBp);
    const quotient = numerator / denominator;

    return Number(numerator < 0n && quotient * denominator !== numerator ? quotient - 1n : quotient);
}

/**
 * What a row takes from a step of the table: the price and a mark that it
 * came from the table (its source, or AUTO when the person may not see
 * sources). A discount typed on the row is not touched. No step: the price
 * is left empty and found when the document is saved.
 */
export function fromTier(tier, vatRate = null) {
    if (!tier) {
        return { price: '', price_source: '', price_warnings: '', price_explanation: '' };
    }

    // A price from a list including VAT is worked out from its gross price with the row's own VAT rate, as the server does.
    const vatBp = vatRate === null || vatRate === '' ? Number.NaN : Number(vatRate);
    const net = tier.gross !== null && tier.gross !== undefined && Number.isInteger(vatBp) && vatBp >= 0 ? consumerNet(tier.gross, vatBp) : tier.price;

    return {
        price: net === null ? '' : formatOre(net),
        price_source: tier.source || 'AUTO',
        price_warnings: (tier.warnings ?? []).join(','),
        price_explanation: '',
    };
}

/** Whether leaving the article field fills the price: only for an empty price or a new article, never over a typed or kept price. */
export function shouldFillPrice(row, articleChanged) {
    return articleChanged || String(row.price ?? '').trim() === '';
}

/**
 * A new customer, date or currency: the row keeps the price it shows, but
 * no longer as the table's (so the server keeps it as typed by hand when
 * the new price differs). Returns whether the row is an article row whose
 * price may now differ.
 */
export function afterContextChange(row) {
    if (!String(row.article ?? '').trim() || row.price_source === 'MANUAL') {
        return false;
    }

    row.price_source = '';
    row.price_explanation = '';

    return true;
}
