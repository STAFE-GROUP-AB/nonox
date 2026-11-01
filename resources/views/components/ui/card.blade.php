{{--
    A card. "action" sits to the right of the title (secondary things such as
    Stäng or Visa); "cta" is the card's main call to action, such as Ny
    artikel, and always sits first on the left (docs/design-system.md).
--}}
@props(['title' => null, 'description' => null, 'action' => null, 'cta' => null])

<section {{ $attributes->merge(['class' => 'rounded-3xl bg-white p-6 shadow-xs ring-1 ring-stone-200 sm:p-8']) }}>
    @if($title || $action)
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            @if($title)
                <h2 class="text-lg font-bold tracking-tight text-ink">{{ $title }}</h2>
            @endif
            {{ $action }}
        </div>
    @endif

    @if($description)
        <p class="mt-1 text-sm text-gray-600">{{ $description }}</p>
    @endif

    @if($cta)
        <div @class(['flex flex-wrap items-center gap-3', 'mt-5' => $title || $description])>{{ $cta }}</div>
    @endif

    <div @class(['mt-5' => $title || $description || $cta])>
        {{ $slot }}
    </div>
</section>
