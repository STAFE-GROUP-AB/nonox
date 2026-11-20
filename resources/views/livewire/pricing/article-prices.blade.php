@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $sources = [
        'FORM' => __('Artikelformuläret'),
        'API' => __('API'),
        'IMPORT' => __('Import'),
        'PRICE_UPDATE' => __('Prisuppdatering'),
    ];
@endphp

<div class="space-y-6">
    <section aria-labelledby="article-base-price">
        <h3 id="article-base-price" class="text-base font-bold tracking-tight text-ink">{{ __('Grundpris') }}</h3>
        <p class="mt-1 text-sm text-gray-700">
            <span class="font-semibold tabular-nums text-gray-900">{{ $article->price_ore->format() }}</span> {{ __('exklusive moms per :unit. Det gäller när ingen prislista eller kampanj ger ett annat pris.', ['unit' => $article->unit]) }}
        </p>
        @if($history->isEmpty())
            <p class="mt-3 text-sm text-gray-500">{{ __('Ingen ändring av grundpriset är sparad ännu. Historiken börjar vid nästa ändring.') }}</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Grundprisets historik') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Från') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Förut') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Nytt pris') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Ändrat via') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($history as $change)
                            <tr wire:key="history-{{ $change->id }}">
                                <td class="py-2 pr-4 tabular-nums">{{ $change->valid_from->toDateString() }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums text-gray-500">{{ $change->old_price_ore === null ? '–' : App\Support\Money\Money::ofOre($change->old_price_ore)->format() }}</td>
                                <td class="py-2 pr-4 text-right font-medium tabular-nums text-gray-900">{{ App\Support\Money\Money::ofOre($change->new_price_ore)->format() }}</td>
                                <td class="py-2 text-gray-700">{{ $sources[$change->source->value] ?? $change->source->value }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section aria-labelledby="article-lists">
        <h3 id="article-lists" class="text-base font-bold tracking-tight text-ink">{{ __('Var artikeln prissätts') }}</h3>
        @if($items->isEmpty() && $followers->isEmpty())
            <p class="mt-1 text-sm text-gray-500">{{ __('Artikeln finns inte i någon prislista eller kampanj. Alla får grundpriset.') }}</p>
        @endif

        @if($items->isNotEmpty())
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Priser för artikeln i prislistor') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Prislista') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Från antal') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Pris') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Gäller') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($items as $item)
                            <tr wire:key="item-{{ $item->id }}">
                                <td class="py-2 pr-4"><a href="{{ route('pricing.lists.show', $item->priceList->id) }}" class="text-accent-700 hover:underline">{{ $item->priceList->code }} · {{ $item->priceList->name }}</a> <x-ui.badge :tone="$item->priceList->kind === App\Domain\Pricing\PriceListKind::Campaign ? 'warning' : 'neutral'" class="ms-1">{{ $item->priceList->kind->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $item->min_quantity_milli === 0 ? '–' : App\Modules\Inventory\Domain\Quantity::format($item->min_quantity_milli) }} <span class="text-gray-500">{{ $item->unit?->code ?? $article->unit }}</span></td>
                                <td class="py-2 pr-4 text-right font-medium tabular-nums text-gray-900">
                                    @if($item->price_minor !== null)
                                        {{ App\Support\Money\Money::ofOre($item->price_minor, $item->priceList->currency)->format() }}
                                    @else
                                        −{{ App\Domain\Pricing\PriceInput::basisPointsToPercent((int) $item->discount_bp) }} %
                                    @endif
                                </td>
                                <td class="py-2 text-gray-700">
                                    <span class="tabular-nums">{{ $item->valid_from === null && $item->valid_to === null ? __('Alltid') : ($item->valid_from?->toDateString() ?? '…').' – '.($item->valid_to?->toDateString() ?? '…') }}</span>
                                    @if($item->valid_from !== null && $item->valid_from->toDateString() > $today)<x-ui.badge tone="warning" class="ms-1">{{ __('Kommande') }}</x-ui.badge>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($followers->isNotEmpty())
            <p class="mt-4 text-sm font-medium text-gray-700">{{ __('Listor som räknar ut priset med en regel') }}</p>
            <ul class="mt-1 text-sm text-gray-700">
                @foreach($followers as $follower)
                    <li wire:key="follower-{{ $follower->id }}"><a href="{{ route('pricing.lists.show', $follower->id) }}" class="text-accent-700 hover:underline">{{ $follower->code }} · {{ $follower->name }}</a>: {{ __('följer :percent %', ['percent' => App\Domain\Pricing\PriceInput::basisPointsToPercent((int) $follower->derived_percent_bp)]) }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="article-lookup">
        <h3 id="article-lookup" class="text-base font-bold tracking-tight text-ink">{{ __('Pris på en dag') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('Priset en kund utan egen lista får, i kronor. Kundens egna priser ser du på kundens flik Priser.') }}</p>
        <div class="mt-3 grid gap-4 sm:grid-cols-6">
            <x-ui.field for="lookup_date" :label="__('Datum')" class="sm:col-span-2">
                <input id="lookup_date" type="date" class="{{ $input }} mt-1" wire:model.live.debounce.400ms="date">
            </x-ui.field>
            <x-ui.field for="lookup_quantity" :label="__('Antal')" class="sm:col-span-2">
                <input id="lookup_quantity" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model.live.debounce.400ms="quantity">
            </x-ui.field>
            <div class="sm:col-span-2" aria-live="polite">
                <p class="block text-sm font-medium text-gray-700">{{ __('Pris') }}</p>
                @if($lookup['error'])
                    <p class="mt-2 text-sm text-gray-600">{{ $lookup['error'] }}</p>
                @else
                    <p class="mt-2 text-lg font-semibold tabular-nums text-gray-900">{{ $lookup['price'] }}</p>
                    <p class="text-xs text-gray-500">{{ $lookup['source'] }}</p>
                @endif
            </div>
        </div>
    </section>
</div>
