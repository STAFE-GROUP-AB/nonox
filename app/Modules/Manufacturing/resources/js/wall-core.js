// The workshop wall screen's logic that needs no page (ADR-071, M7):
// reading and removing the pairing code in the address, the wording of
// dates and delays, and when the board is too old. Tested in
// tests/js/workshop-wall.test.mjs.
import { fill } from './workshop-core.js';

export { fill };

/** The pairing code in the fragment (#kod=ABCD-EFGH), never in the query string, which reaches the server's logs. */
export function codeFromHash(hash) {
    return new URLSearchParams(String(hash).replace(/^#/, '')).get('kod') ?? '';
}

/** The address without the pairing code, for history.replaceState: other fragment values are kept. */
export function withoutCode(href) {
    const url = new URL(href);
    const params = new URLSearchParams(url.hash.replace(/^#/, ''));

    params.delete('kod');
    url.hash = params.toString();

    return url.toString().replace(/#$/, '');
}

const DAY = 86400000;

/** "2026-11-17" against today → "i dag", "i morgon", or "tis 17 nov". */
export function formatDay(date, today, t) {
    if (date === today) {
        return t('day_today');
    }

    const days = Math.round((Date.parse(`${date}T12:00:00Z`) - Date.parse(`${today}T12:00:00Z`)) / DAY);

    if (days === 1) {
        return t('day_tomorrow');
    }

    if (days === -1) {
        return t('day_yesterday');
    }

    return new Intl.DateTimeFormat('sv-SE', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })
        .format(new Date(`${date}T12:00:00Z`))
        .replace('.', '');
}

/** How late an order is, in working days; null when the plan cannot say (past its horizon). */
export function lateText(days, t) {
    if (days === null || days === undefined) {
        return t('after_horizon');
    }

    return days === 1 ? t('late_day') : t('late_days', { days });
}

/** The time of day of an ISO time in Swedish time: "10:42". */
export function clock(iso) {
    return new Intl.DateTimeFormat('sv-SE', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Stockholm' }).format(new Date(iso));
}

/** A board is stale when no answer has come for three refreshes. */
export function isStale(lastOkMs, nowMs, refreshSeconds) {
    return lastOkMs !== null && nowMs - lastOkMs > refreshSeconds * 3000;
}

/** A time from the board: "09:30" today as it is, an earlier or later date as formatDay writes it. */
export function moment(value, today, t) {
    return /^\d{4}-\d{2}-\d{2}$/.test(String(value)) ? formatDay(value, today, t) : value;
}

/**
 * What the screen does when it starts: the preview loads, a code in the
 * address pairs (also on a screen paired before), a stored token loads,
 * and otherwise the pairing form is shown.
 */
export function startAction({ preview, token, code }) {
    if (preview) {
        return 'load';
    }

    if (code !== '') {
        return 'pair';
    }

    return token ? 'load' : 'form';
}

/** After a code is refused: a screen that is already paired keeps showing its board. */
export function afterFailedPair(token) {
    return token ? 'load' : 'form';
}
