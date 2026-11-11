@php
    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli);
@endphp
<div class="space-y-6">
    @if($starting)
        <x-ui.card :title="__('Ny inventering')" :description="__('Systemets antal sparas när du startar. Det du räknar jämförs med det.')">
            <form wire:submit="begin" class="grid gap-5 sm:grid-cols-6">
                <x-ui.field for="count-location" :label="__('Vad räknas')" error="form.location_id" class="sm:col-span-3" :hint="__('En plats tar med allt under den.')">
                    <select id="count-location" wire:model="form.location_id" class="{{ $input }}">
                        <option value="">{{ __('Hela lagret') }}</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="count-date" :label="__('Datum')" error="form.date" class="sm:col-span-1">
                    <input id="count-date" type="date" wire:model="form.date" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="count-note" :label="__('Anteckning')" error="form.note" class="sm:col-span-2">
                    <input id="count-note" wire:model="form.note" maxlength="255" class="{{ $input }}" placeholder="{{ __('Till exempel: bokslut') }}">
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Starta inventering') }}</x-button>
                    <x-secondary-button type="button" wire:click="close">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($current)
        <x-ui.card :title="__('Inventering :number', ['number' => $current->number])" :description="($current->location?->full_code ?? __('Hela lagret')).' · '.$current->counted_on->toDateString().' · '.$current->statusLabel()">
            <x-slot:action>
                <button type="button" wire:click="close" class="text-sm font-semibold text-gray-700 underline">{{ __('Stäng') }}</button>
            </x-slot:action>

            @if($currentLines->isEmpty())
                <x-ui.empty-state :title="__('Inga varor på platsen enligt systemet')">{{ __('Lägg till det du hittar på hyllan nedan.') }}</x-ui.empty-state>
            @else
                <ul class="space-y-3">
                    @foreach($currentLines as $line)
                        @php($difference = $line->differenceMilli())
                        <li class="grid gap-3 rounded-2xl bg-stone-50 p-4 sm:grid-cols-12 sm:items-end" wire:key="count-line-{{ $line->id }}">
                            <span class="sm:col-span-5">
                                <span class="font-bold tabular-nums">{{ $line->location?->full_code }}</span>
                                <span class="block"><span class="font-semibold tabular-nums">{{ $line->article?->number }}</span> {{ $line->article?->name }}</span>
                                <span class="block text-sm text-gray-600">{{ __('Systemet: :quantity :unit', ['quantity' => $q($line->system_milli), 'unit' => $line->article?->stockUnitCode()]) }}
                                    @if($difference !== null && $difference !== 0)<span class="font-semibold text-accent-700"> · {{ __('Skillnad :difference', ['difference' => ($difference > 0 ? '+' : '').$q($difference)]) }}</span>@endif
                                </span>
                            </span>
                            @if($current->status === 'OPEN' && $canCount)
                                <x-ui.field for="counted-{{ $line->id }}" :label="__('Räknat')" error="lines.{{ $line->id }}.counted" class="sm:col-span-3">
                                    <input id="counted-{{ $line->id }}" wire:model="lines.{{ $line->id }}.counted" wire:change="saveLine({{ $line->id }})" inputmode="decimal" class="{{ $input }} min-h-11 text-right text-lg tabular-nums">
                                </x-ui.field>
                                <x-ui.field for="reason-{{ $line->id }}" :label="__('Orsak vid skillnad')" error="lines.{{ $line->id }}.reason" class="sm:col-span-4">
                                    <select id="reason-{{ $line->id }}" wire:model="lines.{{ $line->id }}.reason" wire:change="saveLine({{ $line->id }})" class="{{ $input }}">
                                        <option value="">{{ __('Ingen skillnad') }}</option>
                                        @foreach($reasons as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </x-ui.field>
                            @else
                                <span class="text-right tabular-nums sm:col-span-7">{{ $line->counted_milli === null ? __('Inte räknad') : __('Räknat :quantity', ['quantity' => $q($line->counted_milli)]) }} @if($line->reason)· {{ $reasons[$line->reason] ?? $line->reason }}@endif</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($current->status === 'OPEN' && $canCount)
                <form wire:submit="addLine" class="mt-6 grid gap-3 border-t border-stone-200 pt-4 sm:grid-cols-12 sm:items-end">
                    <x-ui.field for="extra-article" :label="__('Hittat något mer?')" error="extra.article_id" class="sm:col-span-5">
                        <select id="extra-article" wire:model="extra.article_id" class="{{ $input }}">
                            <option value="">{{ __('Välj artikel') }}</option>
                            @foreach($articles as $article)
                                <option value="{{ $article->id }}">{{ $article->number }} {{ $article->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.field for="extra-location" :label="__('På plats')" error="extra.location_id" class="sm:col-span-4">
                        <select id="extra-location" wire:model="extra.location_id" class="{{ $input }}">
                            <option value="">{{ __('Välj plats') }}</option>
                            @foreach($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->full_code }} {{ $location->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <div class="sm:col-span-3"><x-secondary-button type="submit">{{ __('Lägg till rad') }}</x-secondary-button></div>
                </form>
                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <x-button type="button" wire:click="complete" wire:confirm="{{ __('Slutför inventeringen? Skillnaderna förs in i lagret och kan bara rättas med en ny rörelse.') }}" wire:loading.attr="disabled">{{ __('Slutför inventeringen') }}</x-button>
                    <button type="button" wire:click="cancelCount" wire:confirm="{{ __('Makulera inventeringen? Inget ändras i lagret.') }}" class="text-sm font-semibold text-gray-700 underline">{{ __('Makulera') }}</button>
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($canCount && ! $starting)
            <x-slot:cta>
                <x-button type="button" wire:click="start">{{ __('Ny inventering') }}</x-button>
            </x-slot:cta>
        @endif

        @if($counts->isEmpty())
            <x-ui.empty-state :title="__('Inga inventeringar ännu')">{{ __('Räkna lagret minst en gång om året, vid bokslutet. Du kan också räkna en hylla i taget när som helst.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($counts as $count)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="count-{{ $count->id }}">
                        <span>
                            <span class="font-semibold tabular-nums">{{ __('Inventering :number', ['number' => $count->number]) }}</span>
                            <span class="text-gray-700">{{ $count->location?->full_code ?? __('Hela lagret') }}</span>
                            <x-ui.badge :tone="$count->status === 'COMPLETED' ? 'success' : ($count->status === 'OPEN' ? 'accent' : 'neutral')">{{ $count->statusLabel() }}</x-ui.badge>
                            <span class="block text-sm text-gray-500">{{ $count->counted_on->toDateString() }} · {{ __('Rader: :count', ['count' => $count->lines_count]) }}</span>
                        </span>
                        <button type="button" wire:click="show({{ $count->id }})" class="text-sm font-semibold text-accent-700 underline">{{ $count->status === 'OPEN' ? __('Räkna') : __('Visa') }}</button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $counts->links() }}</div>
        @endif
    </x-ui.card>
</div>
