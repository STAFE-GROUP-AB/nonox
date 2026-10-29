@props(['title' => null, 'description' => null, 'action' => null])

<section {{ $attributes->merge(['class' => 'bg-white border border-gray-200 rounded-xl p-6 sm:p-8']) }}>
    @if($title || $action)
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            @if($title)
                <h2 class="text-lg font-semibold text-gray-900">{{ $title }}</h2>
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
