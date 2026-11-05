{{-- INK2 for a limited company or an ekonomisk förening (Ink2, ADR-010). --}}
@php
    $input = 'block w-full rounded-xl border-stone-300 shadow-xs focus:border-accent-500 focus:ring-accent-500';
    $kr = fn (int $kronor): string => number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
@endphp
    @php($taxable = $ink2->taxableKronor())
    <section class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Bokfört resultat') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr($ink2->resultKronor()) }}</p>
        </div>
        <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
            <p class="text-sm text-gray-500">{{ __('Bokförd skatt') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums text-ink">{{ $kr($ink2->taxKronor()) }}</p>
        </div>
        <div class="rounded-3xl bg-ink px-5 py-4 text-white shadow-xs">
            <p class="text-sm text-stone-300">{{ $taxable >= 0 ? __('Överskott av näringsverksamhet') : __('Underskott av näringsverksamhet') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight tabular-nums">{{ $kr(abs($taxable)) }}</p>
        </div>
    </section>

    @foreach($ink2->warnings() as $warning)
        <p class="rounded-2xl bg-white px-4 py-3 text-sm text-accent-800 ring-1 ring-accent-200" role="status">{{ $warning }}</p>
    @endforeach

    <div class="grid gap-6 xl:grid-cols-[minmax(0,28rem)_1fr]">
        <x-ui.card :title="__('Skattemässiga justeringar')" :description="__('Belopp i hela kronor, utan minustecken. Skatten på årets resultat läggs tillbaka av sig själv.')">
            <form wire:submit="save" class="space-y-4">
                @foreach($fieldLabels as $code => $label)
                    @php($proposal = array_key_exists($code, $proposals) && $proposals[$code] !== 0 ? $proposals[$code] : false)
                    <x-ui.field :for="'adj_'.$code" :label="__($label).' ('.$code.')'" :error="'adjustments.'.$code" :hint="$proposal === false ? null : ($proposal === null ? __('Inget förslag: underlaget för året saknas här.') : __('Förslag från bokföringen: :amount. Lämna tomt för att använda förslaget, skriv 0 om fältet inte ska fyllas i.', ['amount' => $kr($proposal)]))">
                        <input id="adj_{{ $code }}" type="text" inputmode="numeric" class="{{ $input }} mt-1" wire:model="adjustments.{{ $code }}" @if(is_int($proposal)) placeholder="{{ number_format($proposal, 0, ',', ' ') }}" @endif>
                    </x-ui.field>
                @endforeach
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field for="tax_consultant" :label="__('Har en konsult hjälpt till med årsredovisningen?')">
                        <select id="tax_consultant" class="{{ $input }} mt-1" wire:model="consultant"><option value="">{{ __('Inte angivet') }}</option><option value="1">{{ __('Ja') }}</option><option value="0">{{ __('Nej') }}</option></select>
                    </x-ui.field>
                    <x-ui.field for="tax_audited" :label="__('Har årsredovisningen reviderats?')">
                        <select id="tax_audited" class="{{ $input }} mt-1" wire:model="audited"><option value="">{{ __('Inte angivet') }}</option><option value="1">{{ __('Ja') }}</option><option value="0">{{ __('Nej') }}</option></select>
                    </x-ui.field>
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <x-button>{{ __('Spara') }}</x-button>
                    @if($ink2->isVerifiedPeriod())
                        <a href="{{ route('accounting.incomeTax.sru', $fiscalYear) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ladda ner SRU-filer') }}</a>
                    @endif
                </div>
                @if($ink2->isVerifiedPeriod())
                    <p class="text-xs text-gray-500">{{ __('ZIP-filen innehåller INFO.SRU och BLANKETTER.SRU för beskattningsperiod :period. Packa upp och lämna båda filerna i Skatteverkets e-tjänst Filöverföring. Kontrollera uppgifterna där innan du skriver under.', ['period' => $ink2->period()]) }}</p>
                @else
                    <p class="text-sm text-accent-800">{{ __('Fältkoderna för beskattningsperiod :period är inte kontrollerade ännu. Använd siffrorna här och fyll i deklarationen i Skatteverkets e-tjänst.', ['period' => $ink2->period()]) }}</p>
                @endif
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Deklarationen, fält för fält')">
            <div class="space-y-6">
                @foreach($forms as $form => $fields)
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ $form }}-{{ $ink2->period() }}</p>
                        <table class="mt-2 min-w-full text-sm">
                            <tbody class="divide-y divide-stone-200">
                                @foreach($fields as $code => $value)
                                    @continue(in_array($code, [7011, 7012], true))
                                    <tr wire:key="{{ $form }}-{{ $code }}">
                                        <td class="w-16 py-1.5 pe-3 tabular-nums text-gray-500">{{ $code }}</td>
                                        <td class="py-1.5 pe-3 text-gray-800">{{ __($labels[$code] ?? '') }}</td>
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
