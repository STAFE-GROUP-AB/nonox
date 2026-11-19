/*
 * The public signing page (/signera/…, ADR-046): a signature pad on a canvas
 * for mouse, touch and stylus (Pointer Events), with undo and clear, and the
 * keyboard alternative of typing one's name. A page of its own: no Alpine,
 * no Livewire, no inline script, so the page's content security policy can
 * allow scripts from NONOX only. The model is in signature-pad-core.js.
 *
 * With fields in the document (ADR-046 amendment, gap 2, S6) the same pad,
 * smaller, takes the signer's initials, the boxes over the pages show what is
 * typed in the field list, and "Nästa obligatoriska fält" moves to the next
 * required field still empty (signing-fields-core.js).
 */
import { clear, createPad, end, hasSignature, move, sameName, start, toCanvas, undo } from './signature-pad-core.js';
import { nextRequired, preview } from './signing-fields-core.js';

/** A drawing pad on a canvas; onChange runs after every change. */
function drawingPad(canvas, lineWidth, onChange) {
    const context = canvas?.getContext('2d');
    const pad = createPad();
    let drawing = false;

    const redraw = () => {
        if (!context) {
            return;
        }

        context.clearRect(0, 0, canvas.width, canvas.height);
        context.lineWidth = lineWidth;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = '#111827';

        for (const stroke of pad.strokes) {
            context.beginPath();
            stroke.forEach((point, index) => (index === 0 ? context.moveTo(point.x, point.y) : context.lineTo(point.x, point.y)));

            if (stroke.length === 1) {
                context.lineTo(stroke[0].x + 0.1, stroke[0].y);
            }

            context.stroke();
        }

        onChange();
    };

    const point = (event) => toCanvas(event.clientX, event.clientY, canvas.getBoundingClientRect(), canvas.width, canvas.height);

    if (canvas && context) {
        canvas.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            drawing = true;
            canvas.setPointerCapture?.(event.pointerId);
            start(pad, point(event));
            redraw();
        });

        canvas.addEventListener('pointermove', (event) => {
            if (!drawing) {
                return;
            }

            event.preventDefault();
            // Coalesced events keep a fast stylus or finger smooth.
            const events = event.getCoalescedEvents?.() ?? [event];
            events.forEach((each) => move(pad, point(each)));
            redraw();
        });

        for (const type of ['pointerup', 'pointercancel', 'lostpointercapture']) {
            canvas.addEventListener(type, () => {
                drawing = false;
                end(pad);
            });
        }
    }

    return {
        hasInk: () => hasSignature(pad),
        undo: () => {
            undo(pad);
            redraw();
        },
        clear: () => {
            clear(pad);
            redraw();
        },
        dataUrl: () => canvas.toDataURL('image/png'),
        focus: () => canvas?.focus(),
    };
}

function say(element, text) {
    if (element) {
        element.textContent = text;
        element.hidden = text === '';
    }
}

/** The initials pad and its typed alternative (S6); returns a check run on submit, false when the form must not go. */
function setUpInitials(form) {
    const box = form.querySelector('[data-initials]');

    if (!box) {
        return () => true;
    }

    const error = box.querySelector('[data-initials-error]');
    const hidden = box.querySelector('[data-initials-input]');
    const pad = drawingPad(box.querySelector('[data-initials-canvas]'), 6, () => say(error, ''));
    const method = () => box.querySelector('input[name="initials_method"]:checked')?.value ?? 'DRAWN';

    const showMethod = () => {
        box.querySelectorAll('[data-initials-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.initialsPanel !== method();
        });
        say(error, '');
    };

    box.querySelector('[data-initials-clear]')?.addEventListener('click', () => pad.clear());
    box.querySelectorAll('input[name="initials_method"]').forEach((radio) => radio.addEventListener('change', showMethod));
    showMethod();

    return () => {
        hidden.value = '';

        if (method() !== 'DRAWN') {
            return true;
        }

        if (pad.hasInk()) {
            hidden.value = pad.dataUrl();

            return true;
        }

        if (box.dataset.initialsRequired === '1') {
            say(error, box.dataset.initialsEmptyText ?? '');
            pad.focus();

            return false;
        }

        return true;
    };
}

