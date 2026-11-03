{{-- One fact in the header's strip: a small label over the value, behind a thin rule on the left. --}}
@props(['label', 'wide' => false])

<div {{ $attributes->class(['min-w-0 border-s-2 border-stone-100 ps-3', 'col-span-2 sm:col-span-1' => $wide]) }}>
    <dt class="text-xs font-medium text-gray-500">{{ $label }}</dt>
    <dd class="mt-1 break-words text-sm font-medium text-ink">{{ $slot }}</dd>
</div>
