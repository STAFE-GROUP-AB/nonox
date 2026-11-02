// The scanning app's pure logic (ADR-040), shared by the browser and
// node --test: what a scanned code means, quantities in thousandths, the
// offline queue and the local view of stock while operations wait.

export const SCALE = 1000;
export const LOCATION_PREFIX = 'L:';
export const ARTICLE_PREFIX = 'A:';

/** "3", "1,5", "1 234,25" or "1.5" → thousandths; null when it is not a quantity. */
export function parseQuantity(input) {
    const text = String(input ?? '').replace(/[\s  ]/g, '').replace(',', '.');

    if (!/^-?\d+(\.\d{1,3})?$/.test(text)) {
        return null;
    }

    const negative = text.startsWith('-');
    const [whole, fraction = ''] = text.replace('-', '').split('.');
    const milli = Number(whole) * SCALE + Number(fraction.padEnd(3, '0'));

    return negative ? -milli : milli;
}

/** Thousandths as Swedish text: 1500 → "1,5", 1234000 → "1 234". */
export function formatQuantity(milli) {
    const sign = milli < 0 ? '−' : '';
    const value = Math.abs(milli);
    const whole = Math.trunc(value / SCALE).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const fraction = String(value % SCALE).padStart(3, '0').replace(/0+$/, '');

    return `${sign}${whole}${fraction ? `,${fraction}` : ''}`;
}

/** Thousandths as the decimal text the server reads: 1500 → "1.5". */
export function decimal(milli) {
    const sign = milli < 0 ? '-' : '';
    const value = Math.abs(milli);
    const fraction = String(value % SCALE).padStart(3, '0').replace(/0+$/, '');

    return `${sign}${Math.trunc(value / SCALE)}${fraction ? `.${fraction}` : ''}`;
}

/** The GTINs a digit code may stand for (as ScanCode::gtinCandidates on the server). */
export function gtinCandidates(code) {
    if (!/^\d{8,14}$/.test(code)) {
        return [];
    }

    const candidates = [code];

    if (code.startsWith('0') && code.length > 8) {
        candidates.push(code.slice(1));
    }

    if (code.length < 14) {
        candidates.push(`0${code}`);
    }

    return [...new Set(candidates)];
}

/** Lookup tables over the cached data, built once per download. */
export function buildIndex(data) {
    const index = {
        articles: new Map(),
        locations: new Map(),
        gtin: new Map(),
        scanner: new Map(),
        sku: new Map(),
        number: new Map(),
        code: new Map(),
    };

    for (const article of data?.articles ?? []) {
        index.articles.set(article.id, article);

        for (const [field, map] of [['gtin', index.gtin], ['scanner_code', index.scanner], ['sku', index.sku], ['number', index.number]]) {
            if (article[field] && !map.has(article[field])) {
                map.set(article[field], article);
            }
        }
    }

    for (const location of data?.locations ?? []) {
        index.locations.set(location.id, location);
        index.code.set(location.code.toUpperCase(), location);
    }

    return index;
}

/**
 * What a code means, by the same rules as ScanCode::resolve on the server:
 * "L:" a location, "A:" an article number; otherwise an article by GTIN,
 * scanner code, SKU or number, then a location by its full code.
 */
export function resolveCode(raw, index) {
    const code = String(raw ?? '').trim();

    if (code === '' || code.length > 120) {
        return { type: null, code };
    }

    const prefix = code.slice(0, 2).toUpperCase();

    if (prefix === LOCATION_PREFIX) {
        const location = index.code.get(code.slice(2).trim().toUpperCase());

        return location ? { type: 'location', location, code } : { type: null, code };
    }

    if (prefix === ARTICLE_PREFIX) {
        const article = index.number.get(code.slice(2).trim());

        return article ? { type: 'article', article, code } : { type: null, code };
    }

    for (const gtin of gtinCandidates(code)) {
        const article = index.gtin.get(gtin);

        if (article) {
            return { type: 'article', article, code };
        }
    }

    const article = index.scanner.get(code) ?? index.sku.get(code) ?? index.number.get(code);

    if (article) {
        return { type: 'article', article, code };
    }

    const location = index.code.get(code.toUpperCase());

    return location ? { type: 'location', location, code } : { type: null, code };
}

