/**
 * The parts of the spotlight that do not touch the page (ADR-026), kept
 * apart so they can be tested with node --test.
 */

/** Groups with an exact hit (a number, an OCR) go first; otherwise the provider's own order. */
export function sortGroups(groups) {
    return [...groups].sort((a, b) => {
        const exact = (group) => (group.best >= 1000 ? 0 : 1);

        return exact(a) - exact(b) || a.order - b.order || a.key.localeCompare(b.key);
    });
}

/** Every result in the order it is shown, with the group it belongs to. */
export function flatten(groups) {
    return groups.flatMap((group) => group.results.map((result) => ({ ...result, group: group.key })));
}

/** The uid that is `delta` steps away, wrapping around. */
export function step(flat, activeUid, delta) {
    if (flat.length === 0) {
        return null;
    }

    const index = flat.findIndex((result) => result.uid === activeUid);
    const next = index === -1 ? (delta > 0 ? 0 : flat.length - 1) : (index + delta + flat.length) % flat.length;

    return flat[next].uid;
}

/** The first result of the next (or previous) group, for Tab and Shift+Tab. */
export function stepGroup(groups, activeUid, delta) {
    const shown = groups.filter((group) => group.results.length > 0);

    if (shown.length === 0) {
        return null;
    }

    const index = shown.findIndex((group) => group.results.some((result) => result.uid === activeUid));
    const next = index === -1 ? 0 : (index + delta + shown.length) % shown.length;

    return shown[next].results[0].uid;
}

/** The words worth highlighting: two characters or more, longest first. */
export function terms(query) {
    return [...new Set(query.toLowerCase().split(/\s+/).filter((word) => word.length >= 2))].sort((a, b) => b.length - a.length);
}

/**
 * The text cut into pieces, each marked as a hit or not, so the template
 * can highlight matches without ever inserting HTML.
 */
export function segments(text, words) {
    if (!text || words.length === 0) {
        return [{ text: text ?? '', hit: false }];
    }

    const escaped = words.map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const pattern = new RegExp(`(${escaped.join('|')})`, 'gi');

    return text
        .split(pattern)
        .filter((part) => part !== '')
        .map((part) => ({ text: part, hit: words.includes(part.toLowerCase()) }));
}

/** The field without the words a chip came from. */
export function withoutToken(query, token) {
    const index = query.indexOf(token);

    if (index === -1) {
        return query;
    }

    return (query.slice(0, index) + ' ' + query.slice(index + token.length)).replace(/\s+/g, ' ').trim();
}

/** Reads newline-delimited JSON as it streams in, calling back for each complete line. */
export async function readLines(response, onLine) {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { value, done } = await reader.read();

        if (done) {
            break;
        }

        buffer += decoder.decode(value, { stream: true });
        let newline = buffer.indexOf('\n');

        while (newline !== -1) {
            const line = buffer.slice(0, newline).trim();
            buffer = buffer.slice(newline + 1);

            if (line !== '') {
                onLine(JSON.parse(line));
            }

            newline = buffer.indexOf('\n');
        }
    }

    if (buffer.trim() !== '') {
        onLine(JSON.parse(buffer));
    }
}

/** Whether a key press happens while the user is typing somewhere, so "/" should type a slash. */
export function isEditable(element) {
    if (!element) {
        return false;
    }

    const tag = element.tagName;

    return element.isContentEditable || tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
}
