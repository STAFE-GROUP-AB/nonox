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

        @if($warnings !== [])
            <div role="status" class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 space-y-2" data-company-warnings>
                @foreach($warnings as $warning)
                    <p>{{ $warning }}</p>
                @endforeach
                <p>{{ __('Inget har ändrats. Kontrollera med din redovisningskonsult och ändra här om det behövs.') }}</p>
            </div>
        @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-label for="details_vat_period" value="{{ __('Momsredovisning') }}" />
                <select id="details_vat_period" class="{{ $field }}" wire:model.live="state.vat_period" @disabled(! $canUpdate)>
                    @foreach($vatPeriods as $period)
                        <option value="{{ $period->value }}">{{ $period->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="vat_period" class="mt-2" />
            </div>
            @if(($state['vat_period'] ?? '') === App\Domain\Company\VatPeriod::None->value)
                <div>
                    <x-label for="details_vat_exemption" value="{{ __('Varför företaget inte är momsregistrerat') }}" />
                    <select id="details_vat_exemption" class="{{ $field }}" wire:model="state.vat_exemption" @disabled(! $canUpdate)>
                        <option value="">{{ __('Inte angivet') }}</option>
                        @foreach($vatExemptions as $exemption)
                            <option value="{{ $exemption->value }}">{{ $exemption->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Skrivs ut på fakturor utan moms. En faktura med moms kan bara skapas om du bekräftar det på fakturan, eftersom moms som tas ut ska betalas in.') }}</p>
                    <x-input-error for="vat_exemption" class="mt-2" />
                </div>
            @endif
            <div>
                <x-label for="details_fiscal_year" value="{{ __('Räkenskapsåret börjar') }}" />
                <select id="details_fiscal_year" class="{{ $field }}" wire:model.live="state.fiscal_year_start_month" @disabled(! $canUpdate)>
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
                <select id="details_framework" class="{{ $field }}" wire:model.live="state.reporting_framework" @disabled(! $canUpdate)>
                    @foreach($frameworks as $framework)
                        <option value="{{ $framework->value }}">{{ $framework->label() }}</option>
                    @endforeach
                </select>
                @if($frameworkYear)
                    <p class="mt-1 text-xs text-gray-500">{{ __('Valet gäller räkenskapsåret :year och de år som följer. Tidigare år behåller sitt regelverk.', ['year' => $frameworkYear]) }}</p>
                @endif
                <x-input-error for="reporting_framework" class="mt-2" />
            </div>
        </div>

        @if($frameworkChanged && $canUpdate)
            <div class="max-w-xl">
                <x-label for="details_framework_change_reason" value="{{ __('Varför byter företaget regelverk? (frivilligt)') }}" />
                <textarea id="details_framework_change_reason" rows="2" maxlength="2000" class="{{ $field }}" wire:model="state.framework_change_reason"></textarea>
                <p class="mt-1 text-xs text-gray-500">{{ __('Sparas med räkenskapsåret, så att det går att se senare varför regelverket byttes.') }}</p>
                <x-input-error for="framework_change_reason" class="mt-2" />
            </div>
        @endif

        @if($askPartners)
            <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                <p>{{ __('Ett handels- eller kommanditbolag där en fysisk person beskattas för hela eller en del av bolagets inkomst ska ha kalenderår som räkenskapsår.') }}</p>
                <label class="mt-3 flex items-start gap-3">
                    <x-checkbox wire:model="state.calendar_year_exempt" class="mt-0.5" />
                    <span>{{ __('Ingen fysisk person beskattas för någon del av bolagets inkomst.') }}</span>
                </label>
                <x-input-error for="calendar_year_exempt" class="mt-2" />
            </div>
        @endif

        @if($frameworkRefusal)
            <p class="text-sm text-red-700">{{ $frameworkRefusal }}</p>
        @elseif($frameworkConditions)
            <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                <p class="whitespace-pre-line">{{ $frameworkConditions }}</p>
                <label class="mt-3 flex items-start gap-3">
                    <x-checkbox wire:model="state.framework_confirmed" class="mt-0.5" />
                    <span>{{ __('Företaget uppfyller villkoren ovan. Är du osäker, fråga din redovisningskonsult innan du väljer. Väljer du K3 tar NONOX ännu inte fram årsredovisningen; bokföring, bokslut och inkomstdeklaration fungerar som vanligt.') }}</span>
                </label>
                <x-input-error for="framework_confirmed" class="mt-2" />
            </div>
        @endif

        <div class="space-y-3">
            <label class="flex items-center gap-3">
                <x-checkbox wire:model="state.f_skatt" :disabled="! $canUpdate" />
                <span class="text-sm text-gray-700">{{ __('Företaget har F-skatt') }}</span>
            </label>
            <div class="ml-8 max-w-md">
                <x-label for="details_preliminary_tax_monthly" value="{{ __('Debiterad preliminärskatt per månad (frivilligt)') }}" />
                <input id="details_preliminary_tax_monthly" type="text" inputmode="decimal" class="{{ $field }} tabular-nums" wire:model="state.preliminary_tax_monthly" placeholder="0" @disabled(! $canUpdate) aria-describedby="details_preliminary_tax_monthly_help">
                <p id="details_preliminary_tax_monthly_help" class="mt-1 text-xs text-gray-500">{{ __('Beloppet i kronor från Skatteverkets beslut om debiterad preliminärskatt. Fyll i det om du vill se kommande betalningar i Momsbufferten; NONOX räknar aldrig ut det själv. Lämna tomt om du inte vet.') }}</p>
                <x-input-error for="preliminary_tax_monthly" class="mt-2" />
            </div>
            <label class="flex items-center gap-3">
                <x-checkbox wire:model="state.employer_registered" :disabled="! $canUpdate" />
                <span class="text-sm text-gray-700">{{ __('Företaget är registrerat som arbetsgivare') }}</span>
            </label>
            <label class="flex items-start gap-3">
                <x-checkbox wire:model="state.turnover_over_40_msek" :disabled="! $canUpdate" class="mt-0.5" />
                <span class="text-sm text-gray-700">{{ __('Beskattningsunderlaget för moms är över 40 miljoner kronor om året') }}
                    <span class="block text-xs text-gray-500">{{ __('Försäljningen som moms ska tas ut på, utan unionsinterna förvärv och import, beräknad för året. Inte den totala omsättningen. Ger andra deklarationsdagar för moms och arbetsgivardeklaration.') }}</span>
                </span>
            </label>
            <label class="flex items-start gap-3">
                <x-checkbox wire:model.live="state.oss_elected" :disabled="! $canUpdate" class="mt-0.5" />
                <span class="text-sm text-gray-700">{{ __('Har ansökt om beskattning i köparens land (OSS) eller är registrerat för OSS') }}
                    <span class="block text-xs text-gray-500">{{ __('Gäller försäljning av varor och elektroniska tjänster till privatpersoner i andra EU-länder. Utan ansökan tas svensk moms ut så länge försäljningen i år och i fjol är högst :amount.', ['amount' => App\Support\Money\Money::ofOre(App\Domain\Vat\OssThreshold::AMOUNT_ORE)->format()]) }}</span>
                </span>
            </label>
            @if($state['oss_elected'] ?? false)
                <div class="ml-8 max-w-xs">
                    <x-label for="details_oss_elected_from" value="{{ __('Från och med') }}" />
                    <input id="details_oss_elected_from" type="date" class="{{ $field }}" wire:model="state.oss_elected_from" @disabled(! $canUpdate)>
                    <x-input-error for="oss_elected_from" class="mt-2" />
                </div>
            @endif
            <label class="flex items-start gap-3">
                <x-checkbox wire:model.live="state.eu_sales_quarterly_goods" :disabled="! $canUpdate" class="mt-0.5" />
                <span class="text-sm text-gray-700">{{ __('Skatteverket har beslutat att periodisk sammanställning för varor får lämnas per kvartal') }}
                    <span class="block text-xs text-gray-500">{{ __('Utan beslut redovisas varor till företag i andra EU-länder per månad. Beslutet gäller så länge varorna är värda högst 500 000 kr per kvartal.') }}</span>
                </span>
            </label>
            @if($state['eu_sales_quarterly_goods'] ?? false)
                <div class="ml-8 max-w-xs">
                    <x-label for="details_eu_sales_quarterly_goods_from" value="{{ __('Gäller från och med') }}" />
                    <input id="details_eu_sales_quarterly_goods_from" type="date" class="{{ $field }}" wire:model="state.eu_sales_quarterly_goods_from" @disabled(! $canUpdate)>
                    <x-input-error for="eu_sales_quarterly_goods_from" class="mt-2" />
                </div>
            @endif
        </div>
    </section>

    <section class="bg-white border border-gray-200 sm:rounded-xl p-6 space-y-5" data-guide-settings>
        <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ __('Företagsguiden') }}</h3>
            <p class="mt-1 text-sm text-gray-600">{{ __('Branschen avgör, tillsammans med företagsform och moms, vilka steg i startplanen och vilka tips som visas för företaget.') }}</p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-label for="details_industry" value="{{ __('Bransch') }}" />
                <select id="details_industry" class="{{ $field }}" wire:model="state.industry" @disabled(! $canUpdate)>
                    <option value="">{{ __('Inte angivet') }}</option>
                    @foreach($industries as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="industry" class="mt-2" />
            </div>
        </div>

        <label class="flex items-start gap-3">
            <x-checkbox wire:model="state.guide_enabled" :disabled="! $canUpdate" class="mt-0.5" />
            <span class="text-sm text-gray-700">{{ __('Visa Företagsguiden') }}
                <span class="block text-xs text-gray-500">{{ __('Startplanen under Att göra och tipsen längst ner på sidorna. Guiden på webbplatsen finns kvar.') }}</span>
            </span>
        </label>
    </section>

    @if($canUpdate)
        <div class="flex items-center justify-end gap-4">
            <x-button wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
        </div>
    @else
        <p class="text-sm text-gray-600">{{ __('Du kan se uppgifterna, men bara ägare och administratörer kan ändra dem.') }}</p>
    @endif
</form>
