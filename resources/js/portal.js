/*
 * The customer portal (/kundportal, ADR-075): a page of its own with no
 * Alpine, no Livewire and no inline script, so its content security policy
 * allows scripts from NONOX only.
 *
 * - [data-copy="…"]: copies the value (bankgiro, OCR number, amount) and
 *   says so in the button's live region.
 * - form[data-once]: the submit button is disabled while the form is sent,
 *   so a double click or a quick Enter sends one code, one message.
 * - [data-confirm="…"]: a form that asks first (removing one's login).
 */
function copy(button) {
    const value = button.getAttribute('data-copy') ?? '';
    const status = button.querySelector('[data-copy-status]');
    const done = () => {
        if (status) {
            status.textContent = button.getAttribute('data-copied') ?? 'Kopierat';
            setTimeout(() => { status.textContent = ''; }, 2500);
        }
    };

    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(value).then(done, () => {});

        return;
    }

    const field = document.createElement('textarea');
    field.value = value;
    field.setAttribute('readonly', '');
    field.style.position = 'absolute';
    field.style.left = '-9999px';
    document.body.appendChild(field);
    field.select();
    document.execCommand('copy');
    field.remove();
    done();
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy]') : null;

    if (button) {
        event.preventDefault();
        copy(button);
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const question = form.getAttribute('data-confirm');

    if (question && !window.confirm(question)) {
        event.preventDefault();

        return;
    }

    if (form.hasAttribute('data-once')) {
        if (form.dataset.sent === '1') {
            event.preventDefault();

            return;
        }

        form.dataset.sent = '1';
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.setAttribute('aria-disabled', 'true');
            button.classList.add('opacity-60');
        });
    }
});
