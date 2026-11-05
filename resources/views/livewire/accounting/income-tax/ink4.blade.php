{{-- Inkomstdeklaration 4 for a handelsbolag or kommanditbolag (App\Domain\IncomeTax\Ink4, ADR-053). --}}
@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $kronor): string => number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    $taxable = $ink4->taxableKronor();
    $proposals = $ink4->proposals();
    $ready = $ink4->isVerifiedPeriod() && $ink4->partners->isNotEmpty() && $ink4->partners->sum('share_bp') === 10000;
@endphp

<section class="grid gap-3 sm:grid-cols-3" data-ink4-summary>
    <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
        <p class="text-sm text-gray-500">{{ __('Bokfört resultat') }}</p>
        <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr($ink4->resultKronor()) }}</p>
    </div>
    <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
        <p class="text-sm text-gray-500">{{ __('Delägare') }}</p>
        <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $ink4->partners->count() }}</p>
    </div>
    <div class="rounded-3xl bg-ink px-5 py-4 text-white shadow-xs">
        <p class="text-sm text-stone-300">{{ $taxable >= 0 ? __('Överskott att fördela (4.12)') : __('Underskott att fördela (4.13)') }}</p>
        <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums">{{ $kr(abs($taxable)) }}</p>
    </div>
</section>

<p class="rounded-2xl bg-stone-100 px-4 py-3 text-sm text-gray-700" data-ink4-partners-note>{{ __('Handelsbolaget betalar ingen inkomstskatt. Överskottet eller underskottet fördelas på delägarna, som deklarerar sin del själva: en fysisk person på blankett N3A, ett aktiebolag eller annan juridisk person på N3B. Där görs också räntefördelning, periodiseringsfond och egenavgifter. NONOX tar inte fram N3A eller N3B.') }}</p>

@foreach($ink4->warnings() as $warning)
    <p class="rounded-2xl bg-white px-4 py-3 text-sm text-accent-800 ring-1 ring-accent-200" role="status">{{ $warning }}</p>
@endforeach

