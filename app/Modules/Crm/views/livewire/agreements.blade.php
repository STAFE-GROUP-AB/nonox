@php
    $input = 'block min-h-11 sm:min-h-0 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $tabs = ['lopande' => __('Löpande'), 'loper-ut' => __('Löper ut snart'), 'avslutade' => __('Avslutade'), 'alla' => __('Alla')];
@endphp

<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center gap-3">
            @if($canManage)
                <a href="{{ route('crm.agreements.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white hover:bg-gray-800">{{ __('Nytt avtal') }}</a>
            @endif
            <input type="search" class="{{ $input }} w-full sm:max-w-xs" wire:model.live.debounce.300ms="search" placeholder="{{ __('Sök på avtal eller kund') }}" aria-label="{{ __('Sök avtal') }}">
            <div class="flex flex-wrap gap-2 text-sm" role="group" aria-label="{{ __('Visa') }}">
                @foreach($tabs as $key => $label)
                    <button type="button" wire:click="$set('show', '{{ $key }}')" @class(['inline-flex min-h-11 items-center rounded-xl px-4 font-medium', 'bg-ink text-white' => $show === $key, 'bg-white text-ink ring-1 ring-stone-200' => $show !== $key]) aria-pressed="{{ $show === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            @if($show === 'loper-ut')
                <label class="flex items-center gap-2 text-sm">
                    <span>{{ __('inom') }}</span>
                    <select class="{{ $input }}" wire:model.live="within" aria-label="{{ __('Antal dagar') }}">
                        @foreach($windows as $days)
                            <option value="{{ $days }}">{{ __(':days dagar', ['days' => $days]) }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        @if($agreements->isEmpty())
            <x-ui.empty-state class="mt-6" :title="__('Inga avtal att visa')">
                {{ $search !== '' ? __('Inget avtal matchar sökningen.') : __('Lägg upp kundernas avtal med startdatum, slutdatum, uppsägningstid och förlängning. Du får en påminnelse innan det är dags att säga upp eller omförhandla.') }}
            </x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm" data-testid="crm-agreements">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-600">
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Avtal') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Period') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Säg upp senast') }}</th>
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('Förlängning') }}</th>
                            <th scope="col" class="py-2 pr-3 text-right font-medium">{{ __('Värde') }}</th>
                            <th scope="col" class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($agreements as $agreement)
                            @php($deadline = $agreement->noticeDeadline())
                            @php($status = $agreement->status($today))
                            <tr wire:key="agreement-{{ $agreement->id }}" class="border-b border-gray-100 align-top">
                                <td class="py-3 pr-3">
                                    <a href="{{ route('crm.agreements.show', $agreement) }}" class="font-semibold text-gray-900 hover:text-accent-700">{{ $agreement->title }}</a>
                                    <span class="block text-gray-600">{{ $agreement->account?->name }} · {{ $agreement->kind->label() }}</span>
                                </td>
                                <td class="whitespace-nowrap py-3 pr-3 tabular-nums">{{ $agreement->start_date->toDateString() }} – {{ $agreement->end_date?->toDateString() ?? __('tills vidare') }}</td>
                                <td @class(['whitespace-nowrap py-3 pr-3 tabular-nums', 'font-semibold text-accent-700' => $deadline !== null && $deadline->lessThanOrEqualTo($today->addDays(60)) && $status !== App\Modules\Crm\Models\Agreement::ENDED])>{{ $deadline?->toDateString() ?? '–' }}</td>
                                <td class="py-3 pr-3">{{ $agreement->renews() ? __(':months mån. i taget', ['months' => $agreement->renewal_months]) : __('Nej') }}</td>
                                <td class="py-3 pr-3 text-right tabular-nums">{{ $agreement->value_ore > 0 ? App\Support\Money\Money::ofOre($agreement->value_ore)->format().' '.$agreement->value_period->label() : '–' }}</td>
                                <td class="py-3">
                                    <x-ui.badge :tone="$status === App\Modules\Crm\Models\Agreement::ACTIVE ? 'success' : 'neutral'">{{ App\Modules\Crm\Models\Agreement::statusLabel($status) }}</x-ui.badge>
                                    @if($agreement->signed_at)<span class="mt-1 block text-xs text-gray-600">{{ __('Signerat') }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $agreements->links() }}</div>
        @endif
    </x-ui.card>
</div>
