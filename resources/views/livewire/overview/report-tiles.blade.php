<div>
    @if($tiles !== [])
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="{{ __('Sparade rapporter') }}" data-report-tiles>
            @foreach($tiles as $tile)
                @php
                    $format = fn (int $value): string => $tile['money'] ? App\Support\Money\Money::ofOre($value)->format() : number_format($value, 0, ',', "\u{00A0}");
                @endphp
                <a href="{{ route('analysis.index', ['rapport' => $tile['id']]) }}" class="block rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200 transition hover:ring-stone-300 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500" data-report-tile="{{ $tile['id'] }}" wire:key="report-tile-{{ $tile['id'] }}">
                    <span class="block text-sm text-gray-600">{{ $tile['name'] }}</span>
                    <span class="mt-1 block text-2xl font-bold tracking-tight tabular-nums text-ink">
                        @if($tile['tooLarge'])
                            {{ __('För stor') }}
                        @elseif($tile['hidden'])
                            {{ __('Dold') }}
                        @else
                            {{ $format($tile['value']) }}
                        @endif
                    </span>
                    <span class="mt-2 block text-xs text-gray-600">
                        {{ $tile['period'] }}
                        @if($tile['previous'] !== null && ! $tile['hidden'] && ! $tile['tooLarge'])
                            · {{ __('Förra året: :amount', ['amount' => $format($tile['previous'])]) }}
                        @endif
                    </span>
                </a>
            @endforeach
        </section>
    @endif
</div>
