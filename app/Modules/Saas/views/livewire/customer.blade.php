@php
    use App\Modules\Saas\Domain\Months;
    use App\Modules\Saas\Domain\RevenueLine;
    use App\Support\Money\Money;

    $latest = $months->first();
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="$customer->name" :lead="__('Kundens återkommande intäkt månad för månad, varje version av avtalen och dokumenten bakom.')">
        <x-slot:action>
            <a href="{{ route('saas.customers') }}" class="text-sm font-semibold text-accent-700 hover:text-accent-800">{{ __('Alla kunder') }}</a>
        </x-slot:action>
    </x-ui.page-heading>

    @include('saas::partials.note')

    @if($latest !== null)
        <dl class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <dt class="text-sm text-gray-500">{{ __('MRR, :month', ['month' => Months::label($latest->month->toDateString())]) }}</dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ Money::ofOre($latest->mrr_ore)->format() }}</dd>
            </div>
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <dt class="text-sm text-gray-500">{{ __('Kohort') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-ink">{{ $latest->cohort_month === null ? '–' : Months::label($latest->cohort_month->toDateString()) }}</dd>
            </div>
            <div class="rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200">
                <dt class="text-sm text-gray-500">{{ __('Status') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-ink">{{ $latest->state->label() }}</dd>
                @if($latest->scheduled_churn_month !== null)
                    <dd class="mt-1 text-xs text-gray-600">{{ __('Uppsagt: MRR :amount upphör :month.', ['amount' => Money::ofOre($latest->scheduled_churn_ore)->format(), 'month' => Months::label($latest->scheduled_churn_month->toDateString())]) }}</dd>
                @endif
            </div>
        </dl>
    @endif

    <x-ui.card :title="__('MRR per månad')">
        @if($months->isEmpty() && $estimates->isEmpty())
            <x-ui.empty-state :title="__('Ingen återkommande intäkt')">{{ __('Kunden har inget avtal som räknas.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('MRR per månad för :name', ['name' => $customer->name]) }}</caption>
                    <thead>
                        <tr class="text-right text-gray-600">
                            <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Månad') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Ingående') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Rättelse') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Valutaeffekt') }}</th>
                            <th scope="col" class="py-2 pr-4 text-left font-medium">{{ __('Rörelse') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Belopp') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('MRR') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 text-right">
                        @foreach($months as $row)
                            <tr>
                                <th scope="row" class="py-1.5 pr-4 text-left font-normal whitespace-nowrap">{{ Months::label($row->month->toDateString()) }}</th>
                                <td class="py-1.5 pr-4"><x-ui.amount :money="Money::ofOre($row->opening_ore)" /></td>
                                <td class="py-1.5 pr-4">@if($row->correction_ore !== 0)<x-ui.amount :money="Money::ofOre($row->correction_ore)" />@else<span class="text-gray-400">–</span>@endif</td>
                                <td class="py-1.5 pr-4">@if($row->fx_ore !== 0)<x-ui.amount :money="Money::ofOre($row->fx_ore)" />@else<span class="text-gray-400">–</span>@endif</td>
                                <td class="py-1.5 pr-4 text-left">{{ $row->movement?->label() ?? '–' }}</td>
                                <td class="py-1.5 pr-4">@if($row->movement_ore !== 0)<x-ui.amount :money="Money::ofOre($row->movement_ore)" />@else<span class="text-gray-400">–</span>@endif</td>
                                <td class="py-1.5 font-semibold"><x-ui.amount :money="Money::ofOre($row->mrr_ore)" /></td>
                            </tr>
                        @endforeach
                        @foreach($estimates as $estimate)
                            <tr class="text-gray-600">
                                <th scope="row" class="py-1.5 pr-4 text-left font-normal whitespace-nowrap">{{ Months::label($estimate->month->toDateString()) }} <x-ui.badge>{{ __('Uppskattad') }}</x-ui.badge></th>
                                <td colspan="5" class="py-1.5 pr-4 text-left text-xs">{{ __('Ur :count utställda fakturor', ['count' => count($estimate->invoice_ids)]) }}</td>
                                <td class="py-1.5"><x-ui.amount :money="Money::ofOre($estimate->mrr_ore)" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Avtalsversioner')" :description="__('Varje känt läge för kundens avtal. Gäller från är när ändringen gäller; registrerad är när NONOX fick veta den. En version ändras aldrig.')">
        @if($versions->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga avtal är registrerade för kunden.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('Avtalsversioner') }}</caption>
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Avtal') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Händelse') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Gäller från') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Registrerad') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Slutar') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Rader per period, utan moms') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 align-top">
                        @foreach($versions as $version)
                            <tr>
                                <td class="py-2 pr-4">{{ $version->source_type->label() }} · {{ $labels->contract($version->source_type, $version->source_id) }}</td>
                                <td class="py-2 pr-4">{{ $version->cause->label() }}@if($version->status->value === 'PAUSED') · {{ $version->status->label() }}@endif @if($version->excluded !== null)<span class="block text-xs text-gray-600">{{ $version->excluded->label() }}</span>@endif</td>
                                <td class="py-2 pr-4 whitespace-nowrap tabular-nums">{{ $version->effective_from->toDateString() }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap tabular-nums">{{ $version->recorded_at->timezone('Europe/Stockholm')->format('Y-m-d H:i') }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap tabular-nums">{{ $version->cancel_effective_on?->subDay()->toDateString() ?? '–' }}</td>
                                <td class="py-2">
                                    @foreach($version->lines as $data)
                                        @php($line = RevenueLine::fromArray($data))
                                        <span class="block">{{ $labels->line($version->source_type, $version->source_id, $line->key) }}: {{ Money::ofOre($line->amountMinor)->format(false) }} {{ $line->currency }} / {{ trans_choice('{1} månad|[2,*] :count månader', $line->intervalCount * ($line->intervalUnit === 'year' ? 12 : 1), ['count' => $line->intervalCount * ($line->intervalUnit === 'year' ? 12 : 1)]) }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($corrections->isNotEmpty())
        <x-ui.card :title="__('Rättelser')">
            <ul class="divide-y divide-stone-100 text-sm">
                @foreach($corrections as $correction)
                    <li class="flex flex-wrap justify-between gap-2 py-2">
                        <span>{{ __('Bokad i :booked, gäller :affects', ['booked' => Months::label($correction->booked_month->toDateString()), 'affects' => Months::label($correction->affects_month->toDateString())]) }}</span>
                        <x-ui.amount :money="Money::ofOre($correction->amount_ore)" />
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Dokument')">
        @if($agreements->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Kundens avtal i avtalsregistret visas under Kunder → Avtal i CRM.') }}</p>
        @else
            <h3 class="text-sm font-semibold text-ink">{{ __('Avtalsfakturering') }}</h3>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach($agreements as $agreement)
                    <li><a href="{{ route('invoicing.agreements.show', ['agreement' => $agreement->id]) }}" class="text-accent-700 hover:underline">{{ $agreement->title() }}: {{ $agreement->name }}</a> · {{ $agreement->intervalLabel() }} · {{ $agreement->status->label() }}</li>
                @endforeach
            </ul>
            @if($canSeeInvoices)
                <h3 class="mt-5 text-sm font-semibold text-ink">{{ __('Fakturor och kreditfakturor från avtalen') }}</h3>
                @if($invoices->isEmpty())
                    <p class="mt-2 text-sm text-gray-600">{{ __('Inga utställda fakturor än.') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-stone-100 text-sm">
                        @foreach($invoices as $invoice)
                            <li class="flex flex-wrap justify-between gap-2 py-1.5">
                                <a href="{{ route('invoicing.invoices.show', $invoice) }}" class="text-accent-700 hover:underline">{{ $invoice->title() }}</a>
                                <span class="tabular-nums text-gray-600">{{ $invoice->invoice_date->toDateString() }}</span>
                                <x-ui.amount :money="$invoice->net_ore" />
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-gray-500">{{ __('Belopp utan moms. En kreditfaktura ändrar inte MRR; det gör bara en ändring av avtalet.') }}</p>
                @endif
            @endif
        @endif
    </x-ui.card>
</div>
