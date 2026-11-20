@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $mode = $form['mode'] ?? 'price';
@endphp

<div class="space-y-6">
    <x-ui.card>
        <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('Typ') }}</dt><dd class="font-medium text-gray-900">{{ $list->kind->label() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Valuta') }}</dt><dd class="font-medium text-gray-900">{{ $list->currency }}, {{ $list->prices_include_vat ? __('inklusive moms') : __('exklusive moms') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Giltighet') }}</dt><dd class="font-medium tabular-nums text-gray-900">{{ $list->valid_from === null && $list->valid_to === null ? __('Alltid') : ($list->valid_from?->toDateString() ?? '…').' – '.($list->valid_to?->toDateString() ?? '…') }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Standardlista') }}</dt><dd class="font-medium text-gray-900">{{ $list->is_default_for_currency ? __('Ja, för :currency', ['currency' => $list->currency]) : __('Nej') }}</dd></div>
        </dl>
        @if($list->derived_percent_bp !== null)
            <p class="mt-4 rounded-xl bg-accent-50 px-4 py-3 text-sm text-accent-900">
                {{ __('Den här listan följer :source :percent %', ['source' => $list->basedOn ? $list->basedOn->code.' '.$list->basedOn->name : __('grundpriset'), 'percent' => App\Domain\Pricing\PriceInput::basisPointsToPercent($list->derived_percent_bp)]) }}@if($list->rounding_rule !== App\Domain\Pricing\PriceRounding::None), {{ mb_strtolower($list->rounding_rule->label()) }}@endif.
                {{ __('Priserna nedan går före regeln för just de artiklarna.') }}
            </p>
        @endif
        @if($list->archived_at)
            <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('Listan är arkiverad. Återställ den under Prislistor för att ändra priser.') }}</p>
        @endif
        <p class="mt-4 text-sm"><a href="{{ route('pricing.lists') }}" class="font-medium text-accent-700 underline">{{ __('Tillbaka till prislistor') }}</a></p>
    </x-ui.card>

    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra pris') : __('Nytt pris')">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-6">
                    <label for="item_article_search" class="block text-sm font-medium text-gray-700">{{ __('Artikel') }}</label>
                    @if($article)
                        <p class="mt-1 flex items-center gap-3 text-sm text-gray-900">
                            <span><span class="font-semibold tabular-nums">{{ $article->number }}</span> {{ $article->name }} <span class="text-gray-500">({{ __('grundpris :price', ['price' => $article->price_ore->format()]) }} / {{ $article->unit }})</span></span>
                            @if(! $editingId && ! $replacesId)
                                <button type="button" class="text-sm text-accent-700 underline" wire:click="clearArticle">{{ __('Byt artikel') }}</button>
                            @endif
                        </p>
                    @else
                        <input id="item_article_search" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.250ms="articleSearch" placeholder="{{ __('Sök på artikelnummer eller benämning') }}" autocomplete="off" aria-describedby="item_article_results">
                        <ul id="item_article_results" class="mt-1 divide-y divide-gray-100 rounded-lg ring-1 ring-stone-200" @if($articleMatches->isEmpty()) hidden @endif>
                            @foreach($articleMatches as $match)
                                <li wire:key="match-{{ $match->id }}">
                                    <button type="button" class="flex w-full items-center justify-between gap-4 px-3 py-2 text-left text-sm hover:bg-stone-50 focus-visible:bg-stone-50" wire:click="chooseArticle({{ $match->id }})">
                                        <span><span class="font-semibold tabular-nums">{{ $match->number }}</span> {{ $match->name }}</span>
                                        <span class="tabular-nums text-gray-500">{{ $match->price_ore->format() }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <x-input-error for="article_id" :field="'article_id'" class="mt-2" />
                </div>

                @if($article)
                    <x-ui.field for="item_unit" :label="__('Enhet')" error="unit_id" class="sm:col-span-2" :hint="__('Tomt är artikelns egen försäljningsenhet.')">
                        <select id="item_unit" class="{{ $input }} mt-1" wire:model="form.unit_id">
                            <option value="">{{ __('Artikelns enhet (:unit)', ['unit' => $article->unit]) }}</option>
                            @foreach($unitChoices as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }} ({{ $unit->name }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="item_min" :label="__('Från antal')" error="min_quantity" class="sm:col-span-2" :hint="__('Tomt gäller från första enheten. Flera rader med olika antal blir en trappa.')">
                        <input id="item_min" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.min_quantity" placeholder="10">
                    </x-ui.field>
                    <div class="sm:col-span-2">
                        <label for="item_mode" class="block text-sm font-medium text-gray-700">{{ __('Ange') }}</label>
                        <select id="item_mode" class="{{ $input }} mt-1" wire:model.live="form.mode">
                            <option value="price">{{ __('Pris i :currency', ['currency' => $list->currency]) }}</option>
                            <option value="discount">{{ __('Rabatt på ordinarie pris') }}</option>
                        </select>
                    </div>
                    @if($mode === 'price')
                        <x-ui.field for="item_price" :label="$list->prices_include_vat ? __('Pris inkl. moms') : __('Pris exkl. moms')" error="price" class="sm:col-span-2">
                            <input id="item_price" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.price" placeholder="149,00">
                        </x-ui.field>
                    @else
                        <x-ui.field for="item_discount" :label="__('Rabatt, procent')" error="discount" class="sm:col-span-2" :hint="__('Ordinarie pris är standardlistans pris för valutan, annars artikelns grundpris. En lista som följer en annan lista tar den listans pris.')">
                            <input id="item_discount" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.discount" placeholder="12,5">
                        </x-ui.field>
                    @endif
                    <x-ui.field for="item_from" :label="__('Gäller från (valfritt)')" error="valid_from" class="sm:col-span-2">
                        <input id="item_from" type="date" class="{{ $input }} mt-1" wire:model="form.valid_from">
                    </x-ui.field>
                    <x-ui.field for="item_to" :label="__('Gäller till och med (valfritt)')" error="valid_to" class="sm:col-span-2">
                        <input id="item_to" type="date" class="{{ $input }} mt-1" wire:model="form.valid_to">
                    </x-ui.field>
                @endif

                <div class="flex items-center gap-4 sm:col-span-6">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($canManage && ! $showForm && ! $list->archived_at)
                <x-button type="button" wire:click="create">{{ __('Nytt pris') }}</x-button>
            @endif
            <input type="search" class="{{ $input }} max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök artikel') }}" aria-label="{{ __('Sök artikel i listan') }}">
            <select class="{{ $input }} max-w-48" wire:model.live="show" aria-label="{{ __('Visa priser') }}">
                <option value="aktuella">{{ __('Gäller nu och kommande') }}</option>
                <option value="kommande">{{ __('Bara kommande') }}</option>
                <option value="avslutade">{{ __('Avslutade') }}</option>
                <option value="alla">{{ __('Alla') }}</option>
            </select>
        </div>

        @if($items->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$search !== '' || $show !== 'aktuella' ? __('Inga priser matchar') : __('Inga priser i listan ännu')">
                @if($search !== '' || $show !== 'aktuella')
                    {{ __('Ändra sökningen eller visa alla priser.') }}
                @elseif($list->derived_percent_bp !== null)
                    {{ __('Listan räknar ut sina priser av regeln ovan. Lägg bara in ett pris här för en artikel som ska avvika.') }}
                @else
                    {{ __('Lägg in ett pris per artikel. Artiklar utan pris här får grundpriset. Flera rader för samma artikel med olika "från antal" blir en mängdrabatt, och med datum kan du förbereda en prishöjning.') }}
                @endif
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Priser i prislistan') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium" aria-sort="{{ $sort === 'artikel' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button type="button" class="font-medium uppercase tracking-wide hover:text-gray-800" wire:click="sortBy('artikel')">{{ __('Artikel') }}@if($sort === 'artikel') <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif</button></th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Från antal') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium" aria-sort="{{ $sort === 'pris' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button type="button" class="font-medium uppercase tracking-wide hover:text-gray-800" wire:click="sortBy('pris')">{{ __('Pris') }}@if($sort === 'pris') <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif</button></th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Grundpris') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium" aria-sort="{{ $sort === 'fran' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}"><button type="button" class="font-medium uppercase tracking-wide hover:text-gray-800" wire:click="sortBy('fran')">{{ __('Gäller') }}@if($sort === 'fran') <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif</button></th>
                            <th scope="col" class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($items as $item)
                            @php
                                $future = $item->valid_from !== null && $item->valid_from->toDateString() > $today;
                                $archived = $item->archived_at !== null;
                                $ended = $archived || ($item->valid_to !== null && $item->valid_to->toDateString() < $today);
                                $startsToday = $item->valid_from !== null && $item->valid_from->toDateString() === $today;
                            @endphp
                            <tr wire:key="item-{{ $item->id }}">
                                <td class="py-3 pr-4"><span class="font-semibold tabular-nums text-gray-900">{{ $item->article->number }}</span> {{ $item->article->name }}</td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ $item->min_quantity_milli === 0 ? '–' : App\Modules\Inventory\Domain\Quantity::format($item->min_quantity_milli) }} <span class="text-gray-500">{{ $item->unit?->code ?? $item->article->unit }}</span></td>
                                <td class="py-3 pr-4 text-right font-medium tabular-nums text-gray-900">
                                    @if($item->price_minor !== null)
                                        {{ App\Support\Money\Money::ofOre($item->price_minor, $list->currency)->format() }}
                                    @else
                                        −{{ App\Domain\Pricing\PriceInput::basisPointsToPercent((int) $item->discount_bp) }} %
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums text-gray-500">{{ $item->article->price_ore->format() }}</td>
                                <td class="py-3 pr-4 text-gray-700">
                                    <span class="tabular-nums">{{ $item->valid_from === null && $item->valid_to === null ? __('Alltid') : ($item->valid_from?->toDateString() ?? '…').' – '.($item->valid_to?->toDateString() ?? '…') }}</span>
                                    @if($future)<x-ui.badge tone="warning" class="ms-1">{{ __('Kommande') }}</x-ui.badge>@elseif($archived)<x-ui.badge class="ms-1">{{ __('Arkiverad') }}</x-ui.badge>@elseif($ended)<x-ui.badge class="ms-1">{{ __('Avslutad') }}</x-ui.badge>@endif
                                </td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if($canManage && ! $list->archived_at && ! $ended)
                                        @if($future)
                                            <button type="button" class="text-sm font-medium text-accent-700 hover:underline" wire:click="edit({{ $item->id }})">{{ __('Ändra') }}</button>
                                            <button type="button" class="ms-3 text-sm font-medium text-gray-700 underline" wire:click="retire({{ $item->id }})" wire:confirm="{{ __('Ta bort priset? Det har inte börjat gälla.') }}">{{ __('Ta bort') }}</button>
                                        @else
                                            <button type="button" class="text-sm font-medium text-accent-700 hover:underline" wire:click="raise({{ $item->id }})">{{ __('Nytt pris från datum') }}</button>
                                            <button type="button" class="ms-3 text-sm font-medium text-gray-700 underline" wire:click="retire({{ $item->id }})" wire:confirm="{{ $startsToday ? __('Avsluta priset? Det började gälla i dag, så det arkiveras och finns kvar i historiken under Avslutade.') : __('Avsluta priset? Det gäller till och med i går och finns kvar i historiken, så du kan se vad det kostade.') }}">{{ __('Avsluta') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </x-ui.card>
</div>
