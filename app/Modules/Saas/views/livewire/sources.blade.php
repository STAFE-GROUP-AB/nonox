@php
    use App\Modules\Saas\Domain\SourceType;
    use App\Modules\Saas\Support\SaasReport;
@endphp

<div class="space-y-6">
    <x-ui.page-heading :title="__('Källor och datakvalitet')" :lead="__('Det som inte kan räknas med i MRR, och varför. Ingenting lämnas bort i tysthet.')" />

    @include('saas::partials.note')

    <x-ui.card :title="__('Var siffrorna kommer ifrån')">
        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700">
            <li>{{ __('Avtalsfakturering: varje rad, utan moms och efter rabatt, omräknad till en månad.') }}</li>
            <li>{{ __('Avtalsregistret i CRM, när CRM är påslaget: avtalets värde per månad, kvartal eller år. Ett avtal som faktureras med en avtalsfakturering räknas bara där, så ingenting räknas två gånger.') }}</li>
            <li>{{ __('Engångsbelopp, fakturor utan avtal och kreditfakturor ändrar inte MRR. En kreditfaktura som hör till en ändring av avtalet syns genom ändringen.') }}</li>
        </ul>
    </x-ui.card>

    @if($missingRates !== [])
        <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200" role="status">
            {{ __('Det finns ingen kurs från Riksbanken för :currencies. Rader i de valutorna räknas inte med förrän kursen finns.', ['currencies' => implode(', ', $missingRates)]) }}
        </div>
    @endif

    <x-ui.card :title="__('Avtal som inte räknas med')">
        @if($uncounted->isEmpty())
            <x-ui.empty-state :title="__('Allt räknas med')">{{ __('Varje registrerat avtal har en kund och ett belopp som går att räkna om till en månad.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('Avtal som inte räknas med') }}</caption>
                    <thead>
                        <tr class="text-left text-gray-600">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Avtal') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Varför') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Åtgärd') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($uncounted as $version)
                            <tr>
                                <td class="py-2 pr-4">
                                    @if($version->source_type === SourceType::CrmAgreement && Route::has('crm.agreements.show'))
                                        <a href="{{ route('crm.agreements.show', ['agreement' => (int) $version->source_id]) }}" class="text-accent-700 hover:underline">{{ $version->source_type->label() }}: {{ $version->source_ref['title'] ?? '' }}</a>
                                    @elseif($version->source_type === SourceType::RecurringInvoice)
                                        <a href="{{ route('invoicing.agreements.show', ['agreement' => $version->source_id]) }}" class="text-accent-700 hover:underline">{{ $version->source_type->label() }} {{ $version->source_ref['number'] ?? '' }}</a>
                                    @else
                                        {{ $version->source_type->label() }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $version->customer?->name ?? '–' }}</td>
                                <td class="py-2 pr-4">{{ $version->excluded?->label() ?? __('Ingen kund') }}</td>
                                <td class="py-2">
                                    @if(SaasReport::needsAction($version))
                                        <x-ui.badge tone="accent">{{ __('Behöver åtgärd') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Bara en notering') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
