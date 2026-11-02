@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white', 'dropdownClasses' => ''])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right inset-s-0',
    'top' => 'origin-top',
    'none', 'false' => '',
    default => 'ltr:origin-top-right rtl:origin-top-left inset-e-0',
};

$width = match ($width) {
    '48' => 'w-48',
    '60' => 'w-60',
    default => 'w-48',
};
@endphp

{{--
    A menu button (WCAG 4.1.2, 2.1.1): the trigger's button says whether the
    menu is open, Esc closes it and gives focus back, and it closes when focus
    leaves it.
--}}
<div class="relative"
     x-data="{ open: false, button() { return $refs.trigger.querySelector('button, a'); } }"
     x-init="$nextTick(() => { button()?.setAttribute('aria-haspopup', 'true'); button()?.setAttribute('aria-expanded', 'false'); }); $watch('open', (value) => button()?.setAttribute('aria-expanded', value ? 'true' : 'false'))"
     @click.away="open = false"
     @close.stop="open = false"
     x-on:keydown.escape="if (open) { open = false; button()?.focus(); }"
     x-on:focusout="if ($event.relatedTarget && ! $el.contains($event.relatedTarget)) open = false">
    <div x-ref="trigger" @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
            class="absolute z-50 mt-2 {{ $width }} rounded-2xl shadow-xl {{ $alignmentClasses }} {{ $dropdownClasses }}"
            style="display: none;"
            @click="open = false">
        <div class="overflow-hidden rounded-2xl ring-1 ring-stone-200 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
