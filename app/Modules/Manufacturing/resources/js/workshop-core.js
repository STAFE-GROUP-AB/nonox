// The workshop terminal's logic that needs no page (ADR-071 §7): time
// arithmetic and wording, tested in tests/js/workshop-terminal.test.mjs.

export const MAX_MINUTES = 960;

/** Whole minutes from hours and minutes. */
export const totalMinutes = ({ hours, minutes }) => hours * 60 + minutes;

/** Adds minutes (negative to subtract) to a report, never below zero or above 16 hours. */
export function addMinutes(time, delta) {
    const total = Math.min(MAX_MINUTES, Math.max(0, totalMinutes(time) + delta));

    return { hours: Math.floor(total / 60), minutes: total % 60 };
}

/** 135 → "2 h 15 min", with the words from the server's strings. */
export function formatDuration(total, t) {
    const hours = Math.floor(total / 60);
    const minutes = total % 60;

    if (hours === 0) {
        return t('minutes_short', { min: minutes });
    }

    return minutes === 0 ? t('hours_short', { h: hours }) : t('hours_minutes_short', { h: hours, min: minutes });
}

/** The letters in a picture-less tile: "Anna Lind" → "AL". */
export function initials(name) {
    const parts = String(name).trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    return (parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/** Replaces :name placeholders in a string. */
export function fill(text, replace = {}) {
    let result = text;

    for (const [name, value] of Object.entries(replace)) {
        result = result.replaceAll(`:${name}`, String(value));
    }

    return result;
}

/** How long ago an ISO time was, as whole minutes (never negative). */
export function minutesSince(iso, now = Date.now()) {
    return Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));
}
