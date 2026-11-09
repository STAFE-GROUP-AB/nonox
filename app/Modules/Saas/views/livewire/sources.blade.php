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
            {{ __('Riksbanken har ingen kurs för :currencies vid månadens slut. Den senaste kända kursen används tills den finns, och månaden kan inte stängas förrän dess.', ['currencies' => implode(', ', $missingRates)]) }}
        </div>
    @endif

    @if($doubles !== [])
        <x-ui.card :title="__('Möjlig dubbelräkning')" :description="__('Kunden har både ett avtal i avtalsregistret och en avtalsfakturering som inte är kopplade till varandra. Gäller de samma sak räknas intäkten två gånger. Koppla avtalet i registret till avtalsfaktureringen, så räknas den bara där. Är det olika avtal markerar du dem som separata. NONOX gissar inte.')">
            <ul class="divide-y divide-stone-100 text-sm">
                @foreach($doubles as $customerId => $pairs)
                    @foreach($pairs as $pair)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>
                                <span class="font-medium text-ink">{{ $customers->get($customerId)?->name ?? __('Kund :id', ['id' => $customerId]) }}</span>:
                                @if(Route::has('crm.agreements.show'))
                                    <a href="{{ route('crm.agreements.show', ['agreement' => (int) $pair['crm']]) }}" class="text-accent-700 hover:underline">{{ __('Koppla :agreement', ['agreement' => $labels->contract(SourceType::CrmAgreement, $pair['crm'])]) }}</a>
                                @else
                                    {{ $labels->contract(SourceType::CrmAgreement, $pair['crm']) }}
                                @endif
                                · {{ $labels->contract(SourceType::RecurringInvoice, $pair['recurring']) }}
                            </span>
                            @if($canManage)
                                <button type="button" wire:click="markSeparate({{ (int) $pair['crm'] }}, '{{ $pair['recurring'] }}')" wire:loading.attr="disabled" class="text-sm font-medium text-gray-700 underline">{{ __('Markera som separata avtal') }}</button>
                            @endif
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if($separate->isNotEmpty())
        <x-ui.card :title="__('Markerade som separata avtal')">
            <ul class="divide-y divide-stone-100 text-sm">
                @foreach($separate as $pair)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>{{ $labels->contract(SourceType::CrmAgreement, (string) $pair->crm_agreement_id) }} · {{ $labels->contract(SourceType::RecurringInvoice, $pair->recurring_invoice_id) }}</span>
                        @if($canManage)
                            <button type="button" wire:click="undoSeparate({{ $pair->id }})" class="text-sm font-medium text-gray-700 underline">{{ __('Ångra') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if($negative->isNotEmpty())
        <x-ui.card :title="__('Kunder med negativ återkommande intäkt')" :description="__('Kundens avtalsrader blir tillsammans mindre än noll, till exempel en rabattrad som är större än priset. Kunden räknas som 0 kr tills avtalet är rättat.')">
            <ul class="divide-y divide-stone-100 text-sm">
                @foreach($negative as $row)
                    <li class="py-2"><a href="{{ route('saas.customers.show', ['customer' => $row->customer_id]) }}" class="text-accent-700 hover:underline">{{ $row->customer?->name ?? __('Kund :id', ['id' => $row->customer_id]) }}</a></li>
                @endforeach
            </ul>
        </x-ui.card>
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
                                        <a href="{{ route('crm.agreements.show', ['agreement' => (int) $version->source_id]) }}" class="text-accent-700 hover:underline">{{ $version->source_type->label() }}: {{ $labels->contract($version->source_type, $version->source_id) }}</a>
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
