@props(['for', 'field' => null])

{{--
    A field's error. It is read aloud when it appears (role="alert"), and
    resources/js/accessibility.js links it to its field: aria-invalid and
    aria-describedby on the input whose id is `field`, or else on the input
    bound to the same key with wire:model or name.
--}}
@error($for)
    <p {{ $attributes->merge(['class' => 'text-sm text-red-600', 'id' => 'error-'.Str::slug(str_replace(['.', '_'], '-', $for)), 'role' => 'alert', 'data-error-for' => $for, 'data-error-field' => $field]) }}>{{ $message }}</p>
@enderror
