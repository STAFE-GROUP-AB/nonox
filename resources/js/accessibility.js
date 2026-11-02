/**
 * Accessibility for every signed-in page (docs/tillganglighet.md).
 *
 * - Each field error (<x-input-error>, data-error-for) is linked to its field:
 *   aria-invalid="true" and the error's id in aria-describedby, so a screen
 *   reader reads the error with the field (WCAG 3.3.1, 1.3.1). A hint
 *   (<x-ui.field hint>, data-hint-for) is linked the same way (3.3.2).
 * - After a form is sent and comes back with errors, focus moves to the first
 *   field with an error and the number of errors is read aloud (3.3.1, 4.1.3).
 *
 * Livewire morphs the page after every request and drops attributes the
 * server did not render, so the links are made again after each morph.
 */

/**
 * Whether a field's attributes ([[name, value], ...]) bind it to an error
 * key: wire:model with any modifiers, or the form field's name.
 */
export function boundTo(attributes, key) {
    return attributes.some(([name, value]) => value === key && (name === 'name' || name === 'wire:model' || name.startsWith('wire:model.')));
}

/** aria-describedby with the id added once, keeping what was there. */
export function withDescribedBy(existing, id) {
    const ids = String(existing ?? '').split(/\s+/).filter(Boolean);

    if (!ids.includes(id)) {
        ids.push(id);
    }

    return ids.join(' ');
}

/** The sentence read aloud after a form came back with errors. */
export function errorSummary(count, one, many) {
    if (count < 1) {
        return '';
    }

    return count === 1 ? one : many.replace(':count', String(count));
}

function fieldFor(error) {
    const id = error.dataset.errorField;

    if (id) {
        const byId = document.getElementById(id);

        if (byId) {
            return byId;
        }
    }

    const scope = error.closest('form, [wire\\:id]') ?? document;
    const key = error.dataset.errorFor;

    return [...scope.querySelectorAll('input, select, textarea')]
        .find((field) => boundTo([...field.attributes].map((attribute) => [attribute.name, attribute.value]), key)) ?? null;
}

export function linkFields(root = document) {
    root.querySelectorAll('[data-hint-for]').forEach((hint) => {
        const field = document.getElementById(hint.dataset.hintFor);

        if (field && hint.id) {
            field.setAttribute('aria-describedby', withDescribedBy(field.getAttribute('aria-describedby'), hint.id));
        }
    });

    const invalid = [];

    root.querySelectorAll('[data-error-for]').forEach((error) => {
        const field = fieldFor(error);

        if (!field || !error.id) {
            return;
        }

        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', withDescribedBy(field.getAttribute('aria-describedby'), error.id));
        invalid.push(field);
    });

    return invalid;
}

function announce(text) {
    const region = document.getElementById('nx-announcer');

    if (!region || text === '') {
        return;
    }

    region.textContent = '';
    setTimeout(() => {
        region.textContent = text;
    }, 50);
}

if (typeof document !== 'undefined') {
    let sentForm = null;

    document.addEventListener('submit', (event) => {
        sentForm = event.target;
    }, true);

    const afterUpdate = (root) => {
        const invalid = linkFields(root);

        if (sentForm === null) {
            return;
        }

        const form = sentForm;
        sentForm = null;
        const own = invalid.filter((field) => form.contains(field));
        const errors = form.querySelectorAll('[data-error-for]').length;

        if (errors === 0) {
            return;
        }

        const region = document.getElementById('nx-announcer');
        announce(errorSummary(errors, region?.dataset.oneError ?? '', region?.dataset.manyErrors ?? ''));
        (own[0] ?? form.querySelector('[data-error-for]'))?.focus?.();
    };

    // A focused element is never left under a sticky top bar (WCAG 2.4.11).
    document.addEventListener('focusin', (event) => {
        const target = event.target;

        if (!(target instanceof Element) || target.closest('[data-sticky-bar], aside, [role="dialog"]')) {
            return;
        }

        const covered = [...document.querySelectorAll('[data-sticky-bar]')]
            .map((bar) => bar.getBoundingClientRect())
            .filter((rect) => rect.height > 0)
            .reduce((bottom, rect) => Math.max(bottom, rect.bottom), 0);
        const top = target.getBoundingClientRect().top;

        if (top < covered + 8) {
            window.scrollBy({ top: top - covered - 16 });
        }
    });

    document.addEventListener('DOMContentLoaded', () => linkFields());
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('morphed', ({ el }) => queueMicrotask(() => afterUpdate(el)));
    });
}
