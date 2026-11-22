/*
 * The public Verify page, /kontrollera (ADR-046 amendment, gap 6, S12). The
 * chosen file is hashed here with Web Crypto and only the 64 hex characters
 * are sent; the file itself never leaves the device. A hash in the link's
 * fragment (#sha256=…, from the certificate's QR code) is read here too and is
 * removed from the address bar after it has been used. No inline script, no
 * third-party code: the page's content security policy allows NONOX's own
 * scripts only.
 */
import { hashFile, hashFromFragment, isSha256, latestGuard, requestBody, resultHeading, resultNote } from './verify-core.js';

const root = document.querySelector('[data-verify]');

if (root) {
    const input = root.querySelector('[data-verify-file]');
    const status = root.querySelector('[data-verify-status]');
    const result = root.querySelector('[data-verify-result]');
    const heading = root.querySelector('[data-verify-heading]');
    const list = root.querySelector('[data-verify-list]');
    const note = root.querySelector('[data-verify-note]');
    const url = root.dataset.verifyUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const text = JSON.parse(root.dataset.verifyText ?? '{}');
    // Every choice gets its own number and its own AbortController; an answer that is not for the latest choice is dropped.
    const guard = latestGuard();
    let controller = null;

    const say = (message) => {
        status.textContent = message;
    };

    const show = (data, source, name) => {
        // "Filen är oförändrad" is said only when a chosen file matched exactly one signing.
        heading.textContent = resultHeading(data.status, source, data.heading, text);
        list.replaceChildren();

        if (source === 'file' && name) {
            const term = document.createElement('dt');
            const detail = document.createElement('dd');
            term.textContent = text.chosenFile;
            detail.textContent = name;
            list.append(term, detail);
        }

        for (const [label, value] of data.lines ?? []) {
            const term = document.createElement('dt');
            const detail = document.createElement('dd');
            term.textContent = label;
            detail.textContent = value;
            list.append(term, detail);
        }

        note.textContent = resultNote(data.status, source, text);
        result.dataset.found = data.found ? 'true' : 'false';
        result.hidden = false;
        result.focus();
    };

    const check = async (hash, source, name, ticket) => {
        if (!isSha256(hash)) {
            return;
        }

        controller?.abort();
        controller = new AbortController();
        const mine = controller;
        result.hidden = true;
        say(text.working);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: requestBody(hash),
                credentials: 'same-origin',
                referrerPolicy: 'no-referrer',
                signal: mine.signal,
            });

            if (!guard.isCurrent(ticket)) {
                return;
            }

            if (response.status === 429) {
                say(text.tooMany);

                return;
            }

            if (!response.ok) {
                say(text.failed);

                return;
            }

            const data = await response.json();

            if (!guard.isCurrent(ticket)) {
                return;
            }

            say('');
            show(data, source, name);
        } catch {
            if (guard.isCurrent(ticket)) {
                say(text.failed);
            }
        }
    };

    input?.addEventListener('change', async () => {
        const file = input.files?.[0];

        if (!file) {
            return;
        }

        const ticket = guard.next();
        controller?.abort();
        result.hidden = true;
        say(text.hashing);

        try {
            const hash = await hashFile(file);

            if (!guard.isCurrent(ticket)) {
                return;
            }

            await check(hash, 'file', file.name, ticket);
        } catch {
            if (guard.isCurrent(ticket)) {
                say(text.noCrypto);
            }
        }

        input.value = '';
    });

    const fromLink = hashFromFragment(window.location.hash);

    if (fromLink) {
        history.replaceState(null, '', window.location.pathname + window.location.search);
        check(fromLink, 'link', '', guard.next());
    }
}
