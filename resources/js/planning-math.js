/**
 * Pure helpers for the schedule grid (Planering → Schema): snapping to the
 * company's time step and turning grid positions into the wall-clock times
 * the server takes ("YYYY-MM-DD HH:MM", read in Europe/Stockholm). No time
 * zone arithmetic happens in the browser, so summer time cannot move a shift.
 */

export const DAY_MINUTES = 24 * 60;

/** Rounds minutes to the nearest step. */
export function snap(minutes, step) {
    const size = Math.max(1, Number(step) || 15);

    return Math.round(minutes / size) * size;
}

/** Keeps a value within [min, max]. */
export function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

/** "YYYY-MM-DD" plus whole days, by the calendar. */
export function addDays(date, days) {
    const [year, month, day] = date.split('-').map(Number);
    const moved = new Date(Date.UTC(year, month - 1, day + days));

    return moved.toISOString().slice(0, 10);
}

/** Minutes after midnight of a date as "YYYY-MM-DD HH:MM"; 1440 and more fall on the next days. */
export function at(date, minutes) {
    const days = Math.floor(minutes / DAY_MINUTES);
    const rest = minutes - days * DAY_MINUTES;
    const hours = String(Math.floor(rest / 60)).padStart(2, '0');
    const mins = String(rest % 60).padStart(2, '0');

    return `${addDays(date, days)} ${hours}:${mins}`;
}

/** "HH:MM" as minutes after midnight. */
export function minutesOf(time) {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
}

/**
 * A shift moved to another day, keeping its clock times: the end keeps its
 * distance in days from the start (an evening shift that ends after midnight).
 */
export function moveToDay(shift, date) {
    return {
        startsAt: `${date} ${shift.start}`,
        endsAt: `${addDays(date, shift.endOffset)} ${shift.end}`,
    };
}

/**
 * The interval after a drag on the timeline. mode is move, start or end;
 * delta is the pointer's movement in minutes. Snapped, at least one step
 * long, and kept on the day's timeline (a shift may end up to 24 hours later).
 */
export function dragInterval(mode, startMin, endMin, delta, step) {
    const size = Math.max(1, Number(step) || 15);
    let start = startMin;
    let end = endMin;

    if (mode === 'move') {
        const length = endMin - startMin;
        start = clamp(snap(startMin + delta, size), 0, DAY_MINUTES - size);
        end = start + length;
    } else if (mode === 'start') {
        start = clamp(snap(startMin + delta, size), 0, endMin - size);
    } else if (mode === 'end') {
        end = clamp(snap(endMin + delta, size), startMin + size, startMin + DAY_MINUTES);
    }

    return { start, end };
}

/** A new interval drawn on the timeline from one point to another, in either direction. */
export function drawnInterval(fromMin, toMin, step) {
    const size = Math.max(1, Number(step) || 15);
    const a = clamp(snap(Math.min(fromMin, toMin), size), 0, DAY_MINUTES - size);
    const b = clamp(snap(Math.max(fromMin, toMin), size), a + size, DAY_MINUTES);

    return { start: a, end: b };
}

/** The cell an arrow key moves to in a grid of rows × columns, staying inside it. */
export function neighbour(row, column, key, rows, columns) {
    const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };
    const [dRow, dColumn] = moves[key] ?? [0, 0];

    return { row: clamp(row + dRow, 0, rows - 1), column: clamp(column + dColumn, 0, columns - 1) };
}
