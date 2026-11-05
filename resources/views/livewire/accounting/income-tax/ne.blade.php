{{-- The NE-bilaga for a sole trader (App\Domain\IncomeTax\Ne, ADR-053). --}}
@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $kronor): string => number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    $calc = $ne->calculation();
    $fields = $ne->ne();
    $result = $calc['result'];
    $groups = [
        __('Justeringar av det bokförda resultatet') => [7601, 7700, 7602, 7701, 7402],
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
                </div>
                <x-ui.field for="ne_consultant" :label="__('Har en konsult hjälpt till med årsbokslutet?')">
                    <select id="ne_consultant" class="{{ $input }} mt-1" wire:model="consultant"><option value="">{{ __('Inte angivet') }}</option><option value="1">{{ __('Ja') }}</option><option value="0">{{ __('Nej') }}</option></select>
                </x-ui.field>
            </fieldset>

            <fieldset class="space-y-4" data-ne-interest>
                <legend class="text-sm font-bold text-ink">{{ __('Räntefördelning (R30, R31)') }}</legend>
                <p class="text-sm text-gray-600">{{ __('Kapitalunderlaget är tillgångarnas skattemässiga värde minus skulderna vid förra årets slut, utan skattefordringar och skatteskulder, med periodiseringsfonder som skuld och minskat med 79,4 % av expansionsfonden. Ett sparat fördelningsbelopp från förra året läggs till.') }}</p>
                <x-ui.field for="ne_capital_base" :label="__('Kapitalunderlag för räntefördelning')" error="inputs.capital_base" :hint="__('Kan vara negativt. Är det lägre än minus 500 000 kr blir det negativ räntefördelning, som måste göras.')">
                    <input id="ne_capital_base" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="inputs.capital_base">
                </x-ui.field>
                @if($calc['positive_max'] > 0)
                    <x-ui.field for="ne_positive_allocation" :label="__('Positiv räntefördelning du vill använda')" error="inputs.positive_allocation" :hint="__('Högst :max (även högst överskottet i R29). Lämna tomt för det högsta beloppet. Det du inte använder sparas till nästa år.', ['max' => $kr(min($calc['positive_max'], max($calc['r29'], 0)))])">
                        <input id="ne_positive_allocation" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="inputs.positive_allocation">
                    </x-ui.field>
                @endif
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-gray-600">{{ __('Positiv räntefördelning (R30)') }}</dt><dd class="text-right tabular-nums">{{ $kr($calc['r30']) }}</dd>
                    <dt class="text-gray-600">{{ __('Negativ räntefördelning (R31)') }}</dt><dd class="text-right tabular-nums">{{ $kr($calc['r31']) }}</dd>
                    <dt class="text-gray-600">{{ __('Sparat fördelningsbelopp') }}</dt><dd class="text-right tabular-nums">{{ $kr($calc['saved']) }}</dd>
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
                @foreach([7710 => __('Högst :max.', ['max' => $kr((int) $calc['r36_max'])]), 7609 => null] as $code => $hint)
                    <x-ui.field :for="'ne_'.$code" :label="__($neLabels[$code]).' ('.$code.')'" :error="'adjustments.'.$code" :hint="$hint">
                        <input id="ne_{{ $code }}" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="adjustments.{{ $code }}">
                    </x-ui.field>
                @endforeach
            </fieldset>

            <fieldset class="space-y-4" data-ne-self-employment>
                <legend class="text-sm font-bold text-ink">{{ __('Egenavgifter (R40, R41, R43)') }}</legend>
                @foreach([7610 => __('R43 på förra årets NE-bilaga.'), 7713 => __('Står i slutskattebeskedet för förra året.'), 7714 => $calc['r43_rate'] === null ? __('Fyll i beloppet: NONOX kan inte räkna ut det här.') : __('NONOX räknar :rate % av R42: :amount. Lämna tomt för att använda det.', ['rate' => number_format($calc['r43_rate'] / 100, 0, ',', ''), 'amount' => $kr(intdiv(max($calc['r42'], 0) * $calc['r43_rate'], 10000))])] as $code => $hint)
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
                            <x-ui.field :for="'ne_'.$code" :label="__($neLabels[$code] ?? $neInformation[$code] ?? '').' ('.$code.')'" :error="'adjustments.'.$code">
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
                        <li>{{ __('Kontrollera uppgifterna, för över överskottet eller underskottet till punkt 10 och skriv under deklarationen.') }}</li>
                    </ol>
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