/** The location and everything under it (sort keys are paths: "HL/A/00000003"). */
export function inSubtree(location, root) {
    if (!root) {
        return true;
    }

    return location.sort === root.sort || location.sort.startsWith(`${root.sort}/`);
}

/** Stock of an article per location, in walking order: [{ location, quantity }]. */
export function stockOf(articleId, levels, index) {
    return levels
        .filter(([article]) => article === articleId)
        .map(([, locationId, quantity]) => ({ location: index.locations.get(locationId) ?? { id: locationId, code: '?', sort: '~' }, quantity }))
        .sort((a, b) => a.location.sort.localeCompare(b.location.sort));
}

/** What is stored at a location: [{ article, quantity }] by article number. */
export function contentsOf(locationId, levels, index) {
    return levels
        .filter(([, location]) => location === locationId)
        .map(([articleId, , quantity]) => ({ article: index.articles.get(articleId) ?? { id: articleId, number: '?', name: '' }, quantity }))
        .sort((a, b) => String(a.article.number).localeCompare(String(b.article.number), 'sv'));
}

/** Levels after a change on the phone, so lookups show it before the server has it. */
export function adjustLevels(levels, changes) {
    const next = levels.map((level) => [...level]);

    for (const { article_id: articleId, location_id: locationId, quantity } of changes) {
        const level = next.find(([article, location]) => article === articleId && location === locationId);

        if (level) {
            level[2] += quantity;
        } else {
            next.push([articleId, locationId, quantity]);
        }
    }

    return next.filter(([, , quantity]) => quantity !== 0);
}

/** The stock changes an operation will make, for adjustLevels. */
export function changesOf(operation, data) {
    const payload = operation.payload;

    if (operation.kind === 'receive') {
        return payload.lines.filter((line) => line.location_id).map((line) => ({ article_id: line.article_id, location_id: line.location_id, quantity: parseQuantity(line.quantity) ?? 0 }));
    }

    if (operation.kind === 'move') {
        const quantity = parseQuantity(payload.quantity) ?? 0;

        return [
            { article_id: payload.article_id, location_id: payload.from_location_id, quantity: -quantity },
            { article_id: payload.article_id, location_id: payload.to_location_id, quantity },
        ];
    }

    if (operation.kind === 'pick') {
        const list = data.pick_lists.find((candidate) => candidate.id === payload.pick_list_id);
        const lines = new Map((list?.lines ?? []).map((line) => [line.id, line]));

        return payload.lines
            .map((row) => ({ line: lines.get(row.line_id), picked: parseQuantity(row.picked) ?? 0 }))
            .filter(({ line, picked }) => line && line.location_id && picked > 0)
            .map(({ line, picked }) => ({ article_id: line.article_id, location_id: line.location_id, quantity: -picked }));
    }

    return [];
}

/**
 * Receipt lines with what is scanned now: expected (ordered), received
 * before, and scanned on this phone.
 */
export function receiptProgress(receipt, rows) {
    return (receipt?.lines ?? []).map((line) => {
        const scanned = rows.filter((row) => row.article_id === line.article_id).reduce((sum, row) => sum + row.quantity, 0);

        return { ...line, scanned, done: line.received + scanned >= line.ordered };
    });
}

/** Pick list lines in walking order: by location path, lines without a location last. */
export function walkingOrder(lines, index) {
    return [...lines].sort((a, b) => {
        const sa = index.locations.get(a.location_id)?.sort ?? '￿';
        const sb = index.locations.get(b.location_id)?.sort ?? '￿';

        return sa === sb ? a.id - b.id : sa.localeCompare(sb);
    });
}

