@props(['title' => null, 'description' => null, 'action' => null])

<section {{ $attributes->merge(['class' => 'rounded-3xl bg-white p-6 shadow-sm ring-1 ring-stone-200 sm:p-8']) }}>
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

    <div @class(['mt-5' => $title || $description])>
        {{ $slot }}
    </div>
</section>
