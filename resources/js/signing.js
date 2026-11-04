/*
 * The public signing page (/signera/…, ADR-046): a signature pad on a canvas
 * for mouse, touch and stylus (Pointer Events), with undo and clear, and the
 * keyboard alternative of typing one's name. A page of its own: no Alpine,
 * no Livewire, no inline script, so the page's content security policy can
 * allow scripts from NONOX only. The model is in signature-pad-core.js.
 */
import { clear, createPad, end, hasSignature, move, sameName, start, toCanvas, undo } from './signature-pad-core.js';

function setUp(form) {
    const canvas = form.querySelector('[data-signature-canvas]');
    const context = canvas?.getContext('2d');
    const hidden = form.querySelector('[data-signature-input]');
    const error = form.querySelector('[data-signature-error]');
    const status = form.querySelector('[data-signature-status]');
    const submit = form.querySelector('[data-signing-submit]');
    const pad = createPad();
    let drawing = false;

    const method = () => form.querySelector('input[name="method"]:checked')?.value ?? 'DRAWN';

    const say = (element, text) => {
        if (element) {
            element.textContent = text;
            element.hidden = text === '';
        }
    };

    const redraw = () => {
        if (!context) {
            return;
        }

        context.clearRect(0, 0, canvas.width, canvas.height);
        context.lineWidth = 4;
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

        say(status, hasSignature(pad) ? form.dataset.drawnText ?? '' : '');
    };

    const point = (event) => toCanvas(event.clientX, event.clientY, canvas.getBoundingClientRect(), canvas.width, canvas.height);

    if (canvas && context) {
        canvas.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            drawing = true;
            canvas.setPointerCapture?.(event.pointerId);
            start(pad, point(event));
            say(error, '');
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

        form.querySelector('[data-signature-undo]')?.addEventListener('click', () => {
            undo(pad);
            redraw();
        });

        form.querySelector('[data-signature-clear]')?.addEventListener('click', () => {
            clear(pad);
            redraw();
        });
    }

    const showMethod = () => {
        form.querySelectorAll('[data-method-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.methodPanel !== method();
        });
        say(error, '');
    };

    form.querySelectorAll('input[name="method"]').forEach((radio) => radio.addEventListener('change', showMethod));
    showMethod();

    form.addEventListener('submit', (event) => {
        if (submit?.dataset.busy === '1') {
            event.preventDefault();

            return;
        }

        const name = form.querySelector('input[name="name"]')?.value ?? '';

        if (method() === 'DRAWN') {
            if (!hasSignature(pad)) {
                event.preventDefault();
                say(error, form.dataset.emptyText ?? '');
                canvas?.focus();

                return;
            }

            hidden.value = canvas.toDataURL('image/png');
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