/**
 * The stocktake as counted on the phone: the server's lines merged with the
 * phone's counts, keyed article:location, with the difference.
 */
export function countRows(count, counted) {
    const rows = new Map();

    for (const line of count?.lines ?? []) {
        rows.set(`${line.article_id}:${line.location_id}`, { article_id: line.article_id, location_id: line.location_id, system: line.system, counted: line.counted, reason: line.reason ?? null });
    }

    for (const [key, entry] of Object.entries(counted ?? {})) {
        const row = rows.get(key) ?? { article_id: entry.article_id, location_id: entry.location_id, system: 0, counted: null, reason: null };
        rows.set(key, { ...row, counted: entry.counted, reason: entry.reason ?? row.reason });
    }

    return [...rows.values()].map((row) => ({ ...row, difference: row.counted === null ? null : row.counted - row.system }));
}

/** The queue after the server's answers: done ones leave, rejected ones become conflicts. */
export function applyResults(queue, results) {
    const answers = new Map(results.map((result) => [result.uuid, result]));
    const remaining = [];
    const done = [];
    const conflicts = [];

    for (const operation of queue) {
        const answer = answers.get(operation.uuid);

        if (!answer) {
            remaining.push(operation);
        } else if (answer.status === 'OK') {
            done.push({ ...operation, result: answer.data });
        } else {
            conflicts.push({ ...operation, status: answer.status, message: answer.message, at: new Date().toISOString() });
        }
    }

    return { remaining, done, conflicts };
}

/** Oldest first, so a receipt is in stock before the move that takes from it. */
export function sendOrder(queue) {
    return [...queue].sort((a, b) => (a.created_at === b.created_at ? 0 : a.created_at < b.created_at ? -1 : 1));
}

/** A new operation with its UUID and the company and user it was made for. */
export function newOperation(kind, payload, context, uuid, now = new Date()) {
    return { uuid, kind, payload, company_id: context.company_id, user_id: context.user_id, created_at: now.toISOString() };
}

/**
 * The server's data as the phone should show it while operations wait:
 * stock moved, receipts received, pick lists and finished stocktakes gone,
 * and saved counts in their stocktake.
 */
export function withPending(raw, operations) {
    if (!raw) {
        return raw;
    }

    const data = structuredClone(raw);
    const changes = operations.flatMap((operation) => changesOf(operation, raw));
    data.levels = adjustLevels(raw.levels ?? [], changes);

    for (const operation of operations) {
        const payload = operation.payload;

        if (operation.kind === 'pick') {
            data.pick_lists = data.pick_lists.filter((list) => list.id !== payload.pick_list_id);
        } else if (operation.kind === 'receive' && payload.receipt_id) {
            const receipt = data.receipts.find((candidate) => candidate.id === payload.receipt_id);

            for (const line of payload.lines) {
                const target = receipt?.lines.find((candidate) => candidate.article_id === line.article_id);

                if (target) {
                    target.received += parseQuantity(line.quantity) ?? 0;
                }
            }
        } else if (operation.kind === 'count') {
            const count = data.counts.find((candidate) => candidate.id === payload.stock_count_id);

            if (!count) {
                continue;
            }

            if (payload.complete) {
                data.counts = data.counts.filter((candidate) => candidate.id !== count.id);
                continue;
            }

            for (const line of payload.lines) {
                const target = count.lines.find((candidate) => candidate.article_id === line.article_id && candidate.location_id === line.location_id);
                const counted = parseQuantity(line.counted);

                if (target) {
                    Object.assign(target, { counted, reason: line.reason ?? target.reason });
                } else {
                    const system = (raw.levels ?? []).find(([article, location]) => article === line.article_id && location === line.location_id)?.[2] ?? 0;
                    count.lines.push({ id: null, article_id: line.article_id, location_id: line.location_id, system, counted, reason: line.reason ?? null });
                }
            }
        }
    }

    return data;
}
