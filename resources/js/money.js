/**
 * Amounts in whole öre, parsed and formatted like the server's Money class.
 * No browser code here, so it can be tested with node --test.
 */

const NBSP = ' ';

/** Parses Swedish amounts like the server's Money::parse. Returns öre or null. */
export function parseOre(value) {
    const text = String(value ?? '')
        .replace(/[\s  ]/g, '')
        .replace('−', '-')
        .replace(/(kr\.?|sek)$/i, '');

    if (text === '') {
        return 0;
    }

    const match = text.match(/^(-?)(\d+)(?:[.,](\d{1,2}))?$/);

    if (!match) {
        return null;
    }

    const ore = Number(match[2]) * 100 + Number((match[3] ?? '').padEnd(2, '0'));

    return match[1] === '-' ? -ore : ore;
}

export function formatOre(ore) {
    const sign = ore < 0 ? '-' : '';
    const absolute = Math.abs(ore);
    const kronor = String(Math.floor(absolute / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
    const rest = String(absolute % 100).padStart(2, '0');

    return `${sign}${kronor},${rest}`;
}