/** The boxes over the pages follow the field list, and "Nästa obligatoriska fält" finds the next empty one (S6). */
function setUpFields(form) {
    const controls = () => Array.from(document.querySelectorAll(`[form="${form.id}"][data-field-input]`));

    const refresh = () => {
        for (const control of controls()) {
            const box = document.querySelector(`[data-field-preview="${control.dataset.fieldInput}"]`);

            if (box) {
                box.textContent = preview(control, box.dataset.placeholder ?? '');
            }
        }
    };

    for (const control of controls()) {
        control.addEventListener('input', refresh);
        control.addEventListener('change', refresh);
    }

    refresh();

    document.querySelectorAll('[data-next-required]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const next = nextRequired(controls(), document.activeElement instanceof HTMLElement ? document.activeElement : null);

            if (!next) {
                return;
            }

            event.preventDefault();
            next.scrollIntoView({ block: 'center' });
            next.focus({ preventScroll: true });
        });
    });
}

function setUp(form) {
    const canvas = form.querySelector('[data-signature-canvas]');
    const hidden = form.querySelector('[data-signature-input]');
    const error = form.querySelector('[data-signature-error]');
    const status = form.querySelector('[data-signature-status]');
    const submit = form.querySelector('[data-signing-submit]');
    const pad = drawingPad(canvas, 4, () => {
        say(error, '');
        say(status, pad.hasInk() ? form.dataset.drawnText ?? '' : '');
    });
    const initialsReady = setUpInitials(form);

    const method = () => form.querySelector('input[name="method"]:checked')?.value ?? 'DRAWN';

    form.querySelector('[data-signature-undo]')?.addEventListener('click', () => pad.undo());
    form.querySelector('[data-signature-clear]')?.addEventListener('click', () => pad.clear());

    const showMethod = () => {
        form.querySelectorAll('[data-method-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.methodPanel !== method();
        });
        say(error, '');
    };

    form.querySelectorAll('input[name="method"]').forEach((radio) => radio.addEventListener('change', showMethod));
    showMethod();

    if (form.id) {
        setUpFields(form);
    }

    form.addEventListener('submit', (event) => {
        if (submit?.dataset.busy === '1') {
            event.preventDefault();

            return;
        }

        const name = form.querySelector('input[name="name"]')?.value ?? '';

        if (method() === 'DRAWN') {
            if (!pad.hasInk()) {
                event.preventDefault();
                say(error, form.dataset.emptyText ?? '');
                pad.focus();

                return;
            }

            hidden.value = pad.dataUrl();
        } else {
            hidden.value = '';
            const typed = form.querySelector('input[name="typed_signature"]')?.value ?? '';

            if (!sameName(typed, name)) {
                event.preventDefault();
                say(error, form.dataset.typedText ?? '');
                form.querySelector('input[name="typed_signature"]')?.focus();

                return;
            }
        }

        if (!initialsReady()) {
            event.preventDefault();

            return;
        }

        if (submit) {
            submit.dataset.busy = '1';
            submit.setAttribute('aria-disabled', 'true');
            submit.textContent = form.dataset.busyText ?? submit.textContent;
        }
    });
}

function guardDecline(form) {
    form.addEventListener('submit', (event) => {
        const button = form.querySelector('button[type="submit"]');

        if (button?.dataset.busy === '1') {
            event.preventDefault();

            return;
        }

        if (button) {
            button.dataset.busy = '1';
            button.setAttribute('aria-disabled', 'true');
        }
    });
}

document.querySelectorAll('[data-signing-form]').forEach(setUp);
document.querySelectorAll('[data-decline-form]').forEach(guardDecline);
// The signer check's forms (ADR-046 S3): "Skicka kod" and "Fortsätt" are sent once; the server also reuses a code sent within the minute.
document.querySelectorAll('[data-once-form]').forEach(guardDecline);
