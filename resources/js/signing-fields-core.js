/*
 * The field list of the public signing page (ADR-046 amendment, gap 2, S6),
 * without the DOM so it can be tested in Node: what a field's box over the
 * page shows while the signer types, and which required field is the next
 * one still empty ("Nästa obligatoriska fält"). The server checks every
 * value again; this only helps the signer.
 */

/** The text shown in a field's box: the value, an X for a ticked box or the chosen radio button, else the placeholder. */
export function preview(control, placeholder) {
    if (control.type === 'checkbox' || control.type === 'radio') {
        return control.checked ? 'X' : placeholder;
    }

    const value = String(control.value ?? '').trim();

    return value === '' ? placeholder : value;
}

/** Whether a required control still lacks a value (a radio group counts as filled when one of its buttons is chosen). */
export function isEmpty(control, all) {
    if (control.type === 'radio') {
        return !all.some((other) => other.type === 'radio' && other.name === control.name && other.checked);
    }

    if (control.type === 'checkbox') {
        return !control.checked;
    }

    return String(control.value ?? '').trim() === '';
}

/** The next required control that is empty, after the one that has focus (wrapping around), or null. */
export function nextRequired(controls, current) {
    const required = controls.filter((control) => control.required && isEmpty(control, controls));

    if (required.length === 0) {
        return null;
    }

    const start = current === null ? -1 : controls.indexOf(current);

    return required.find((control) => controls.indexOf(control) > start) ?? required[0];
}
