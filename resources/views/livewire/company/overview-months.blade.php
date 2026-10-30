@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $tab = 'manader';
    $monthNames = [1 => 'jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    $status = [
        'REPORTED' => ['✓', 'text-green-800', __('redovisad')],
        'LATE' => ['!', 'text-accent-800 font-semibold', __('försenad')],
        'DUE' => ['○', 'text-gray-700', __('att redovisa')],
        'OPEN' => ['·', 'text-gray-400', __('pågår')],
    ];
@endphp
<div class="space-y-6">
    @include('livewire.company.overview-tabs')
    <x-ui.field for="company_search_months" :label="__('Sök företag')" class="max-w-md">
        <input id="company_search_months" type="search" class="{{ $input }} mt-1" wire:model.live.debounce.300ms="search" placeholder="{{ __('Namn eller organisationsnummer') }}">
    </x-ui.field>
    <p class="text-sm text-gray-600">{{ __('Per månad: antal bokförda verifikationer, om perioden är låst, och om momsen och arbetsgivardeklarationen för månaden är redovisade.') }}</p>

    @if($rows === [])
        <x-ui.card>
            <x-ui.empty-state :title="__('Inget företag matchar sökningen')">{{ __('Sök på en del av namnet eller organisationsnumret.') }}</x-ui.empty-state>
        </x-ui.card>
    @else
        <x-ui.card>
            <div class="-mx-4 overflow-x-auto sm:mx-0">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="sticky left-0 bg-white py-2 pe-4 font-medium">{{ __('Företag') }}</th>
                            @foreach($months as $month)
                                @php([$y, $m] = explode('-', $month))
                                <th class="px-3 py-2 text-center font-medium tabular-nums">{{ $monthNames[(int) $m] }} {{ $y }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($rows as $row)
                            @php($company = $row['company'])
                            <tr wire:key="month-row-{{ $company->id }}">
                                <td class="sticky left-0 bg-white py-3 pe-4 align-top">
                                    <form method="POST" action="{{ route('current-team.update') }}">
                                        @method('PUT')
                                        @csrf
                                        <input type="hidden" name="team_id" value="{{ $company->id }}">
                                        <input type="hidden" name="till" value="dashboard">
                                        <button type="submit" class="text-left font-medium text-gray-900 hover:text-accent-700">{{ $company->name }}</button>
                                    </form>
                                    <span class="block text-xs text-gray-500 tabular-nums">{{ $company->org_number }}</span>
                                </td>
                                @foreach($months as $month)
                                    @php($cell = $row['cells'][$month])
                                    <td class="px-3 py-3 align-top text-center text-xs leading-5">
                                        @if($cell['posted'] !== null)
                                            <span class="block tabular-nums {{ $cell['posted'] === 0 ? 'text-gray-400' : 'text-gray-900' }}" title="{{ __('Bokförda verifikationer') }}">{{ __(':count ver.', ['count' => $cell['posted']]) }}</span>
                                        @endif
                                        @if($cell['locked'])
                                            <span class="block text-gray-600">{{ __('Låst') }}</span>
                                        @endif
                                        @if($cell['vat'])
                                            @php([$mark, $tone, $text] = $status[$cell['vat']])
                                            <span class="block {{ $tone }}" title="{{ $cell['vat_due'] ? __('Sista dag :date', ['date' => $cell['vat_due']]) : '' }}">{{ $mark }} {{ __('Moms') }} {{ $text }}</span>
                                        @endif
                                        @if($cell['agi'])
                                            @php([$mark, $tone, $text] = $status[$cell['agi']])
                                            <span class="block {{ $tone }}" title="{{ $cell['agi_due'] ? __('Sista dag :date', ['date' => $cell['agi_due']]) : '' }}">{{ $mark }} {{ __('AGI') }} {{ $text }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
