@php
    $select = 'mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-xs';
@endphp

<form wire:submit="create" class="space-y-8">
    <section class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
        <div>
            <h3 class="text-lg font-medium text-gray-900">{{ __('Om företaget') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Uppgifterna står på registreringsbeviset från Bolagsverket eller på verksamt.se.') }}</p>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <x-label for="org_number" value="{{ __('Organisationsnummer') }}" />
                <x-input id="org_number" type="text" class="mt-1 block w-full" wire:model.blur="org_number" autofocus placeholder="556123-4567" inputmode="numeric" />
                <x-input-error for="org_number" class="mt-2" />
            </div>

            <div>
                <x-label for="legal_form" value="{{ __('Företagsform') }}" />
                <select id="legal_form" class="{{ $select }}" wire:model.live="legal_form">
                    @foreach($legalForms as $form)
                        <option value="{{ $form->value }}">{{ $form->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="legal_form" class="mt-2" />
            </div>
        </div>

        @include('livewire.partials.company-lookup')

        <div>
            <x-label for="name" value="{{ __('Företagsnamn') }}" />
            <x-input id="name" type="text" class="mt-1 block w-full" wire:model="name" autocomplete="organization" />
            <x-input-error for="name" class="mt-2" />
        </div>

        <div>
            <x-label for="address_line1" value="{{ __('Adress (frivilligt)') }}" />
            <x-input id="address_line1" type="text" class="mt-1 block w-full" wire:model="address_line1" autocomplete="street-address" />
            <x-input-error for="address_line1" class="mt-2" />
        </div>

        <div class="grid gap-6 sm:grid-cols-3">
            <div>
                <x-label for="postal_code" value="{{ __('Postnummer') }}" />
                <x-input id="postal_code" type="text" class="mt-1 block w-full" wire:model="postal_code" autocomplete="postal-code" />
                <x-input-error for="postal_code" class="mt-2" />
            </div>

            <div class="sm:col-span-2">
                <x-label for="city" value="{{ __('Ort') }}" />
                <x-input id="city" type="text" class="mt-1 block w-full" wire:model="city" autocomplete="address-level2" />
                <x-input-error for="city" class="mt-2" />
            </div>
        </div>

        <div>
            <x-label for="email" value="{{ __('E-post till företaget (frivilligt)') }}" />
            <x-input id="email" type="email" class="mt-1 block w-full" wire:model="email" />
            <x-input-error for="email" class="mt-2" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <x-label for="industry" value="{{ __('Bransch (frivilligt)') }}" />
                <select id="industry" class="{{ $select }}" wire:model="industry" aria-describedby="industry_help">
                    <option value="">{{ __('Välj bransch') }}</option>
                    @foreach($industries as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="industry" class="mt-2" />
            </div>
        </div>
        <p id="industry_help" class="-mt-3 text-sm text-gray-600">{{ __('Företagsguiden väljer steg och tips efter branschen, företagsformen och om företaget är momsregistrerat.') }}</p>
    </section>

    <section class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
        <div>
            <h3 class="text-lg font-medium text-gray-900">{{ __('Bokföring och moms') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Osäker? Förvalen passar de flesta aktiebolag. Du kan ändra dem senare.') }}</p>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <x-label for="fiscal_year_start_month" value="{{ __('Räkenskapsåret börjar i') }}" />
                <select id="fiscal_year_start_month" class="{{ $select }}" wire:model.live="fiscal_year_start_month" @if($calendarYearOnly) aria-describedby="fiscal_year_calendar" @endif>
                    @foreach($months as $number => $month)
                        <option value="{{ $number }}">{{ $month }}</option>
                    @endforeach
                </select>
                @if($calendarYearOnly)
                    <p id="fiscal_year_calendar" class="mt-2 text-sm text-gray-600">{{ __('En enskild firma har alltid kalenderår som räkenskapsår.') }}</p>
                @endif
                <x-input-error for="fiscal_year_start_month" class="mt-2" />
            </div>

            <div>
                <x-label for="accounting_method" value="{{ __('Bokföringsmetod') }}" />
                <select id="accounting_method" class="{{ $select }}" wire:model="accounting_method">
                    @foreach($accountingMethods as $method)
                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="accounting_method" class="mt-2" />
            </div>
        </div>

        @if($askPartners)
            <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                <p>{{ __('Ett handels- eller kommanditbolag där en fysisk person beskattas för hela eller en del av bolagets inkomst ska ha kalenderår som räkenskapsår.') }}</p>
                <label class="mt-3 flex items-start gap-3">
                    <x-checkbox wire:model="calendar_year_exempt" class="mt-0.5" />
                    <span>{{ __('Ingen fysisk person beskattas för någon del av bolagets inkomst.') }}</span>
                </label>
                <x-input-error for="calendar_year_exempt" class="mt-2" />
            </div>
        @endif

        <label class="flex items-center gap-3">
            <x-checkbox wire:model.live="vat_registered" />
            <span class="text-sm text-gray-700">{{ __('Företaget är momsregistrerat') }}</span>
        </label>

        @if($vat_registered)
            <div>
                <x-label for="vat_period" value="{{ __('Hur ofta redovisar ni moms?') }}" />
                <select id="vat_period" class="{{ $select }}" wire:model="vat_period">
                    @foreach($vatPeriods as $period)
                        <option value="{{ $period->value }}">{{ $period->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="vat_period" class="mt-2" />
            </div>
        @endif

        <div>
            <x-label for="reporting_framework" value="{{ __('Regelverk för årsbokslut') }}" />
            <select id="reporting_framework" class="{{ $select }}" wire:model.live="reporting_framework">
                @foreach($frameworks as $framework)
                    <option value="{{ $framework->value }}">{{ $framework->label() }}</option>
                @endforeach
            </select>
            <x-input-error for="reporting_framework" class="mt-2" />

            @if($frameworkRefusal)
                <p class="mt-2 text-sm text-red-700">{{ $frameworkRefusal }}</p>
            @elseif($frameworkConditions)
                <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                    <p class="whitespace-pre-line">{{ $frameworkConditions }}</p>
                    <label class="mt-3 flex items-start gap-3">
                        <x-checkbox wire:model="framework_confirmed" class="mt-0.5" />
                        <span>{{ __('Företaget uppfyller villkoren ovan. Är du osäker, fråga din redovisningskonsult innan du väljer. Väljer du K3 tar NONOX fram årsredovisningen bara för ett mindre aktiebolag, och då som en beta; bokföring, bokslut och inkomstdeklaration fungerar som vanligt.') }}</span>
                    </label>
                    <x-input-error for="framework_confirmed" class="mt-2" />
                </div>
            @endif
        </div>

        <div class="space-y-3">
            <label class="flex items-center gap-3">
                <x-checkbox wire:model="f_skatt" />
                <span class="text-sm text-gray-700">{{ __('Företaget har F-skatt') }}</span>
            </label>

            <label class="flex items-center gap-3">
                <x-checkbox wire:model="employer_registered" />
                <span class="text-sm text-gray-700">{{ __('Företaget är registrerat som arbetsgivare') }}</span>
            </label>
        </div>
    </section>

    @if(config('app.landing.hosted'))
        <div>
            <label class="flex items-start gap-3">
                <x-checkbox wire:model="accept_agreement" class="mt-0.5" />
                <span class="text-sm text-gray-700">{!! __('Jag godkänner :terms och :dpa för företaget, och har läst :privacy.', [
                    'terms' => '<a href="'.e(route('legal.terms')).'" target="_blank" class="font-medium text-accent-600 underline">'.e(__('villkoren')).'</a>',
                    'dpa' => '<a href="'.e(route('legal.dpa')).'" target="_blank" class="font-medium text-accent-600 underline">'.e(__('personuppgiftsbiträdesavtalet')).'</a>',
                    'privacy' => '<a href="'.e(route('legal.privacy')).'" target="_blank" class="font-medium text-accent-600 underline">'.e(__('integritetspolicyn')).'</a>',
                ]) !!}</span>
            </label>
            <x-input-error for="accept_agreement" class="mt-2" />
        </div>
    @endif

    <div class="flex justify-end">
        <x-button wire:loading.attr="disabled">
            {{ __('Skapa företaget') }}
        </x-button>
    </div>
</form>
