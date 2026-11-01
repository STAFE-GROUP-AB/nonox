@php
    $field = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs disabled:bg-gray-50 disabled:text-gray-500';
@endphp

<form wire:submit="save" class="space-y-6">
    <section class="bg-white border border-gray-200 sm:rounded-xl p-6 space-y-5">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Företagsuppgifter') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Uppgifterna används bland annat på dina fakturor.') }}</p>
        </div>

        <dl class="grid gap-4 sm:grid-cols-2 rounded-lg bg-gray-50 p-4 text-sm">
            <div>
                <dt class="text-gray-500">{{ __('Organisationsnummer') }}</dt>
                <dd class="font-medium text-gray-900 tabular-nums">{{ $company->org_number }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('Företagsform') }}</dt>
                <dd class="font-medium text-gray-900">{{ $company->legal_form->label() }}</dd>
            </div>
            <p class="sm:col-span-2 text-gray-500">{{ __('Organisationsnummer och företagsform går inte att ändra, eftersom det då blir ett annat företag. Lägg i så fall till det som ett nytt företag.') }}</p>
        </dl>

        @if($canUpdate)
            @include('livewire.partials.company-lookup')
        @endif

        <div>
            <x-label for="details_name" value="{{ __('Företagsnamn') }}" />
            <input id="details_name" type="text" class="{{ $field }}" wire:model="state.name" @disabled(! $canUpdate)>
            <x-input-error for="name" class="mt-2" />
        </div>

        <div>
            <x-label for="details_address" value="{{ __('Adress') }}" />
            <input id="details_address" type="text" class="{{ $field }}" wire:model="state.address_line1" autocomplete="street-address" @disabled(! $canUpdate)>
            <x-input-error for="address_line1" class="mt-2" />
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <x-label for="details_postal_code" value="{{ __('Postnummer') }}" />
                <input id="details_postal_code" type="text" class="{{ $field }}" wire:model="state.postal_code" autocomplete="postal-code" @disabled(! $canUpdate)>
                <x-input-error for="postal_code" class="mt-2" />
            </div>
            <div class="sm:col-span-2">
                <x-label for="details_city" value="{{ __('Ort') }}" />
                <input id="details_city" type="text" class="{{ $field }}" wire:model="state.city" autocomplete="address-level2" @disabled(! $canUpdate)>
                <x-input-error for="city" class="mt-2" />
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <x-label for="details_email" value="{{ __('E-post') }}" />
                <input id="details_email" type="email" class="{{ $field }}" wire:model="state.email" @disabled(! $canUpdate)>
                <x-input-error for="email" class="mt-2" />
            </div>
            <div>
                <x-label for="details_phone" value="{{ __('Telefon') }}" />
                <input id="details_phone" type="text" class="{{ $field }}" wire:model="state.phone" autocomplete="tel" @disabled(! $canUpdate)>
                <x-input-error for="phone" class="mt-2" />
            </div>
            <div>
                <x-label for="details_website" value="{{ __('Webbplats') }}" />
                <input id="details_website" type="text" class="{{ $field }}" wire:model="state.website" @disabled(! $canUpdate)>
                <x-input-error for="website" class="mt-2" />
            </div>
        </div>
    </section>

    <section class="bg-white border border-gray-200 sm:rounded-xl p-6 space-y-5">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Betalning') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Dit dina kunder betalar. Står på dina fakturor.') }}</p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-label for="details_bankgiro" value="{{ __('Bankgiro') }}" />
                <input id="details_bankgiro" type="text" class="{{ $field }} tabular-nums" wire:model="state.bankgiro" placeholder="123-4566" inputmode="numeric" @disabled(! $canUpdate)>
                <x-input-error for="bankgiro" class="mt-2" />
            </div>
            <div>
                <x-label for="details_plusgiro" value="{{ __('Plusgiro') }}" />
                <input id="details_plusgiro" type="text" class="{{ $field }} tabular-nums" wire:model="state.plusgiro" placeholder="123456-6" inputmode="numeric" @disabled(! $canUpdate)>
                <x-input-error for="plusgiro" class="mt-2" />
            </div>
        </div>
    </section>

    <section class="bg-white border border-gray-200 sm:rounded-xl p-6 space-y-5">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Moms och bokföring') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Osäker? Fråga din redovisningskonsult, eller titta i besluten från Skatteverket.') }}</p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-label for="details_vat_period" value="{{ __('Momsredovisning') }}" />
                <select id="details_vat_period" class="{{ $field }}" wire:model="state.vat_period" @disabled(! $canUpdate)>
                    @foreach($vatPeriods as $period)
                        <option value="{{ $period->value }}">{{ $period->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="vat_period" class="mt-2" />
            </div>
            <div>
                <x-label for="details_fiscal_year" value="{{ __('Räkenskapsåret börjar') }}" />
                <select id="details_fiscal_year" class="{{ $field }}" wire:model="state.fiscal_year_start_month" @disabled(! $canUpdate)>
                    @foreach($months as $number => $month)
                        <option value="{{ $number }}">1 {{ $month }}</option>
                    @endforeach
                </select>
                <x-input-error for="fiscal_year_start_month" class="mt-2" />
            </div>
            <div>
                <x-label for="details_accounting_method" value="{{ __('Bokföringsmetod') }}" />
                <select id="details_accounting_method" class="{{ $field }}" wire:model="state.accounting_method" @disabled(! $canUpdate)>
                    @foreach($accountingMethods as $method)
                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="accounting_method" class="mt-2" />
            </div>
            <div>
                <x-label for="details_framework" value="{{ __('Regelverk för årsbokslut') }}" />
                <select id="details_framework" class="{{ $field }}" wire:model="state.reporting_framework" @disabled(! $canUpdate)>
                    @foreach($frameworks as $framework)
                        <option value="{{ $framework->value }}">{{ $framework->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="reporting_framework" class="mt-2" />
            </div>
        </div>

        <div class="space-y-3">
            <label class="flex items-center gap-3">
                <x-checkbox wire:model="state.f_skatt" :disabled="! $canUpdate" />
                <span class="text-sm text-gray-700">{{ __('Företaget har F-skatt') }}</span>
            </label>
            <label class="flex items-center gap-3">
                <x-checkbox wire:model="state.employer_registered" :disabled="! $canUpdate" />
                <span class="text-sm text-gray-700">{{ __('Företaget är registrerat som arbetsgivare') }}</span>
            </label>
            <label class="flex items-center gap-3">
                <x-checkbox wire:model="state.turnover_over_40_msek" :disabled="! $canUpdate" />
                <span class="text-sm text-gray-700">{{ __('Omsättningen är över 40 miljoner kronor om året (andra deklarationsdagar för moms)') }}</span>
            </label>
        </div>
    </section>

    @if($canUpdate)
        <div class="flex items-center justify-end gap-4">
            <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
        </div>
    @else
        <p class="text-sm text-gray-600">{{ __('Du kan se uppgifterna, men bara ägare och administratörer kan ändra dem.') }}</p>
    @endif
</form>
