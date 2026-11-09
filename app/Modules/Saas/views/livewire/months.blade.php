@php
    use App\Modules\Saas\Domain\Months;
    use App\Support\Money\Money;
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Månader')" :lead="__('En öppen månad räknas om varje gång ett avtal ändras. När du stänger en månad fryses dess siffror; det som blir känt senare och gäller månaden bokas som rättelse i nästa öppna månad.')" />

    @include('saas::partials.note')

    @if($closable !== null && $canManage)
        <x-ui.card :title="__('Stäng :month', ['month' => Months::label($closable->key())])">
            <div x-data="{ confirming: false }" class="space-y-3">
                <p class="text-sm text-gray-700">{{ __('Månaden är slut och kan stängas. Påminnelsen kommer i Mina datum den :day:e i månaden efter. Ingenting stängs av sig självt.', ['day' => $reminderDay]) }}</p>
                <x-button type="button" x-show="! confirming" x-on:click="confirming = true">{{ __('Stäng månad') }}</x-button>
                <div x-show="confirming" x-cloak class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="alertdialog" aria-labelledby="saas-close-title">
                    <p id="saas-close-title" class="font-semibold">{{ __('Stänga :month?', ['month' => Months::label($closable->key())]) }}</p>
                    <p class="mt-1">{{ __('MRR :amount och alla rörelser fryses och kan inte ändras igen. En senare ändring som gäller månaden visas som rättelse i nästa öppna månad.', ['amount' => Money::ofOre($closable->mrr_ore)->format()]) }}</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <x-button type="button" wire:click="close('{{ substr($closable->key(), 0, 7) }}')" wire:loading.attr="disabled">{{ __('Ja, stäng månaden') }}</x-button>
                        <button type="button" class="text-sm text-gray-700 underline" x-on:click="confirming = false">{{ __('Avbryt') }}</button>
                    </div>
                </div>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Kontroll')">
        <p class="text-sm text-gray-700">
            @if($verified === null)
                {{ __('De stängda månaderna har inte kontrollerats ännu. Kontrollen körs varje natt.') }}
            @elseif(($verified->payload['ok'] ?? false) === true)
                {{ __('Senast kontrollerad :time: alla :count stängda månader går att räkna fram igen ur avtalsversionerna, till öret.', ['time' => $verified->created_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i'), 'count' => $verified->payload['closed_months'] ?? 0]) }}
            @else
                <span class="font-semibold text-red-800">{{ __('Kontrollen :time hittade avvikelser i :months. Kontakta supporten.', ['time' => $verified->created_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i'), 'months' => implode(', ', (array) ($verified->payload['differing_months'] ?? []))]) }}</span>
            @endif
        </p>
    </x-ui.card>

    @if($months->isEmpty())
        <x-ui.empty-state :title="__('Inga månader än')">{{ __('Månaderna räknas fram från den månad modulen slogs på.') }}</x-ui.empty-state>
    @else
        <x-ui.card>
            <x-slot:action>
                <a href="{{ route('saas.export', ['list' => 'manader']) }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ladda ner CSV') }}</a>
            </x-slot:action>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('Månader') }}</caption>
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Månad') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('MRR') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Kunder') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Stängd av') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($months as $month)
                            <tr>
                                <th scope="row" class="py-2 pr-4 text-left font-medium text-ink whitespace-nowrap">{{ Months::label($month->key()) }}</th>
                                <td class="py-2 pr-4 text-right"><x-ui.amount :money="Money::ofOre($month->mrr_ore)" /></td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $month->customers }}</td>
                                <td class="py-2 pr-4">
                                    @if($month->isClosed())
                                        <x-ui.badge tone="success">{{ __('Stängd') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Öppen') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="py-2 text-gray-700">{{ $month->isClosed() ? ($month->closer?->name ?? __('Okänd')).' · '.$month->closed_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i') : '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($historyFrom !== null)
                <p class="mt-3 text-sm text-gray-600">{{ __('Månader före :month finns bara som uppskattning ur utställda avtalsfakturor. De stängs inte och rättas inte.', ['month' => Months::label($historyFrom)]) }}</p>
            @endif
        </x-ui.card>
    @endif
</div>
