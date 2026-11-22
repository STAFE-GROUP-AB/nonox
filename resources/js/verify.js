/*
 * The public Verify page, /kontrollera (ADR-046 amendment, gap 6, S12). The
 * chosen file is hashed here with Web Crypto and only the 64 hex characters
 * are sent; the file itself never leaves the device. A hash in the link's
 * fragment (#sha256=…, from the certificate's QR code) is read here too and is
 * removed from the address bar after it has been used. No inline script, no
 * third-party code: the page's content security policy allows NONOX's own
 * scripts only.
 */
import { hashFile, hashFromFragment, isSha256, requestBody } from './verify-core.js';

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
    let busy = false;

    const say = (message) => {
        status.textContent = message;
    };

    const show = (data, source) => {
        heading.textContent = data.heading;
        list.replaceChildren();

        for (const [label, value] of data.lines ?? []) {
            const term = document.createElement('dt');
            const detail = document.createElement('dd');
            term.textContent = label;
            detail.textContent = value;
            list.append(term, detail);
        }

        note.textContent = source === 'link' ? text.fromLink : '';
        result.dataset.found = data.found ? 'true' : 'false';
        result.hidden = false;
        result.focus();
    };

    const check = async (hash, source) => {
        if (busy || !isSha256(hash)) {
            return;
        }

        busy = true;
        result.hidden = true;
        say(text.working);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: requestBody(hash),
                credentials: 'same-origin',
                referrerPolicy: 'no-referrer',
            });

            if (response.status === 429) {
                say(text.tooMany);

                return;
            }

            if (!response.ok) {
                say(text.failed);

                return;
            }

            say('');
            show(await response.json(), source);
        } catch {
            say(text.failed);
        } finally {
            busy = false;
        }
    };

    input?.addEventListener('change', async () => {
        const file = input.files?.[0];

        if (!file) {
            return;
        }

        say(text.hashing);

        try {
            await check(await hashFile(file), 'file');
        } catch {
            say(text.noCrypto);
        }

        input.value = '';
    });

    const fromLink = hashFromFragment(window.location.hash);

    if (fromLink) {
        history.replaceState(null, '', window.location.pathname + window.location.search);
        check(fromLink, 'link');
    }
}
