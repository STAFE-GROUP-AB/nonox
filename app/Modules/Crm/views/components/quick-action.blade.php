{{--
    A round quick action under the header: an icon in a circle and a short
    label. A link (href) or a button (wire:click through the attributes).
    disabled is the reason it cannot be used: the control stays a button with
    aria-disabled, described by the page's visible note under the actions
    (describedby, the header's crm-actions-note), and does nothing.
--}}
@props(['icon' => 'note', 'label', 'href' => null, 'disabled' => null, 'primary' => false, 'describedby' => 'crm-actions-note'])

@php
    $circle = $primary
        ? 'bg-ink text-white group-hover:bg-black'
        : 'bg-stone-100 text-ink ring-1 ring-stone-200 group-hover:bg-white group-hover:ring-stone-300';
@endphp

@if($disabled)
    {{-- Not usable: a real button for the keyboard and screen readers, aria-disabled, described by the reason shown under the row (no hover needed). --}}
    <button type="button" aria-disabled="true" aria-describedby="{{ $describedby }}" title="{{ $disabled }}" class="group inline-flex w-[4.25rem] flex-none cursor-not-allowed flex-col items-center gap-1.5 rounded-2xl text-center opacity-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-600 sm:w-[4.5rem]" data-quick-action="{{ $label }}">
        <span class="inline-flex size-12 items-center justify-center rounded-full bg-stone-100 text-gray-500 ring-1 ring-stone-200"><x-crm::icon :name="$icon" /></span>
        <span class="text-xs font-medium leading-tight text-gray-600">{{ $label }}</span>
    </button>
@elseif($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'group inline-flex w-[4.25rem] sm:w-[4.5rem] flex-none flex-col items-center gap-1.5 rounded-2xl text-center focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-600']) }} data-quick-action="{{ $label }}">
        <span class="inline-flex size-12 items-center justify-center rounded-full motion-safe:transition-colors {{ $circle }}"><x-crm::icon :name="$icon" /></span>
        <span class="text-xs font-medium leading-tight text-ink">{{ $label }}</span>
    </a>
@else
    <button type="button" {{ $attributes->merge(['class' => 'group inline-flex w-[4.25rem] sm:w-[4.5rem] flex-none flex-col items-center gap-1.5 rounded-2xl text-center focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-600']) }} data-quick-action="{{ $label }}">
        <span class="inline-flex size-12 items-center justify-center rounded-full motion-safe:transition-colors {{ $circle }}"><x-crm::icon :name="$icon" /></span>
        <span class="text-xs font-medium leading-tight text-ink">{{ $label }}</span>
    </button>
@endif
