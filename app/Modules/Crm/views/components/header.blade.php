{{--
    The header of a CRM record (an account or a person): initials, name,
    a line above it (kicker) and the lines under it, badges, an aside for
    the edit links, the round quick actions and the facts strip. Shared by
    the account card and the person page so they read as one product.
--}}
@props(['name', 'shape' => 'round', 'kicker' => null])

<section {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-3xl bg-white shadow-xs ring-1 ring-stone-200']) }}>
    <div class="pointer-events-none absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-accent-50/80 to-transparent sm:h-32" aria-hidden="true"></div>
    <div class="relative px-5 pb-5 pt-6 sm:px-8 sm:pb-7 sm:pt-8">
        <div class="flex flex-wrap items-start gap-x-5 gap-y-4 sm:flex-nowrap">
            <x-crm::avatar :name="$name" :shape="$shape" />
            <div class="min-w-0 flex-1 basis-48">
                @if($kicker)
                    <p class="text-sm text-gray-600">{{ $kicker }}</p>
                @endif
                <h1 class="mt-0.5 break-words text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ $name }}</h1>
                @isset($subtitle)
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600">{{ $subtitle }}</div>
                @endisset
                @isset($badges)
                    <div class="mt-3 flex flex-wrap items-center gap-2">{{ $badges }}</div>
                @endisset
            </div>
            @isset($aside)
                {{-- On the phone the edit links are a row of their own above the name; on a wide screen a column on the right. --}}
                <div class="order-first -mt-2 flex w-full justify-end gap-x-4 text-sm sm:order-none sm:mt-0 sm:w-auto sm:flex-col sm:items-end sm:gap-1">{{ $aside }}</div>
            @endisset
        </div>

        @isset($actions)
            <div class="-mx-5 mt-6 flex gap-2 overflow-x-auto px-5 pb-1 sm:mx-0 sm:flex-wrap sm:gap-3 sm:overflow-visible sm:px-0" role="group" aria-label="{{ __('Snabbval') }}">
                {{ $actions }}
            </div>
        @endisset

        @isset($facts)
            <dl class="mt-6 grid grid-cols-2 gap-x-4 gap-y-5 border-t border-stone-200 pt-6 sm:grid-cols-3 lg:grid-cols-4">
                {{ $facts }}
            </dl>
        @endisset
    </div>
</section>
