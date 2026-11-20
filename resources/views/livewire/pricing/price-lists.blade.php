@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $kind = $form['kind'] ?? 'STANDARD';
@endphp

<div class="space-y-6">
    @if($showForm)
        <x-ui.card :title="$editingId ? __('Ändra prislista') : __('Ny prislista')">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-6">
                <x-ui.field for="list_code" :label="__('Kod')" error="code" class="sm:col-span-2" :hint="__('Kort och unik, till exempel ATER eller SOMMAR.')">
                    <input id="list_code" type="text" class="{{ $input }} mt-1 uppercase" wire:model="form.code" maxlength="20" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="list_name" :label="__('Namn')" error="name" class="sm:col-span-4">
                    <input id="list_name" type="text" class="{{ $input }} mt-1" wire:model="form.name" maxlength="100" placeholder="{{ __('Till exempel: Återförsäljare, Sommarkampanj') }}">
                </x-ui.field>

                <x-ui.field for="list_kind" :label="__('Typ')" error="kind" class="sm:col-span-2">
                    <select id="list_kind" class="{{ $input }} mt-1" wire:model.live="form.kind">
                        @foreach($kinds as $each)
                            <option value="{{ $each->value }}">{{ $each->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="list_currency" :label="__('Valuta')" error="currency" class="sm:col-span-2" :hint="__('Tre bokstäver, till exempel SEK eller EUR.')">
                    <input id="list_currency" type="text" class="{{ $input }} mt-1 uppercase" wire:model="form.currency" maxlength="3" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="list_vat" :label="__('Priserna anges')" error="prices_include_vat" class="sm:col-span-2">
                    <select id="list_vat" class="{{ $input }} mt-1" wire:model="form.prices_include_vat">
                        <option value="0">{{ __('Exklusive moms') }}</option>
                        <option value="1">{{ __('Inklusive moms') }}</option>
                    </select>
                </x-ui.field>

                <x-ui.field for="list_from" :label="$kind === 'CAMPAIGN' ? __('Gäller från') : __('Gäller från (valfritt)')" error="valid_from" class="sm:col-span-3">
                    <input id="list_from" type="date" class="{{ $input }} mt-1" wire:model="form.valid_from">
                </x-ui.field>
                <x-ui.field for="list_to" :label="$kind === 'CAMPAIGN' ? __('Gäller till och med') : __('Gäller till och med (valfritt)')" error="valid_to" class="sm:col-span-3">
                    <input id="list_to" type="date" class="{{ $input }} mt-1" wire:model="form.valid_to">
                </x-ui.field>

                @if($kind === 'CHANNEL')
                    <x-ui.field for="list_channel" :label="__('Gäller för')" error="channel" class="sm:col-span-3">
                        <select id="list_channel" class="{{ $input }} mt-1" wire:model="form.channel">
                            <option value="POS">{{ __('Kassan') }}</option>
                            <option value="PORTAL">{{ __('Kundportalen') }}</option>
                        </select>
                    </x-ui.field>
                @endif

                @if($kind === 'CAMPAIGN')
                    <fieldset class="sm:col-span-6">
                        <legend class="text-sm font-medium text-gray-700">{{ __('Kampanjen gäller för') }}</legend>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Välj inga grupper om kampanjen gäller alla kunder.') }}</p>
                        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                            @forelse($groupChoices as $group)
                                <label class="flex items-center gap-2 text-sm text-gray-800">
                                    <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.group_ids" value="{{ $group->id }}">
                                    {{ $group->name }}
                                </label>
                            @empty
                                <span class="text-sm text-gray-500">{{ __('Det finns inga kundgrupper ännu. Kampanjen gäller alla kunder.') }}</span>
                            @endforelse
                        </div>
                        <x-input-error for="group_ids" :field="'group_ids'" class="mt-2" />
                    </fieldset>
                @endif

                @if($kind !== 'CAMPAIGN')
                    <label class="flex items-start gap-3 sm:col-span-6">
                        <input type="checkbox" class="mt-1 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="form.derived">
                        <span class="text-sm text-gray-800">{{ __('Låt listan följa ett annat pris') }}
                            <span class="block text-xs text-gray-500">{{ __('Priserna räknas då ut av grundpriset eller en annan lista med ett påslag eller en sänkning, så de följer med när grundpriset ändras. Priser du lägger in i listan går före regeln.') }}</span>
                        </span>
                    </label>
                @endif

                @if($kind !== 'CAMPAIGN' && ($form['derived'] ?? false))
                    <x-ui.field for="list_based_on" :label="__('Följer')" error="based_on_list_id" class="sm:col-span-2">
                        <select id="list_based_on" class="{{ $input }} mt-1" wire:model="form.based_on_list_id">
                            <option value="">{{ __('Grundpriset på artikeln') }}</option>
                            @foreach($basisChoices as $choice)
                                <option value="{{ $choice->id }}">{{ $choice->code }} · {{ $choice->name }} ({{ $choice->currency }})</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="list_percent" :label="__('Procent')" error="derived_percent" class="sm:col-span-2" :hint="__('−12 ger 12 % lägre pris, 5 ger 5 % högre.')">
                        <input id="list_percent" type="text" inputmode="decimal" class="{{ $input }} mt-1 text-right tabular-nums" wire:model="form.derived_percent" placeholder="-12">
                    </x-ui.field>
                    <x-ui.field for="list_rounding" :label="__('Avrundning')" error="rounding_rule" class="sm:col-span-2">
                        <select id="list_rounding" class="{{ $input }} mt-1" wire:model="form.rounding_rule">
                            @foreach($roundings as $rounding)
                                <option value="{{ $rounding->value }}">{{ $rounding->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif

                @if($kind === 'STANDARD')
                    <label class="flex items-start gap-3 sm:col-span-6">
                        <input type="checkbox" class="mt-1 rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model="form.is_default_for_currency">
                        <span class="text-sm text-gray-800">{{ __('Standardlista för valutan') }}
                            <span class="block text-xs text-gray-500">{{ __('Används för kunder som inte har någon egen lista, i listans valuta. Bara en lista per valuta kan vara standard; väljer du den här tas den andra bort som standard.') }}</span>
                        </span>
                    </label>
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
            @if($canManage && ! $showForm)
                <x-button type="button" wire:click="create">{{ __('Ny prislista') }}</x-button>
            @endif
            <a href="{{ route('pricing.groups') }}" class="text-sm font-medium text-accent-700 underline">{{ __('Kundgrupper') }}</a>
            <input type="search" class="{{ $input }} max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på kod eller namn') }}" aria-label="{{ __('Sök prislista') }}">
            <select class="{{ $input }} max-w-44" wire:model.live="kind" aria-label="{{ __('Visa typ') }}">
                <option value="">{{ __('Alla typer') }}</option>
                @foreach($kinds as $each)
                    <option value="{{ $each->value }}">{{ $each->label() }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" class="rounded-sm border-gray-300 text-accent-600 focus:ring-accent-500" wire:model.live="archived">
                {{ __('Visa arkiverade') }}
            </label>
        </div>

        @if($lists->isEmpty())
            <x-ui.empty-state class="mt-6" :title="($search !== '' || $kind !== '' || $archived) ? __('Ingen prislista matchar') : __('Inga prislistor ännu')">
                @if($search !== '' || $kind !== '' || $archived)
                    {{ __('Ändra sökningen eller filtret.') }}
                @else
                    {{ __('Utan prislistor gäller artikelns pris för alla. Gör en prislista när en grupp kunder ska ha egna priser, när priserna ska gälla i en annan valuta, eller för en kampanj. Börja med en lista som följer grundpriset minus en procent, så behöver du inte lägga in något pris för hand.') }}
                @endif
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('Prislistor') }}</caption>
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            @foreach(['code' => __('Kod'), 'name' => __('Namn'), 'kind' => __('Typ'), 'currency' => __('Valuta'), 'valid_from' => __('Giltighet')] as $column => $label)
                                <th scope="col" class="py-2 pr-4 font-medium" aria-sort="{{ $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                    <button type="button" class="font-medium uppercase tracking-wide hover:text-gray-800" wire:click="sortBy('{{ $column }}')">{{ $label }}@if($sort === $column) <span aria-hidden="true">{{ $direction === 'asc' ? '↑' : '↓' }}</span>@endif</button>
                                </th>
                            @endforeach
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Priser') }}</th>
                            <th scope="col" class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($lists as $list)
                            @php
                                $running = ($list->valid_from === null || $list->valid_from->lessThanOrEqualTo($today)) && ($list->valid_to === null || $list->valid_to->greaterThanOrEqualTo($today));
                            @endphp
                            <tr wire:key="list-{{ $list->id }}">
                                <td class="py-3 pr-4 font-semibold tabular-nums text-gray-900">{{ $list->code }}</td>
                                <td class="py-3 pr-4">
                                    <a href="{{ route('pricing.lists.show', $list->id) }}" class="font-medium text-accent-700 hover:underline">{{ $list->name }}</a>
                                    <span class="block text-xs text-gray-500">
                                        @if($list->derived_percent_bp !== null)
                                            {{ __('Följer :source :percent %', ['source' => $list->basedOn ? $list->basedOn->code : __('grundpriset'), 'percent' => App\Domain\Pricing\PriceInput::basisPointsToPercent($list->derived_percent_bp)]) }}@if($list->rounding_rule !== App\Domain\Pricing\PriceRounding::None), {{ mb_strtolower($list->rounding_rule->label()) }}@endif
                                        @endif
                                        @if($list->kind === App\Domain\Pricing\PriceListKind::Campaign)
                                            {{ $list->priceGroups->isEmpty() ? __('För alla kunder') : __('För :groups', ['groups' => $list->priceGroups->pluck('name')->implode(', ')]) }}
                                        @endif
                                        @if($list->channel)
                                            {{ $list->channel === App\Domain\Pricing\PriceChannel::Pos ? __('Kassan') : __('Kundportalen') }}
                                        @endif
                                    </span>
                                </td>
                                <td class="py-3 pr-4">
                                    <x-ui.badge :tone="$list->kind === App\Domain\Pricing\PriceListKind::Campaign ? 'warning' : 'neutral'">{{ $list->kind->label() }}</x-ui.badge>
                                    @if($list->is_default_for_currency)
                                        <x-ui.badge tone="accent" class="ms-1">{{ __('Standard') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 tabular-nums">{{ $list->currency }}<span class="block text-xs text-gray-500">{{ $list->prices_include_vat ? __('inkl. moms') : __('exkl. moms') }}</span></td>
                                <td class="py-3 pr-4 text-gray-700">
                                    @if($list->valid_from === null && $list->valid_to === null)
                                        {{ __('Alltid') }}
                                    @else
                                        <span class="tabular-nums">{{ $list->valid_from?->toDateString() ?? '…' }} – {{ $list->valid_to?->toDateString() ?? '…' }}</span>
                                        @if($list->kind === App\Domain\Pricing\PriceListKind::Campaign)
                                            <x-ui.badge :tone="$running ? 'success' : 'neutral'" class="ms-1">{{ $running ? __('Pågår') : ($list->valid_from?->greaterThan($today) ? __('Kommande') : __('Avslutad')) }}</x-ui.badge>
                                        @endif
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-right tabular-nums">{{ $list->item_count }}</td>
                                <td class="py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('pricing.lists.show', $list->id) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Priser') }}</a>
                                    @if($canManage)
                                        <button type="button" class="ms-3 text-sm font-medium text-accent-700 hover:underline" wire:click="edit({{ $list->id }})">{{ __('Ändra') }}</button>
                                        @if($list->archived_at)
                                            <button type="button" class="ms-3 text-sm font-medium text-gray-700 underline" wire:click="restore({{ $list->id }})">{{ __('Återställ') }}</button>
                                        @else
                                            <button type="button" class="ms-3 text-sm font-medium text-gray-700 underline" wire:click="archive({{ $list->id }})" wire:confirm="{{ __('Arkivera prislistan? Den används inte längre för nya priser, men dokument som redan har ett pris från den ändras inte. Du kan återställa den senare.') }}">{{ __('Arkivera') }}</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $lists->links() }}</div>
        @endif
    </x-ui.card>
</div>
