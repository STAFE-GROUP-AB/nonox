/**
 * Margin and floor warnings on the editor's rows (ADR-082 §9, P7). The
 * server does the arithmetic (App\Livewire\Invoicing\Concerns\ChecksRowMargin);
 * the browser only decides when to ask: after something that moves the
 * margin has changed, not after every keystroke in a description.
 */

/** The parts of a row that move its margin, joined so two equal rows give the same text. */
export function marginSignature(rows) {
    return JSON.stringify(
        (rows ?? []).map((row) => [row.article ?? '', row.quantity ?? '', row.unit_id ?? '', row.price ?? '', row.discount ?? '', row.price_source === 'MANUAL' ? 'M' : '']),
    );
}

/** What the browser sends: only what the server reads, so no description or note leaves with it. */
export function marginPayload(rows) {
    return (rows ?? []).map((row) => ({
        article: row.article ?? '',
        quantity: row.quantity ?? '',
        unit_id: row.unit_id ?? '',
        price: row.price ?? '',
        discount: row.discount ?? '',
        price_source: row.price_source === 'MANUAL' ? 'MANUAL' : '',
    }));
}
