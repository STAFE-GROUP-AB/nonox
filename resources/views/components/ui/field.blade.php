@props(['for', 'label', 'hint' => null, 'error' => null])

<div {{ $attributes }}>
    <label for="{{ $for }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    {{ $slot }}
    @if($hint)
        {{-- Linked to the field by resources/js/accessibility.js (aria-describedby). --}}
        <p id="{{ $for }}-hint" class="mt-1 text-sm text-gray-500" data-hint-for="{{ $for }}">{{ $hint }}</p>
    @endif
    <x-input-error :for="$error ?? $for" :field="$for" class="mt-2" />
</div>
