@props(['for', 'label', 'hint' => null, 'error' => null])

<div {{ $attributes }}>
    <label for="{{ $for }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    {{ $slot }}
    @if($hint)
        <p class="mt-1 text-sm text-gray-500">{{ $hint }}</p>
    @endif
    <x-input-error :for="$error ?? $for" class="mt-2" />
</div>
