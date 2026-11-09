@php
    use App\Modules\Saas\Domain\Months;
    use App\Modules\Saas\Support\SaasReport;
    use App\Support\Money\Money;
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Återkommande intäkter')" :lead="__('Hur den återkommande intäkten från avtal och avtalsfakturering rör sig: nya kunder, utökningar, minskningar och kunder som slutar.')">
        <x-slot:action>
            @include('saas::partials.month-picker')
        </x-slot:action>
    </x-ui.page-heading>

    @include('saas::partials.note')

    @if($issues > 0)
        <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="status">
            {{ trans_choice('{1} En sak i underlaget behöver granskas.|[2,*] :count saker i underlaget behöver granskas.', $issues, ['count' => $issues]) }}
            <a href="{{ route('saas.sources') }}" class="font-semibold underline">{{ __('Se Källor') }}</a>
        </div>
    @endif

    @if($selected === null || ! $hasData)
        <x-ui.empty-state :title="__('Inga återkommande intäkter än')">
            {{ __('Siffrorna räknas fram ur avtalsfakturering och, om CRM är påslaget, avtalsregistret. Lägg upp ett avtal så syns det här inom någon minut.') }}
            <x-slot:action>
                <a href="{{ route('invoicing.agreements.create') }}" class="inline-flex items-center rounded-lg bg-accent-700 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-800">{{ __('Nytt avtal') }}</a>
            </x-slot:action>
        </x-ui.empty-state>
    @else
        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Nyckeltal för :month', ['month' => Months::label($selected->key())]) }}">
            <div class="rounded-3xl bg-ink px-5 py-4 text-white shadow-xs">
                <span class="block text-sm text-stone-300">{{ __('MRR') }}</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums">{{ Money::ofOre($selected->mrr_ore)->format() }}</span>
                <span class="mt-1 block text-xs text-stone-300">{{ $selected->isClosed() ? __('Stängd månad') : __('Öppen månad, räknas om vid varje ändring') }}</span>
            </div>
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <span class="block text-sm text-gray-500">{{ __('ARR (årstakt)') }}</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums text-ink">{{ Money::ofOre($selected->arrOre())->format() }}</span>
                <span class="mt-1 block text-xs text-gray-500">{{ __('MRR gånger 12, ingen prognos') }}</span>
            </div>
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <span class="block text-sm text-gray-500">{{ __('Kunder') }}</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums text-ink">{{ number_format($selected->customers, 0, ',', ' ') }}</span>
                <span class="mt-1 block text-xs text-gray-500">{{ __('med MRR vid månadens slut') }}</span>
            </div>
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <span class="block text-sm text-gray-500">{{ __('ARPA') }}</span>
                <span class="mt-1 block text-2xl font-bold tabular-nums text-ink">{{ $selected->arpaOre() === null ? '–' : Money::ofOre($selected->arpaOre())->format() }}</span>
                <span class="mt-1 block text-xs text-gray-500">{{ __('MRR per kund') }}</span>
            </div>
        </section>

        <x-ui.card :title="__('Churn och kvarhållen intäkt, :month', ['month' => Months::label($selected->key())])">
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <dt class="text-sm text-gray-600">{{ __('Kundchurn') }}</dt>
                    <dd class="text-lg font-semibold tabular-nums text-ink">{{ $rates['logo'] ?? '–' }}</dd>
                    <dd class="text-xs text-gray-500">{{ __(':churned av :customers kunder', ['churned' => $selected->churned_customers, 'customers' => $selected->opening_customers]) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">{{ __('Brutto intäktschurn') }}</dt>
                    <dd class="text-lg font-semibold tabular-nums text-ink">{{ $rates['gross'] ?? '–' }}</dd>
                    <dd class="text-xs text-gray-500">{{ __('(Minskning + Churn) ÷ ingående MRR') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">{{ __('Netto intäktschurn') }}</dt>
                    <dd class="text-lg font-semibold tabular-nums text-ink">{{ $rates['net'] ?? '–' }}</dd>
                    <dd class="text-xs text-gray-500">{{ str_starts_with((string) $rates['net'], '−') ? __('Negativ churn: utökningarna är större än förlusterna') : __('(Minskning + Churn − Utökning) ÷ ingående MRR') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">{{ __('NRR (12 mån)') }}</dt>
                    <dd class="text-lg font-semibold tabular-nums text-ink">{{ $rates['nrr'] ?? '–' }}</dd>
                    <dd class="text-xs text-gray-500">{{ $rates['nrr'] === null ? __('Kräver 12 månaders historik') : __('Kunderna för 12 månader sedan, deras MRR nu') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-600">{{ __('GRR (12 mån)') }}</dt>
                    <dd class="text-lg font-semibold tabular-nums text-ink">{{ $rates['grr'] ?? '–' }}</dd>
                    <dd class="text-xs text-gray-500">{{ __('Som NRR, utan utökningar') }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-gray-600">{{ __('Paus, återaktivering, nya kunder, valutaeffekt och rättelser räknas inte in i churn. NRR och GRR räknas med månadens valutakurser för båda åren, så kursändringar räknas inte som kvarhållen intäkt.') }}</p>
        </x-ui.card>

        <x-ui.card :title="__('Från förra månadens MRR till denna')" :description="__('Varje rad öppnar kunderna bakom beloppet. Raderna summerar till öret.')">
            <x-slot:action>
                <a href="{{ route('saas.export', ['list' => 'rorelser']) }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Ladda ner CSV') }}</a>
            </x-slot:action>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('MRR-brygga för :month', ['month' => Months::label($selected->key())]) }}</caption>
                    <tbody class="divide-y divide-stone-100">
                        <tr>
                            <th scope="row" class="py-2 pr-4 text-left font-medium text-gray-700">{{ $selected->isClosed() || $previous === null || ! $previous->isClosed() ? __('Ingående MRR') : __('Ingående MRR, som rapporterad') }}</th>
                            <td class="py-2 text-right"><x-ui.amount :money="Money::ofOre($selected->opening_mrr_ore)" /></td>
                        </tr>
                        @foreach(SaasReport::CATEGORIES as $category => $column)
                            @php($value = (int) $selected->getAttribute($column))
                            @continue($value === 0 && in_array($category, ['rattelse', 'valutaeffekt', 'aterupptagen', 'paus'], true))
                            <tr>
                                <th scope="row" class="py-2 pr-4 text-left font-normal text-gray-700">
                                    <a href="{{ route('saas.movements.show', ['month' => substr($selected->key(), 0, 7), 'kategori' => $category]) }}" class="hover:text-accent-700 hover:underline">{{ SaasReport::label($category) }}</a>
                                </th>
                                <td class="py-2 text-right"><x-ui.amount :money="Money::ofOre($value)" /></td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <th scope="row" class="py-2 pr-4 text-left text-ink">{{ __('MRR vid månadens slut') }}</th>
                            <td class="py-2 text-right text-ink"><x-ui.amount :money="Money::ofOre($selected->mrr_ore)" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            @if($selected->correction_ore !== 0)
                <p class="mt-3 text-sm text-gray-600">{{ __('Rättelse är det som blev känt efter att förra månaden stängdes. Den stängda månaden är oförändrad.') }} <a href="{{ route('saas.corrections') }}" class="font-semibold text-accent-700 underline">{{ __('Se rättelserna') }}</a></p>
            @endif
            @if($selected->scheduled_churn_ore !== 0)
                <p class="mt-3 text-sm text-gray-600">{{ __('Uppsagd MRR som fortfarande löper: :amount.', ['amount' => Money::ofOre($selected->scheduled_churn_ore)->format()]) }} <a href="{{ route('saas.scheduled') }}" class="font-semibold text-accent-700 underline">{{ __('Se uppsagda avtal') }}</a></p>
            @endif
        </x-ui.card>

        <x-ui.card :title="__('MRR per månad')">
            <x-slot:action>
                <div class="inline-flex rounded-lg ring-1 ring-stone-300" role="group" aria-label="{{ __('Visa stängda månader') }}">
                    <button type="button" wire:click="$set('show', 'rapporterat')" aria-pressed="{{ $withCorrections ? 'false' : 'true' }}" @class(['rounded-l-lg px-3 py-1.5 text-sm font-medium', 'bg-ink text-white' => ! $withCorrections, 'text-gray-700 hover:bg-stone-50' => $withCorrections])>{{ __('Som rapporterat') }}</button>
                    <button type="button" wire:click="$set('show', 'med-rattelser')" aria-pressed="{{ $withCorrections ? 'true' : 'false' }}" @class(['rounded-r-lg px-3 py-1.5 text-sm font-medium', 'bg-ink text-white' => $withCorrections, 'text-gray-700 hover:bg-stone-50' => ! $withCorrections])>{{ __('Med rättelser') }}</button>
                </div>
            </x-slot:action>
            <p class="text-sm text-gray-600">
                {{ $withCorrections
                    ? __('Stängda månader visas som de ser ut i dag, med rättelserna som har kommit efteråt. De lagrade månaderna ändras inte.')
                    : __('Stängda månader visas som de rapporterades när de stängdes.') }}
            </p>
            <div class="mt-6 flex h-48 items-end gap-1" aria-hidden="true">
                @foreach($chart as $key => $point)
                    <div class="flex h-full min-w-0 flex-1 flex-col justify-end" title="{{ Months::label($key) }}: {{ Money::ofOre($point['mrr_ore'])->format() }}">
                        <div @class([
                            'w-full rounded-t',
                            'bg-stone-300' => $point['kind'] === 'estimate',
                            'bg-accent-600' => $point['kind'] === 'ledger' && ! $point['corrected'],
                            'bg-amber-500' => $point['corrected'],
                        ]) style="height: {{ max(1, intdiv(100 * max(0, $point['mrr_ore']), $chartMax)) }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex flex-wrap gap-4 text-xs text-gray-600" aria-hidden="true">
                <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-accent-600"></span>{{ __('Räknat ur avtalen') }}</span>
                <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-stone-300"></span>{{ __('Uppskattad ur utställda fakturor') }}</span>
                @if($withCorrections)
                    <span class="inline-flex items-center gap-1"><span class="inline-block h-3 w-3 rounded-sm bg-amber-500"></span>{{ __('Ändrad av rättelser') }}</span>
                @endif
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('MRR per månad') }}</caption>
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Månad') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('MRR') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach(array_reverse($chart, true) as $key => $point)
                            <tr>
                                <td class="py-1.5 pr-4">{{ Months::label($key) }}</td>
                                <td class="py-1.5 pr-4 text-right"><x-ui.amount :money="Money::ofOre($point['mrr_ore'])" /></td>
                                <td class="py-1.5">
                                    @if($point['kind'] === 'estimate')
                                        <x-ui.badge>{{ __('Uppskattad') }}</x-ui.badge>
                                    @elseif($point['corrected'])
                                        <x-ui.badge tone="accent">{{ __('Stängd, med rättelser') }}</x-ui.badge>
                                    @elseif($point['closed'])
                                        <x-ui.badge tone="success">{{ __('Stängd') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Öppen') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