<div class="grid gap-6 xl:grid-cols-[minmax(0,32rem)_1fr]">
    <div class="space-y-6">
        <x-ui.card :title="__('Delägare')" :description="__('Varje delägare får en egen bilaga INK4DU. Numret är delägarens konton i BAS: 1 är 2010–2019, 2 är 2020–2029, 3 är 2030–2039 och 4 är 2040–2049; därifrån hämtas uttag och tillskott.')" data-ink4-partners>
            @if(! $fiscalYear->isOpen())
                <p class="mb-4 rounded-2xl bg-stone-100 px-4 py-3 text-sm text-gray-700" data-ink4-partners-locked>{{ __('Räkenskapsåret är stängt. Delägarna för året kan inte ändras, eftersom de hör till årets deklaration.') }}</p>
            @elseif($partnersCopied)
                <p class="mb-4 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-950 ring-1 ring-amber-200" data-ink4-partners-copied>{{ __('Delägarna är hämtade från förra året och är inte sparade för det här året än. Kontrollera dem och klicka på Spara delägarna.') }}</p>
            @endif
            <form wire:submit="savePartners" class="space-y-4">
                <fieldset class="space-y-4" @disabled(! $fiscalYear->isOpen())>
                @foreach($partners as $index => $partner)
                    <div class="space-y-3 rounded-2xl p-4 ring-1 ring-stone-200" wire:key="partner-{{ $index }}">
                        <div class="grid gap-3 sm:grid-cols-[5rem_1fr]">
                            <x-ui.field :for="'partner_'.$index.'_position'" :label="__('Nummer')" :error="'partners.'.$index.'.position'">
                                <input id="partner_{{ $index }}_position" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="partners.{{ $index }}.position">
                            </x-ui.field>
                            <x-ui.field :for="'partner_'.$index.'_name'" :label="__('Namn')" :error="'partners.'.$index.'.name'">
                                <input id="partner_{{ $index }}_name" type="text" class="{{ $input }} mt-1" wire:model="partners.{{ $index }}.name" autocomplete="off">
                            </x-ui.field>
                        </div>
                        <label class="flex items-center gap-3 text-sm text-gray-700"><x-checkbox wire:model.live="partners.{{ $index }}.legal_person" />{{ __('Delägaren är ett företag (juridisk person)') }}</label>
                        <div class="grid gap-3 sm:grid-cols-[1fr_7rem]">
                            <x-ui.field :for="'partner_'.$index.'_identity'" :label="($partner['legal_person'] ?? false) ? __('Organisationsnummer') : __('Personnummer med århundrade')" :error="'partners.'.$index.'.identity'" :hint="! ($partner['legal_person'] ?? false) && ($partner['masked'] ?? '') !== '' ? __('Sparat: :number. Lämna tomt för att behålla det, eller skriv ett nytt.', ['number' => $partner['masked']]) : null">
                                <input id="partner_{{ $index }}_identity" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="partners.{{ $index }}.identity" autocomplete="off" placeholder="{{ ($partner['legal_person'] ?? false) ? '556677-8899' : (($partner['masked'] ?? '') !== '' ? $partner['masked'] : '19121212-1212') }}">
                            </x-ui.field>
                            <x-ui.field :for="'partner_'.$index.'_share'" :label="__('Andel, %')" :error="'partners.'.$index.'.share'">
                                <input id="partner_{{ $index }}_share" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="partners.{{ $index }}.share">
                            </x-ui.field>
                        </div>
                        <details class="rounded-xl ring-1 ring-stone-200" @if(($partner['contributions'] ?? '') !== '' || ($partner['withdrawals'] ?? '') !== '' || ($partner['employer_deduction'] ?? '') !== '') open @endif>
                            <summary class="min-h-10 cursor-pointer px-3 py-2 text-sm font-medium text-ink">{{ __('Tillskott, uttag och nedsättning för delägaren') }}</summary>
                            <div class="grid gap-3 border-t border-stone-100 px-3 py-3 sm:grid-cols-3">
                                <x-ui.field :for="'partner_'.$index.'_contributions'" :label="__('Tillskott (8103)')" :error="'partners.'.$index.'.contributions'" :hint="__('Tomt: från kontona x7–x8.')">
                                    <input id="partner_{{ $index }}_contributions" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="partners.{{ $index }}.contributions">
                                </x-ui.field>
                                <x-ui.field :for="'partner_'.$index.'_withdrawals'" :label="__('Uttag (8104)')" :error="'partners.'.$index.'.withdrawals'" :hint="__('Tomt: från kontona x1–x3.')">
                                    <input id="partner_{{ $index }}_withdrawals" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="partners.{{ $index }}.withdrawals">
                                </x-ui.field>
                                <x-ui.field :for="'partner_'.$index.'_employer_deduction'" :label="__('Andel avdrag arbetsgivaravgift (8105)')" :error="'partners.'.$index.'.employer_deduction'" :hint="__('Bara om bolaget har gjort regional nedsättning av arbetsgivaravgifterna.')">
                                    <input id="partner_{{ $index }}_employer_deduction" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="partners.{{ $index }}.employer_deduction">
                                </x-ui.field>
                            </div>
                        </details>
                        <div class="flex flex-wrap items-center gap-4">
                            <button type="button" wire:click="removePartner({{ $index }})" class="text-sm font-medium text-gray-700 underline" aria-label="{{ __('Ta bort delägare :position, :name', ['position' => $partner['position'], 'name' => $partner['name']]) }}">{{ __('Ta bort delägaren') }}</button>
                            @if(($partner['ref'] ?? '') !== '')
                                <a href="{{ route('privacy.export.incomeTaxPartner', (int) $partner['ref']) }}" class="text-sm font-medium text-accent-700 underline" aria-label="{{ __('Registerutdrag för :name', ['name' => $partner['name']]) }}">{{ __('Registerutdrag (JSON)') }}</a>
                            @endif
                        </div>
                    </div>
                @endforeach
                </fieldset>
                <div class="flex flex-wrap items-center gap-4" @if(! $fiscalYear->isOpen()) hidden @endif>
                    <button type="button" wire:click="addPartner" class="inline-flex min-h-10 items-center rounded-full px-4 text-sm font-semibold text-ink ring-1 ring-stone-300 hover:bg-stone-100">{{ __('Lägg till delägare') }}</button>
                    <x-button>{{ __('Spara delägarna') }}</x-button>
                </div>
                <p class="text-xs text-gray-500">{{ __('Person- och organisationsnummer sparas krypterade och skrivs bara i filen till Skatteverket.') }}</p>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Skattemässiga justeringar (INK4S)')" :description="__('Belopp i hela kronor, utan minustecken.')">
            <form wire:submit="save" class="space-y-4" data-ink4-form>
                @foreach(['adjustments' => [__('Justeringar av det bokförda resultatet (4.3–4.11)'), $ink4Adjustments], 'information' => [__('Övriga uppgifter (4.14–4.16)'), $ink4Information]] as $group => [$legend, $definitions])
                    <details class="group rounded-2xl ring-1 ring-stone-200" @if($group === 'information' || collect(array_keys($definitions))->contains(fn ($code) => ($adjustments[$code] ?? '') !== '')) open @endif>
                        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-4 py-2 text-sm font-bold text-ink [&::-webkit-details-marker]:hidden">
                            <svg class="size-4 transition group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                            {{ $legend }}
                        </summary>
                        <div class="space-y-4 border-t border-stone-100 px-4 py-3">
                            @foreach($definitions as $code => $definition)
                                @php($label = is_array($definition) ? $definition[0] : $definition)
                                @php($proposal = $proposals[$code] ?? 0)
                                <x-ui.field :for="'ink4_'.$code" :label="__($label).' ('.$code.')'" :error="'adjustments.'.$code" :hint="$proposal > 0 ? __('Förslag från bokföringen: :amount. Lämna tomt för att använda förslaget, skriv 0 om fältet inte ska fyllas i.', ['amount' => $kr($proposal)]) : null">
                                    <input id="ink4_{{ $code }}" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="adjustments.{{ $code }}">
                                </x-ui.field>
                            @endforeach
                        </div>
                    </details>
                @endforeach
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="ink4_consultant" :label="__('Har en konsult hjälpt till med bokslutet?')">
                        <select id="ink4_consultant" class="{{ $input }} mt-1" wire:model="consultant"><option value="">{{ __('Inte angivet') }}</option><option value="1">{{ __('Ja') }}</option><option value="0">{{ __('Nej') }}</option></select>
                    </x-ui.field>
                    <x-ui.field for="ink4_audited" :label="__('Har årsredovisningen reviderats?')">
                        <select id="ink4_audited" class="{{ $input }} mt-1" wire:model="audited"><option value="">{{ __('Inte angivet') }}</option><option value="1">{{ __('Ja') }}</option><option value="0">{{ __('Nej') }}</option></select>
                    </x-ui.field>
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <x-button>{{ __('Spara') }}</x-button>
                    @if($ready)
                        <a href="{{ route('accounting.incomeTax.sru', $fiscalYear) }}" class="text-sm font-medium text-accent-700 hover:underline" data-ink4-download>{{ __('Ladda ner SRU-filer') }}</a>
                    @endif
                </div>
                @if($ink4->isVerifiedPeriod())
                    <div class="space-y-1 rounded-2xl bg-stone-50 px-4 py-3 text-sm text-gray-700" data-ink4-instructions>
                        <p class="font-semibold text-ink">{{ __('Så lämnar du Inkomstdeklaration 4') }}</p>
                        <p>{{ __('ZIP-filen innehåller INFO.SRU och BLANKETTER.SRU med INK4R, INK4S och en INK4DU per delägare. Packa upp den och lämna båda filerna i Skatteverkets e-tjänst Filöverföring.') }}</p>
                        <p>{{ __('Första sidan av Inkomstdeklaration 4 kan inte lämnas med filöverföring. Den behövs bara om bolaget redovisar särskild löneskatt, avkastningsskatt eller fastighetsskatt; då lämnas den på papper eller i e-tjänsten Inkomstdeklaration 2, 3 och 4.') }}</p>
                    </div>
                @else
                    <p class="text-sm text-accent-800">{{ __('Fältkoderna för beskattningsperiod :period är inte kontrollerade ännu. Använd siffrorna här och fyll i deklarationen i Skatteverkets e-tjänst.', ['period' => $ink4->period()]) }}</p>
                @endif
            </form>
        </x-ui.card>
    </div>

    <x-ui.card :title="__('Deklarationen, fält för fält')">
        <div class="space-y-6">
            @foreach(['INK4R' => $ink4->ink4r(), 'INK4S' => $ink4->ink4s()] as $name => $formFields)
                <div>
                    <p class="text-sm font-semibold text-ink">{{ $name }}-{{ $ink4->period() }}</p>
                    <table class="mt-2 min-w-full text-sm">
                        <tbody class="divide-y divide-stone-200">
                            @foreach($formFields as $code => $value)
                                @continue(in_array($code, [7011, 7012], true))
                                <tr wire:key="{{ $name }}-{{ $code }}">
                                    <td class="w-16 py-1.5 pe-3 tabular-nums text-gray-500">{{ $code }}</td>
                                    <td class="py-1.5 pe-3 text-gray-800">{{ __($ink4Labels[$code] ?? ($ink4Adjustments[$code][0] ?? '')) }}</td>
                                    <td class="py-1.5 text-right tabular-nums font-medium text-ink">{{ is_int($value) ? $kr($value) : $value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
            @foreach($ink4->ink4du() as $block)
                <div data-ink4du>
                    <p class="text-sm font-semibold text-ink">INK4DU-{{ $ink4->period() }}: {{ $block['partner']->name }} ({{ App\Domain\IncomeTax\IncomeTaxForms::percent($block['partner']->share_bp) }} %)</p>
                    <table class="mt-2 min-w-full text-sm">
                        <tbody class="divide-y divide-stone-200">
                            @foreach($block['fields'] as $code => $value)
                                @continue(in_array($code, [7011, 7012, 8100], true))
                                <tr>
                                    <td class="w-16 py-1.5 pe-3 tabular-nums text-gray-500">{{ $code }}</td>
                                    <td class="py-1.5 pe-3 text-gray-800">{{ __($ink4Labels[$code] ?? '') }}</td>
                                    <td class="py-1.5 text-right tabular-nums font-medium text-ink">{{ is_int($value) ? $kr($value) : $value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
