{{--
    The kind of a date on Mina datum (App\Domain\Deadlines\DeadlineCategory):
    an icon and a word, one colour per kind, so colour is never the only cue.
    With :recommendation the sky "Rekommendation" chip instead.
--}}
@props(['category' => null, 'recommendation' => false])

@php
    $classes = $recommendation ? App\Domain\Deadlines\DeadlineCategory::RECOMMENDATION_CLASSES : $category->chipClasses();
    $icon = $recommendation ? App\Domain\Deadlines\DeadlineCategory::RECOMMENDATION_ICON : $category->icon();
    $label = $recommendation ? __('Rekommendation') : $category->label();
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ring-1', $classes]) }} data-deadline-chip="{{ $recommendation ? 'rekommendation' : $category->value }}">
    <svg class="size-3.5 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}" /></svg>
    {{ $label }}
</span>
