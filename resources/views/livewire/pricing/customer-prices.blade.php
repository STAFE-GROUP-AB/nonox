@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $warnings = [
        'converted_currency' => __('Omräknat från kronor'),
        'no_price' => __('Pris saknas'),
        'no_exchange_rate' => __('Växelkurs saknas'),
        'unit_not_convertible' => __('Enheten går inte att räkna om'),
        'unknown_article' => __('Okänd artikel'),
    ];
@endphp

<div class="space-y-6">
    <x-ui.card :title="__('Priser för :name', ['name' => $customer->name])" :description="__('Kundens egen prislista går före gruppens. Utan någon av dem gäller grundpriset, om inte en kampanj är billigare.')">
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
            <x-ui.field for="customer_price_group" :label="__('Kundgrupp')" error="price_group_id" class="sm:col-span-3">
                <select id="customer_price_group" class="{{ $input }} mt-1" wire:model="groupId" @disabled(! $canManage)>
                    <option value="">{{ __('Ingen grupp') }}</option>
                    @foreach($groups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="customer_price_list" :label="__('Egen prislista')" error="price_list_id" class="sm:col-span-3">
                <select id="customer_price_list" class="{{ $input }} mt-1" wire:model="listId" @disabled(! $canManage)>
                    <option value="">{{ __('Ingen egen lista') }}</option>
                    @foreach($lists as $list)
                        <option value="{{ $list->id }}">{{ $list->code }} · {{ $list->name }} ({{ $list->currency }})</option>
                    @endforeach
                </select>
            </x-ui.field>
            @if($canManage)
                <div class="sm:col-span-6">
                    <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    @if($groups->isEmpty() && $lists->isEmpty())
                        <span class="ms-3 text-sm text-gray-600">{{ __('Det finns inga prislistor eller kundgrupper ännu.') }} <a href="{{ route('pricing.lists') }}" class="font-medium text-accent-700 underline">{{ __('Gör en prislista') }}</a></span>
                    @endif
                </div>
            @endif
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Prisavtal')" :description="__('Ett aktivt prisavtal går före kundens prislista. Avtal som väntar på underskrift eller är utkast gäller inte ännu.')" data-customer-price-agreements>
        @if($agreements->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Kunden har inget prisavtal som gäller eller är på väg.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($agreements as $priceAgreement)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="customer-agreement-{{ $priceAgreement->id }}">
                        <a href="{{ route('pricing.agreements.show', $priceAgreement->id) }}" class="font-medium text-accent-700 hover:underline">{{ $priceAgreement->reference() }} · {{ $priceAgreement->title }}</a>
                        <span class="flex items-center gap-2">
                            <span class="tabular-nums text-gray-600">{{ $priceAgreement->valid_from->toDateString() }} – {{ $priceAgreement->lastDay()?->toDateString() ?? __('tills vidare') }} {{ $priceAgreement->currency }}</span>
                            <x-ui.badge :tone="$priceAgreement->status->tone()">{{ $priceAgreement->status->label() }}</x-ui.badge>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
        @if($canManageAgreements)
            <p class="mt-4 text-sm"><a href="{{ route('pricing.agreements', ['ny' => 1, 'kund' => $customer->id]) }}" class="font-medium text-accent-700 underline">{{ __('Nytt prisavtal för kunden') }}</a></p>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Kundens pris idag')" :description="__('Så här prissätts en vara i :currency till kunden i dag, vid antal 1. Fakturor, offerter och order får samma pris när artikeln väljs.', ['currency' => $currency])">
        <input type="search" class="{{ $input }} max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök artikel') }}" aria-label="{{ __('Sök artikel') }}">

        @if($articles->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga artiklar')">{{ __('Det finns inga artiklar som matchar. Artiklar läggs upp under Register.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Kundens pris per artikel') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Grundpris') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Kundens pris') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Varifrån') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($articles as $article)
                            @php($price = $resolved[(string) $article->id] ?? null)
                            <tr wire:key="preview-{{ $article->id }}">
                                <td class="py-3 pr-4"><span class="font-semibold tabular-nums text-gray-900">{{ $article->number }}</span> {{ $article->name }}</td>
                                <td class="py-3 pr-4 text-right tabular-nums text-gray-500">{{ $article->price_ore->format() }}</td>
                                <td class="py-3 pr-4 text-right font-medium tabular-nums text-gray-900">
                                    @if($price?->hasPrice())
                                        {{ App\Support\Money\Money::ofOre((int) $price->unitPriceMinor, $price->currency)->format() }}
                                        @if($price->discountBp > 0)
                                            <span class="block text-xs font-normal text-gray-500">−{{ App\Domain\Pricing\PriceInput::basisPointsToPercent($price->discountBp) }} %</span>
                                        @endif
                                    @else
                                        –
                                    @endif
                                </td>
                                <td class="py-3 text-gray-700">
                                    @if($price?->source)
                                        {{ $price->source === App\Domain\Pricing\PriceSource::CustomerList ? __('Kundens eller gruppens prislista') : $price->source->label() }}@if(isset($price->explanation['list_id']) && $listCodes->has($price->explanation['list_id'])) <span class="text-gray-500">({{ $listCodes[$price->explanation['list_id']] }})</span>@endif
                                    @endif
                                    @foreach($price?->warnings ?? [] as $warning)
                                        <x-ui.badge tone="warning" class="ms-1">{{ $warnings[$warning] ?? $warning }}</x-ui.badge>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $articles->links() }}</div>
        @endif
    </x-ui.card>
</div>
