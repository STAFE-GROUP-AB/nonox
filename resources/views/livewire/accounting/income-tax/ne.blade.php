{{-- The NE-bilaga for a sole trader (App\Domain\IncomeTax\Ne, ADR-053). --}}
@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $kronor): string => number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    $calc = $ne->calculation();
    $proposals = $ne->proposals();
    $estimate = $ne->estimatedCapitalBase();
    $fields = $ne->ne();
    $result = $calc['result'];
    $groups = [
        __('Justeringar av det bokförda resultatet') => [7601, 7700, 7602, 7701],
        __('Gemensam verksamhet och medhjälpande make') => [7702, 7603, 7614, 7703],
        __('Övriga justeringar och tidigare underskott') => [7704, 7604, 7705, 7706, 7605, 7606, 7707],
        __('Pension, sjukpenning och allmänt avdrag') => [7711, 7712, 7611, 7612, 7613],
        __('Övriga uppgifter') => [8002, 8003, 8004, 8006, 8007],
    ];
@endphp

<section class="grid gap-3 sm:grid-cols-3" data-ne-summary>
    <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
        <p class="text-sm text-gray-500">{{ __('Bokfört resultat (R11)') }}</p>
        <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr($calc['r12']) }}</p>
    </div>
    <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
        <p class="text-sm text-gray-500">{{ __('Schablonavdrag för egenavgifter (R43)') }}</p>
        <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $calc['r43'] === null ? '–' : $kr($calc['r43']) }}</p>
    </div>
    <div class="rounded-3xl bg-ink px-5 py-4 text-white shadow-xs">
        <p class="text-sm text-stone-300">{{ $result >= 0 ? __('Överskott av näringsverksamhet (R47)') : __('Underskott av näringsverksamhet (R48)') }}</p>
        <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums">{{ $kr(abs($result)) }}</p>
        <p class="mt-1 text-xs text-stone-300">{{ $result >= 0 ? __('Förs till punkt 10.1 i din Inkomstdeklaration 1.') : __('Förs till punkt 10.2 i din Inkomstdeklaration 1, och nästa år till R24.') }}</p>
    </div>
</section>

<p class="rounded-2xl bg-stone-100 px-4 py-3 text-sm text-gray-700">{{ __('NONOX räknar fram NE-bilagan ur bokföringen och det du fyller i här. Du kontrollerar och bekräftar varje uppgift; ingenting bokförs. Räntefördelning, periodiseringsfond och expansionsfond är val du gör, inom de gränser NONOX visar.') }}</p>

@foreach($ne->warnings() as $warning)
    <p class="rounded-2xl bg-white px-4 py-3 text-sm text-accent-800 ring-1 ring-accent-200" role="status">{{ $warning }}</p>
@endforeach

