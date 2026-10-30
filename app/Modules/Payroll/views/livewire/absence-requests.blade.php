@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $labels = ['SICK' => __('Sjuk'), 'VAB' => __('Vård av barn'), 'VACATION' => __('Semester')];
@endphp
<div class="space-y-6">
    <x-ui.card :title="__('Väntar på svar')">
        @if($pending->isEmpty())
            <x-ui.empty-state :title="__('Inga obesvarade ansökningar')">{{ __('Sjukanmälningar och semesteransökningar från Min sida hamnar här.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($pending as $absence)
                    <li class="space-y-3 py-4" wire:key="pending-{{ $absence->id }}">
                        <div class="flex flex-wrap items-center gap-4">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-gray-900">{{ $absence->employee?->first_name }} {{ $absence->employee?->last_name }} · {{ $labels[$absence->type] ?? $absence->type }}</span>
                                <span class="block text-sm text-gray-600 tabular-nums">{{ $absence->date_from->toDateString() }} – {{ $absence->date_to->toDateString() }}</span>
                                @if($absence->note)<span class="block text-sm text-gray-700">”{{ $absence->note }}”</span>@endif
                            </span>
                            <label class="text-sm text-gray-600">{{ __('Timmar per dag') }}
                                <input type="text" inputmode="decimal" class="{{ $input }} mt-1 w-24" wire:model="hours.{{ $absence->id }}">
                            </label>
                            <x-button type="button" wire:click="approve({{ $absence->id }})" wire:loading.attr="disabled">{{ __('Godkänn') }}</x-button>
                            <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('rejecting', {{ $absence->id }})">{{ __('Avslå') }}</button>
                        </div>
                        @if($rejecting === $absence->id)
                            <form wire:submit="reject" class="flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3">
                                <x-ui.field for="reject_reason" :label="__('Skäl, som den anställde ser')" error="reason" class="min-w-64 flex-1">
                                    <input id="reject_reason" type="text" class="{{ $input }} mt-1" wire:model="reason">
                                </x-ui.field>
                                <x-button>{{ __('Avslå ansökan') }}</x-button>
                                <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('rejecting', null)">{{ __('Avbryt') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Utlägg att godkänna')" :description="$aiReads ? __('NONOX AI har läst kvittona och föreslår konto. Kontrollera mot kvittot, rätta det som behövs och godkänn. Utlägget bokförs med kvittot som underlag och betalas tillbaka med nästa lön.') : __('Välj kostnadskonto. Utlägget bokförs med kvittot som underlag och betalas tillbaka med nästa lön.')">
        @if($expenses->isEmpty())
            <p class="text-sm text-gray-600">{{ __('Inga utlägg väntar.') }}</p>
        @else
            <ul class="divide-y divide-gray-100" @if($expenses->contains('ai_status', 'PENDING')) wire:poll.5s @endif>
                @foreach($expenses as $expense)
                    <li class="space-y-3 py-4" wire:key="expense-{{ $expense->id }}">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-gray-900">{{ $expense->employee?->first_name }} {{ $expense->employee?->last_name }} · {{ $expense->description ?? $expense->receipt_name }}</span>
                                <a href="{{ route('payroll.expenses.receipt', $expense) }}" target="_blank" class="text-sm font-medium text-accent-600 hover:text-accent-700">{{ __('Visa kvittot') }}</a>
                            </span>
                            @if($expense->ai_status === 'PENDING')
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">{{ __('NONOX AI läser kvittot…') }}</span>
                            @elseif($expense->ai_status === 'DONE')
                                <span class="rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-800">{{ __('Läst av NONOX AI') }}</span>
                            @elseif($expense->ai_status === 'FAILED')
                                <span class="rounded-full bg-accent-50 px-3 py-1 text-xs font-medium text-accent-800">{{ __('NONOX AI kunde inte läsa allt') }}</span>
                            @endif
                            @if($aiReads && $expense->ai_status !== null && $expense->ai_status !== 'PENDING')
                                <button type="button" class="text-sm text-gray-600 underline" wire:click="readExpenseAgain({{ $expense->id }})" wire:loading.attr="disabled">{{ __('Läs kvittot igen') }}</button>
                            @endif
                        </div>
                        @if($expense->ai_notes)
                            <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ $expense->ai_notes }}</p>
                        @endif
                        @if($expense->ai_status !== 'PENDING')
                            <div class="flex flex-wrap items-end gap-4">
                                <label class="text-sm text-gray-600">{{ __('Datum') }}
                                    <input type="date" class="{{ $input }} mt-1" wire:model="expenseDate.{{ $expense->id }}">
                                </label>
                                <label class="min-w-48 flex-1 text-sm text-gray-600">{{ __('Vad utlägget gäller') }}
                                    <input type="text" class="{{ $input }} mt-1" wire:model="expenseDescription.{{ $expense->id }}">
                                </label>
                                <label class="text-sm text-gray-600">{{ __('Belopp') }}
                                    <input type="text" inputmode="decimal" class="{{ $input }} mt-1 w-32" wire:model="expenseAmount.{{ $expense->id }}">
                                </label>
                                <label class="text-sm text-gray-600">{{ __('Moms') }}
                                    <input type="text" inputmode="decimal" class="{{ $input }} mt-1 w-28" wire:model="expenseVat.{{ $expense->id }}">
                                </label>
                                <label class="text-sm text-gray-600">{{ __('Konto') }}
                                    <select class="{{ $input }} mt-1" wire:model="expenseAccount.{{ $expense->id }}">
                                        <option value="">{{ __('Välj…') }}</option>
                                        @foreach($accounts as $account)<option value="{{ $account->number }}">{{ $account->number }} {{ $account->name }}</option>@endforeach
                                    </select>
                                </label>
                                <x-button type="button" wire:click="approveExpense({{ $expense->id }})" wire:loading.attr="disabled">{{ __('Godkänn') }}</x-button>
                                <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('rejectingExpense', {{ $expense->id }})">{{ __('Avslå') }}</button>
                            </div>
                        @endif
                        @if($rejectingExpense === $expense->id)
                            <form wire:submit="rejectExpense" class="flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3">
                                <x-ui.field for="reject_expense_reason" :label="__('Skäl, som den anställde ser')" error="reason" class="min-w-64 flex-1">
                                    <input id="reject_expense_reason" type="text" class="{{ $input }} mt-1" wire:model="reason">
                                </x-ui.field>
                                <x-button>{{ __('Avslå utlägget') }}</x-button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if($decided->isNotEmpty())
        <x-ui.card :title="__('Senast besvarade')">
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($decided as $absence)
                    <li class="flex flex-wrap items-center gap-4 py-2">
                        <span class="flex-1">{{ $absence->employee?->first_name }} {{ $absence->employee?->last_name }} · {{ $labels[$absence->type] ?? $absence->type }} · <span class="tabular-nums">{{ $absence->date_from->toDateString() }} – {{ $absence->date_to->toDateString() }}</span></span>
                        <span class="text-gray-600">{{ $absence->status === 'APPROVED' ? __('Godkänd') : __('Avslagen') }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
