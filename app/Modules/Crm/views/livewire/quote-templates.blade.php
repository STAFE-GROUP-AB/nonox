@php($input = 'block w-full min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')

<div class="space-y-6">
    @if($editingId !== null)
        <x-ui.card :title="$editingId === 0 ? __('Ny offertmall') : __('Ändra offertmall')">
            <form wire:submit="save" class="mt-4 space-y-5">
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-ui.field for="template-name" :label="__('Namn')" error="name" class="sm:col-span-2">
                        <input id="template-name" type="text" class="{{ $input }} mt-1" wire:model="form.name" maxlength="100" required>
                    </x-ui.field>
                    <x-ui.field for="template-validity" :label="__('Giltig i antal dagar')" error="validity_days">
                        <input id="template-validity" type="number" min="1" max="365" inputmode="numeric" class="{{ $input }} mt-1 tabular-nums" wire:model="form.validity_days">
                    </x-ui.field>
                    <x-ui.field for="template-intro" :label="__('Inledning (frivilligt)')" error="intro_text" class="sm:col-span-3">
                        <textarea id="template-intro" rows="3" class="{{ $input }} mt-1" wire:model="form.intro_text" maxlength="900"></textarea>
                    </x-ui.field>
                    <x-ui.field for="template-terms" :label="__('Villkor (frivilligt)')" error="terms_text" class="sm:col-span-3" :hint="__('Inledningen och villkoren blir offertens meddelande och står på PDF:en.')">
                        <textarea id="template-terms" rows="3" class="{{ $input }} mt-1" wire:model="form.terms_text" maxlength="900"></textarea>
                    </x-ui.field>
                </div>

                <fieldset>
                    <legend class="text-sm font-semibold text-ink">{{ __('Rader') }}</legend>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Välj en artikel så hämtas namn, enhet, pris och moms från artikelregistret den dag offerten skapas. Utan artikel skriver du benämning och pris själv.') }}</p>
                    <x-input-error for="rows" class="mt-2" />
                    <ol class="mt-3 space-y-3">
                        @foreach($rows as $index => $row)
                            <li wire:key="template-row-{{ $index }}" class="grid gap-3 rounded-xl p-3 ring-1 ring-stone-200 sm:grid-cols-12">
                                <div class="sm:col-span-2">
                                    <label for="row-{{ $index }}-article" class="text-xs font-medium text-gray-700">{{ __('Artikel') }}</label>
                                    <select id="row-{{ $index }}-article" class="{{ $input }} mt-1" wire:model.live="rows.{{ $index }}.article">
                                        <option value="">{{ __('Fri rad') }}</option>
                                        @foreach($articles as $article)
                                            <option value="{{ $article->number }}">{{ $article->number }} {{ $article->name }} ({{ $article->price_ore->format() }})</option>
                                        @endforeach
                                    </select>
                                    <x-input-error for="rows.{{ $index }}.article" class="mt-1" />
                                </div>
                                <div class="sm:col-span-3">
                                    <label for="row-{{ $index }}-description" class="text-xs font-medium text-gray-700">{{ $row['article'] === '' ? __('Benämning') : __('Egen benämning (frivilligt)') }}</label>
                                    <input id="row-{{ $index }}-description" type="text" class="{{ $input }} mt-1" wire:model="rows.{{ $index }}.description" maxlength="255">
                                    <x-input-error for="rows.{{ $index }}.description" class="mt-1" />
                                </div>
                                <div class="sm:col-span-1">
                                    <label for="row-{{ $index }}-quantity" class="text-xs font-medium text-gray-700">{{ __('Antal') }}</label>
                                    <input id="row-{{ $index }}-quantity" type="text" inputmode="decimal" class="{{ $input }} mt-1 tabular-nums" wire:model="rows.{{ $index }}.quantity">
                                    <x-input-error for="rows.{{ $index }}.quantity" class="mt-1" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="row-{{ $index }}-unit" class="text-xs font-medium text-gray-700">{{ __('Enhet') }}</label>
                                    <select id="row-{{ $index }}-unit" class="{{ $input }} mt-1" wire:model="rows.{{ $index }}.unit_id">
                                        <option value="">{{ $row['article'] !== '' ? __('Artikelns enhet') : __('Standardenheten (:unit)', ['unit' => $defaultUnitCode]) }}</option>
                                        @foreach($units as $unit)
                                            <option value="{{ $unit->id }}">{{ $unit->code }}{{ $unit->isArchived() ? ' ('.__('arkiverad').')' : '' }}</option>
                                        @endforeach
                                    </select>
                                    <x-input-error for="rows.{{ $index }}.unit_id" class="mt-1" />
                                </div>
                                @if($row['article'] === '')
                                    <div class="sm:col-span-2">
                                        <label for="row-{{ $index }}-price" class="text-xs font-medium text-gray-700">{{ __('Pris exkl. moms') }}</label>
                                        <input id="row-{{ $index }}-price" type="text" inputmode="decimal" class="{{ $input }} mt-1 tabular-nums" wire:model="rows.{{ $index }}.price">
                                        <x-input-error for="rows.{{ $index }}.price" class="mt-1" />
                                    </div>
                                    <div class="sm:col-span-1">
                                        <label for="row-{{ $index }}-vat" class="text-xs font-medium text-gray-700">{{ __('Moms') }}</label>
                                        <select id="row-{{ $index }}-vat" class="{{ $input }} mt-1" wire:model="rows.{{ $index }}.vat_rate">
                                            @foreach($vatRates as $rate)
                                                <option value="{{ $rate }}">{{ $rate / 100 }} %</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @else
                                    <p class="self-end text-xs text-gray-600 sm:col-span-3">{{ __('Pris och moms från artikeln') }}</p>
                                @endif
                                <div class="sm:col-span-1">
                                    <label for="row-{{ $index }}-discount" class="text-xs font-medium text-gray-700">{{ __('Rabatt %') }}</label>
                                    <input id="row-{{ $index }}-discount" type="text" inputmode="decimal" class="{{ $input }} mt-1 tabular-nums" wire:model="rows.{{ $index }}.discount">
                                    <x-input-error for="rows.{{ $index }}.discount" class="mt-1" />
                                </div>
                                <div class="flex items-end sm:col-span-12 sm:justify-end">
                                    <button type="button" class="min-h-11 text-sm text-gray-700 underline sm:min-h-0" wire:click="removeRow({{ $index }})">{{ __('Ta bort raden') }}</button>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    <button type="button" class="mt-3 min-h-11 rounded-lg px-4 text-sm font-semibold ring-1 ring-gray-300 hover:bg-gray-50" wire:click="addRow">{{ __('Lägg till rad') }}</button>
                    @if($canManageUnits)
                        <a href="{{ route('invoicing.units') }}" class="mt-3 ml-4 inline-block text-sm font-medium text-accent-700 underline">{{ __('Hantera enheter') }}</a>
                    @endif
                </fieldset>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <button type="button" class="text-sm text-gray-600 underline" wire:click="cancel">{{ __('Avbryt') }}</button>
                    <x-button wire:loading.attr="disabled">{{ __('Spara mallen') }}</x-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($editingId === null)
                <x-button type="button" wire:click="create">{{ __('Ny mall') }}</x-button>
            @endif
            <div class="flex gap-2 text-sm" role="group" aria-label="{{ __('Visa') }}">
                <button type="button" wire:click="$set('archived', false)" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => ! $archived, 'bg-white text-ink ring-1 ring-stone-200' => $archived]) aria-pressed="{{ $archived ? 'false' : 'true' }}">{{ __('Aktiva') }}</button>
                <button type="button" wire:click="$set('archived', true)" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $archived, 'bg-white text-ink ring-1 ring-stone-200' => ! $archived]) aria-pressed="{{ $archived ? 'true' : 'false' }}">{{ __('Arkiverade') }}</button>
            </div>
        </div>

        @if($templates->isEmpty())
            <x-ui.empty-state class="mt-6" :title="$archived ? __('Inga arkiverade mallar') : __('Inga offertmallar ännu')">
                {{ __('En mall är en offert du skickar ofta: artiklar, villkor och giltighet. Gör en ny offert från mallen på Offerter eller på kundens kort.') }}
            </x-ui.empty-state>
        @else
            <ul class="mt-4 divide-y divide-gray-100" data-testid="crm-quote-templates">
                @foreach($templates as $template)
                    <li wire:key="template-{{ $template->id }}" class="flex flex-wrap items-center gap-3 py-3">
                        <span class="min-w-0 flex-1 basis-52">
                            <span class="block font-semibold text-gray-900">{{ $template->name }}</span>
                            <span class="block text-sm text-gray-600">{{ __(':rows rader · giltig :days dagar', ['rows' => $template->rows_count, 'days' => $template->validity_days]) }}</span>
                        </span>
                        @if(! $archived)
                            <button type="button" class="min-h-11 text-sm font-medium text-accent-600 hover:text-accent-700" wire:click="edit({{ $template->id }})">{{ __('Ändra') }}</button>
                        @endif
                        <button type="button" class="min-h-11 text-sm text-gray-700 underline" wire:click="archive({{ $template->id }}, {{ $archived ? 'false' : 'true' }})">{{ $archived ? __('Återställ') : __('Arkivera') }}</button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