<div class="grid gap-6 xl:grid-cols-[minmax(0,32rem)_1fr]">
    <x-ui.card :title="__('Det du fyller i och bekräftar')" :description="__('Belopp i hela kronor. Tomma fält räknas som 0.')">
        <form wire:submit="save" class="space-y-6" data-ne-form>
            <fieldset class="space-y-4">
                <legend class="text-sm font-bold text-ink">{{ __('Om dig och verksamheten') }}</legend>
                <x-ui.field for="ne_century" :label="__('Du är född på')" error="century" :hint="__('Behövs för personnumret i filen och för vilken procentsats schablonavdraget för egenavgifter har. Sparas krypterat.')">
                    <select id="ne_century" class="{{ $input }} mt-1" wire:model="century"><option value="">{{ __('Välj') }}</option><option value="19">{{ __('1900-talet') }}</option><option value="20">{{ __('2000-talet') }}</option></select>
                </x-ui.field>
                <x-ui.field for="ne_activity" :label="__('Verksamhetens art')" error="inputs.activity">
                    <input id="ne_activity" type="text" maxlength="80" class="{{ $input }} mt-1" wire:model="inputs.activity">
                </x-ui.field>
                <div class="space-y-2 text-sm text-gray-700">
                    <label class="flex items-center gap-3"><x-checkbox wire:model="inputs.passive" />{{ __('Passiv näringsverksamhet (du har inte arbetat i verksamheten i betydande omfattning)') }}</label>
                    <label class="flex items-center gap-3"><x-checkbox wire:model="inputs.outside_eea" />{{ __('Verksamheten bedrivs utanför EES') }}</label>
                    <label class="flex items-center gap-3"><x-checkbox wire:model="inputs.leased_car" />{{ __('Du har dragit av kostnader för en leasad personbil eller lätt lastbil') }}</label>
                    <label class="flex items-center gap-3"><x-checkbox wire:model="inputs.first_year" />{{ __('Det här är det första året med näringsverksamheten') }}</label>
                </div>
                <x-ui.field for="ne_benefit_accounts" :label="__('Konton där bil- eller bostadsförmån är bokförd (R3)')" error="inputs.benefit_accounts" :hint="__('Till exempel 3990. Kontona flyttas från R1 eller R2 till R3. Är förmånen inte bokförd, fyll i den i R15 (intäkter som inte bokförts).')">
                    <input id="ne_benefit_accounts" type="text" class="{{ $input }} mt-1" wire:model="inputs.benefit_accounts" autocomplete="off">
                </x-ui.field>
                <x-ui.field for="ne_consultant" :label="__('Har en konsult hjälpt till med årsbokslutet?')">
                    <select id="ne_consultant" class="{{ $input }} mt-1" wire:model="consultant"><option value="">{{ __('Inte angivet') }}</option><option value="1">{{ __('Ja') }}</option><option value="0">{{ __('Nej') }}</option></select>
                </x-ui.field>
            </fieldset>

            <fieldset class="space-y-4" data-ne-interest>
                <legend class="text-sm font-bold text-ink">{{ __('Räntefördelning (R30, R31)') }}</legend>
                <p class="text-sm text-gray-600">{{ __('Kapitalunderlaget är värdet på tillgångarna minus skulderna vid förra årets slut (inkomstskattelagen 33 kap. 8–13 §§): skattemässiga värden, utan skattefordringar och skatteskulder, med periodiseringsfonder som skuld. Lägg till ett outnyttjat underskott från förra året, och dra av 79,4 % av expansionsfonden och tillskott som inte är tänkta att stanna i verksamheten. Det sparade fördelningsbeloppet fyller du i för sig nedan.') }}</p>
                <x-ui.field for="ne_capital_base" :label="__('Kapitalunderlag för räntefördelning')" error="inputs.capital_base" :hint="$estimate === null ? __('Kan vara negativt. Är det lägre än minus 500 000 kr blir det negativ räntefördelning, som måste göras.') : __('Kan vara negativt. Är det lägre än minus 500 000 kr blir det negativ räntefördelning, som måste göras. Uppskattat ur bokföringen vid förra årets slut: :amount.', ['amount' => $kr($estimate)])">
                    <input id="ne_capital_base" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="inputs.capital_base">
                </x-ui.field>
                <x-ui.field for="ne_saved_opening" :label="__('Sparat fördelningsbelopp från förra året')" error="inputs.saved_allocation_opening" :hint="__('Ruta 8011 på förra årets NE-bilaga. Används först mot en negativ räntefördelning, och det som blir kvar kan användas för positiv räntefördelning i år.')">
                    <input id="ne_saved_opening" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="inputs.saved_allocation_opening">
                </x-ui.field>
                @if($calc['positive_max'] > 0)
                    <div class="space-y-2 rounded-2xl bg-stone-50 px-4 py-3 text-sm text-gray-700" data-ne-allocation-choice>
                        <p>{{ __('Positiv räntefördelning är frivillig (inkomstskattelagen 33 kap. 6 §). Det belopp du räntefördelar beskattas som inkomst av kapital i stället för som inkomst av näringsverksamhet. Det sänker underlaget för din allmänna pension och din sjukpenninggrundande inkomst (SGI) (Skatteverket). Det du inte använder sparas till nästa år.') }}</p>
                        <p class="font-medium text-ink">{{ __('Du kan räntefördela högst :max i år.', ['max' => $kr($calc['usable'])]) }}</p>
                    </div>
                    <x-ui.field for="ne_positive_allocation" :label="__('Positiv räntefördelning du väljer att göra')" error="inputs.positive_allocation" :hint="__('Skriv 0 om du inte vill göra någon. Tomt betyder att du inte har valt än, och då görs ingen.')">
                        <input id="ne_positive_allocation" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="inputs.positive_allocation">
                    </x-ui.field>
                    @if($calc['usable'] > 0)
                        <button type="button" wire:click="$set('inputs.positive_allocation', '{{ $calc['usable'] }}')" class="text-sm font-medium text-accent-700 underline">{{ __('Fyll i det högsta beloppet, :max', ['max' => $kr($calc['usable'])]) }}</button>
                    @endif
                @endif
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-gray-600">{{ __('Positiv räntefördelning (R30)') }}</dt><dd class="text-right tabular-nums">{{ $kr($calc['r30']) }}</dd>
                    <dt class="text-gray-600">{{ __('Negativ räntefördelning (R31)') }}</dt><dd class="text-right tabular-nums">{{ $kr($calc['r31']) }}</dd>
                    <dt class="text-gray-600">{{ __('Sparas till nästa år (8011)') }}</dt><dd class="text-right tabular-nums">{{ $kr($calc['saved']) }}</dd>
                </dl>
            </fieldset>

            <fieldset class="space-y-4" data-ne-reserve>
                <legend class="text-sm font-bold text-ink">{{ __('Periodiseringsfond (R32, R34)') }}</legend>
                @foreach([7608 => null, 7709 => __('Högst 30 % av överskottet i R33: :max.', ['max' => $kr($calc['r34_max'])])] as $code => $hint)
                    <x-ui.field :for="'ne_'.$code" :label="__($neLabels[$code]).' ('.$code.')'" :error="'adjustments.'.$code" :hint="$hint">
                        <input id="ne_{{ $code }}" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="adjustments.{{ $code }}">
                    </x-ui.field>
                @endforeach
            </fieldset>

            <fieldset class="space-y-4" data-ne-expansion>
                <legend class="text-sm font-bold text-ink">{{ __('Expansionsfond (R36, R37)') }}</legend>
                <x-ui.field for="ne_expansion_base" :label="__('Kapitalunderlag för expansionsfond vid årets slut')" error="inputs.expansion_capital_base" :hint="__('Tillgångarna minus skulderna i verksamheten vid årets slut, med justeringarna i inkomstskattelagen 34 kap. 7 §. Fonden får vara högst 125,94 % av underlaget.')">
                    <input id="ne_expansion_base" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="inputs.expansion_capital_base">
                </x-ui.field>
                <x-ui.field for="ne_expansion_opening" :label="__('Expansionsfond vid årets början')" error="inputs.expansion_fund_opening">
                    <input id="ne_expansion_opening" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="inputs.expansion_fund_opening">
                </x-ui.field>
                @foreach([7710 => __('Högst :max.', ['max' => $kr($calc['r36_max'])]), 7609 => $calc['r37_forced'] > 0 ? __('Minst :amount ska återföras.', ['amount' => $kr($calc['r37_forced'] + $calc['r37'])]) : null] as $code => $hint)
                    <x-ui.field :for="'ne_'.$code" :label="__($neLabels[$code]).' ('.$code.')'" :error="'adjustments.'.$code" :hint="$hint">
                        <input id="ne_{{ $code }}" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="adjustments.{{ $code }}">
                    </x-ui.field>
                @endforeach
            </fieldset>

            <fieldset class="space-y-4" data-ne-self-employment>
                <legend class="text-sm font-bold text-ink">{{ __('Egenavgifter (R40, R41, R43)') }}</legend>
                <label class="flex items-center gap-3 text-sm text-gray-700"><x-checkbox wire:model="inputs.full_pension" />{{ __('Du har haft hel ålderspension hela året, eller hel sjuk- eller aktivitetsersättning någon del av året') }}</label>
                @foreach([7610 => __('R43 på förra årets NE-bilaga.'), 7713 => __('Står i slutskattebeskedet för förra året.'), 7714 => $calc['r43_rate'] === null ? __('Fyll i beloppet: NONOX kan inte räkna ut det här.') : __('NONOX räknar :rate % av R42: :amount. Lämna tomt för att använda det.', ['rate' => intdiv($calc['r43_rate'], 100), 'amount' => $kr(intdiv(max($calc['r42'], 0) * $calc['r43_rate'], 10000))])] as $code => $hint)
                    <x-ui.field :for="'ne_'.$code" :label="__($neLabels[$code]).' ('.$code.')'" :error="'adjustments.'.$code" :hint="$hint">
                        <input id="ne_{{ $code }}" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="adjustments.{{ $code }}">
                    </x-ui.field>
                @endforeach
            </fieldset>

            @foreach($groups as $legend => $codes)
                <details class="group rounded-2xl ring-1 ring-stone-200" @if(collect($codes)->contains(fn ($code) => ($adjustments[$code] ?? '') !== '')) open @endif>
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-4 py-2 text-sm font-bold text-ink [&::-webkit-details-marker]:hidden">
                        <svg class="size-4 transition group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                        {{ $legend }}
                    </summary>
                    <div class="space-y-4 border-t border-stone-100 px-4 py-3">
                        @foreach($codes as $code)
                            <x-ui.field :for="'ne_'.$code" :label="__($neLabels[$code] ?? $neInformation[$code] ?? '').' ('.$code.')'" :error="'adjustments.'.$code" :hint="isset($proposals[$code]) ? __('Förslag ur bokföringen: :amount.', ['amount' => $kr($proposals[$code])]) : null">
                                <input id="ne_{{ $code }}" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="adjustments.{{ $code }}">
                            </x-ui.field>
                        @endforeach
                    </div>
                </details>
            @endforeach

            <div class="flex flex-wrap items-center gap-4">
                <x-button>{{ __('Spara') }}</x-button>
                @if($ne->isVerifiedPeriod() && $ne->hasParameters())
                    <a href="{{ route('accounting.incomeTax.sru', $fiscalYear) }}" class="text-sm font-medium text-accent-700 hover:underline" data-ne-download>{{ __('Ladda ner NE-bilagan som SRU-fil') }}</a>
                @endif
            </div>
            @if($ne->isVerifiedPeriod() && $ne->hasParameters())
                <div class="space-y-2 rounded-2xl bg-stone-50 px-4 py-3 text-sm text-gray-700" data-ne-instructions>
                    <p class="font-semibold text-ink">{{ __('Så lämnar du NE-bilagan') }}</p>
                    <ol class="list-decimal space-y-1 ps-5">
                        <li>{{ __('Spara och ladda ner ZIP-filen. Packa upp den: du får BLANKETTER.SRU och INFO.SRU. Byt inte namn på filerna.') }}</li>
                        <li>{{ __('Logga in i Skatteverkets e-tjänst Inkomstdeklaration 1 med e-legitimation.') }}</li>
                        <li>{{ __('Under Bilagor väljer du Importera vid Enskild näringsverksamhet (NE) och väljer filen BLANKETTER.SRU.') }}</li>
                        <li>{{ __('Kontrollera uppgifterna, för över beloppen till Inkomstdeklaration 1 enligt tabellen och skriv under deklarationen.') }}</li>
                    </ol>
                    <table class="w-full text-xs" data-ne-transfers>
                        <caption class="sr-only">{{ __('Belopp som förs över till Inkomstdeklaration 1') }}</caption>
                        <thead><tr><th scope="col" class="py-1 text-left font-semibold">{{ __('NE-bilagan') }}</th><th scope="col" class="py-1 text-left font-semibold">{{ __('Inkomstdeklaration 1') }}</th></tr></thead>
                        <tbody>
                            @foreach(['R30' => '11.1', 'R31' => '11.2', 'R36' => '12.1', 'R37' => '12.2', 'R38' => '10.6', 'R45' => '14.1', 'R47' => '10.1 / 10.3', 'R48' => '10.2 / 10.4'] as $from => $to)
                                <tr><td class="py-0.5">{{ $from }}</td><td class="py-0.5 tabular-nums">{{ $to }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="text-xs text-gray-600">{{ __('Lämna inte en filöverförd deklaration med bara näringsuppgifterna: den kan inte kompletteras med en pappersdeklaration, och den senast lämnade deklarationen gäller.') }}</p>
                </div>
            @elseif(! $ne->isVerifiedPeriod())
                <p class="text-sm text-accent-800">{{ __('Fältkoderna för beskattningsperiod :period är inte kontrollerade ännu. Använd siffrorna här och fyll i NE-bilagan i Skatteverkets e-tjänst.', ['period' => $ne->period()]) }}</p>
            @endif
        </form>
    </x-ui.card>

    <x-ui.card :title="__('NE-bilagan, fält för fält')">
        <table class="min-w-full text-sm" data-ne-fields>
            <tbody class="divide-y divide-stone-200">
                @foreach($fields as $code => $value)
                    @continue(in_array($code, [7011, 7012], true))
                    <tr wire:key="ne-{{ $code }}">
                        <td class="w-16 py-1.5 pe-3 tabular-nums text-gray-500">{{ $code }}</td>
                        <td class="py-1.5 pe-3 text-gray-800">{{ __($neLabels[$code] ?? '') }}</td>
                        <td class="py-1.5 text-right tabular-nums font-medium text-ink">{{ is_int($value) ? $kr($value) : $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>
</div>
