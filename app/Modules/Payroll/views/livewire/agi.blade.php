@php
    $statuses = [
        'GENERATED' => [__('Skapad, inte inlämnad'), 'accent'],
        'SUBMITTED' => [__('Inlämnad'), 'neutral'],
        'ACCEPTED' => [__('Godkänd'), 'success'],
        'REJECTED' => [__('Avvisad'), 'accent'],
        'CORRECTED' => [__('Ersatt av rättelse'), 'neutral'],
    ];
@endphp
<div class="space-y-6">
    <x-ui.card>
        @if($next)
            <p class="text-sm text-gray-600">{{ __('Nästa arbetsgivardeklaration') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900">
                {{ $next['period'] }}
                @if($next['deadline'])
                    <span @class(['ml-2 text-base font-medium', 'text-accent-700' => $next['overdue'], 'text-gray-600' => ! $next['overdue']])>
                        {{ $next['overdue'] ? __('skulle ha lämnats senast :date', ['date' => $next['deadline']->toDateString()]) : __('lämnas senast :date', ['date' => $next['deadline']->toDateString()]) }}
                    </span>
                @endif
            </p>
        @else
            <p class="text-sm text-gray-900">{{ __('Alla månader med utbetald lön är deklarerade.') }}</p>
        @endif
        <p class="mt-3 text-sm text-gray-600">{{ __('Arbetsgivardeklarationen lämnas för den månad lönen betalas ut. Skapa filen här, logga in hos Skatteverket och lämna in den under Arbetsgivardeklaration → Lämna via fil. Markera den sedan som inlämnad med kvittensnumret.') }}</p>
        <x-input-error for="period" class="mt-3" />
        <x-input-error for="submission" class="mt-3" />
    </x-ui.card>

    @if($periods === [])
        <x-ui.empty-state :title="__('Ingen lön är utbetald ännu')">{{ __('När en lönekörning är attesterad kan arbetsgivardeklarationen för utbetalningsmånaden skapas här.') }}</x-ui.empty-state>
    @else
        <x-ui.card>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Utbetalningsmånad') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Senast') }}</th>
                            <th class="py-2 pr-4 font-medium text-right">{{ __('Skatt') }}</th>
                            <th class="py-2 pr-4 font-medium text-right">{{ __('Arbetsgivaravgifter') }}</th>
                            <th class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($periods as $row)
                            <tr wire:key="agi-{{ $row['period'] }}">
                                <td class="py-3 pr-4 align-top font-medium text-gray-900">{{ $row['period'] }}</td>
                                <td @class(['py-3 pr-4 align-top tabular-nums whitespace-nowrap', 'text-accent-700 font-medium' => $row['overdue']])>{{ $row['deadline']?->toDateString() ?? '–' }}</td>
                                <td class="py-3 pr-4 align-top text-right">@if($row['latest'])<x-ui.amount :money="App\Support\Money\Money::ofOre($row['latest']->tax_ore)" />@endif</td>
                                <td class="py-3 pr-4 align-top text-right">@if($row['latest'])<x-ui.amount :money="App\Support\Money\Money::ofOre($row['latest']->contributions_ore)" />@endif</td>
                                <td class="py-3 align-top">
                                    @if($row['open_runs'] > 0)
                                        <p class="text-sm text-gray-600">{{ __('En lönekörning i månaden är inte attesterad.') }}</p>
                                    @endif
                                    <ul class="space-y-3">
                                        @foreach($row['submissions'] as $submission)
                                            <li wire:key="agi-submission-{{ $submission->id }}">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <x-ui.badge :tone="$statuses[$submission->status][1]">{{ $statuses[$submission->status][0] }}</x-ui.badge>
                                                    <span class="text-xs text-gray-500">
                                                        {{ $submission->sequence > 1 ? __('Rättelse :number', ['number' => $submission->sequence - 1]) : __('Första filen') }}
                                                        · {{ trans_choice(':count individuppgift|:count individuppgifter', count($submission->individuals)) }}
                                                        @if($submission->removals > 0) · {{ trans_choice(':count borttag|:count borttag', $submission->removals) }} @endif
                                                        @if($submission->receipt_reference) · {{ __('Kvittens :receipt', ['receipt' => $submission->receipt_reference]) }} @endif
                                                    </span>
                                                    <x-ui.link :href="route('payroll.agi.file', $submission)">{{ __('Hämta fil') }}</x-ui.link>
                                                </div>
                                                @if($canSubmit && $submission->status === 'GENERATED')
                                                    <div class="mt-2 flex flex-wrap items-end gap-2">
                                                        <label class="text-xs text-gray-600" for="receipt-{{ $submission->id }}">{{ __('Kvittensnummer (frivilligt)') }}
                                                            <input id="receipt-{{ $submission->id }}" type="text" maxlength="100" class="mt-1 block w-48 border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm text-sm" wire:model="receipts.{{ $submission->id }}">
                                                        </label>
                                                        <x-button type="button" wire:click="submitted({{ $submission->id }})" wire:loading.attr="disabled">{{ __('Markera som inlämnad') }}</x-button>
                                                    </div>
                                                @elseif($canSubmit && $submission->status === 'SUBMITTED')
                                                    <div class="mt-2 flex flex-wrap gap-3 text-sm">
                                                        <button type="button" class="font-medium text-accent-600 hover:text-accent-700" wire:click="decided({{ $submission->id }}, true)">{{ __('Godkänd av Skatteverket') }}</button>
                                                        <button type="button" class="font-medium text-gray-600 hover:text-gray-800" wire:click="decided({{ $submission->id }}, false)">{{ __('Avvisad') }}</button>
                                                    </div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if($canSubmit && $row['runs'] > 0 && $row['latest']?->status !== 'GENERATED')
                                        <x-button type="button" class="mt-2" wire:click="generate('{{ $row['period'] }}')" wire:loading.attr="disabled">
                                            {{ $row['reported'] ? __('Skapa rättelse') : __('Skapa arbetsgivardeklaration') }}
                                        </x-button>
                                    @elseif($canSubmit && $row['latest']?->status === 'GENERATED')
                                        <button type="button" class="mt-2 text-sm text-gray-600 underline" wire:click="generate('{{ $row['period'] }}')">{{ __('Skapa filen på nytt') }}</button>
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
