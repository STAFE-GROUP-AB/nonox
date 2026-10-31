@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $statuses = ['SUBMITTED' => [__('Väntar på svar'), 'bg-gray-100 text-gray-700'], 'APPROVED' => [__('Godkänt, betalas med lönen'), 'bg-green-50 text-green-800'], 'PAID' => [__('Utbetalt'), 'bg-green-50 text-green-800'], 'REJECTED' => [__('Inte godkänt'), 'bg-accent-50 text-accent-800']];
@endphp
<div @if($reading) wire:poll.5s @endif>
    @if($employee)
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Utlägg') }}</p>
                @unless($open)
                    <button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-900 hover:border-gray-300" wire:click="start">{{ __('Lägg in ett utlägg') }}</button>
                @endunless
            </div>
            @if($open)
                <form wire:submit="send" class="mt-3 space-y-4">
                    @if($aiReads)
                        <p class="text-sm text-gray-600">{{ __('Ta en bild av kvittot och skicka den. Företaget har valt att låta AI läsa datum, vad du köpte, belopp och moms; kvittot skickas då till företagets AI-leverantör, och den som godkänner kontrollerar uppgifterna.') }} <a href="{{ route('legal.privacy') }}" class="underline">{{ __('Om personuppgifter') }}</a></p>
                    @else
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.field for="expense_date" :label="__('Datum på kvittot')" error="date"><input id="expense_date" type="date" class="{{ $input }} mt-1" wire:model="expense.date"></x-ui.field>
                            <x-ui.field for="expense_description" :label="__('Vad köpte du?')" error="description"><input id="expense_description" type="text" class="{{ $input }} mt-1" wire:model="expense.description" placeholder="{{ __('Till exempel citroner till baren') }}"></x-ui.field>
                            <x-ui.field for="expense_amount" :label="__('Belopp, kronor')" error="amount"><input id="expense_amount" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="expense.amount"></x-ui.field>
                            <x-ui.field for="expense_vat" :label="__('Varav moms (om det står på kvittot)')" error="vat"><input id="expense_vat" type="text" inputmode="decimal" class="{{ $input }} mt-1" wire:model="expense.vat"></x-ui.field>
                        </div>
                    @endif
                    <x-ui.field for="expense_receipt" :label="__('Kvitto')" error="receipt" :hint="__('Ta en bild med mobilen eller välj en PDF.')">
                        <input id="expense_receipt" type="file" accept="image/*,application/pdf" capture="environment" class="mt-1 block w-full text-sm" wire:model="receipt">
                    </x-ui.field>
                    <div class="flex items-center gap-4">
                        <x-button wire:loading.attr="disabled" wire:target="send,receipt">{{ $aiReads ? __('Skicka kvittot') : __('Skicka utlägget') }}</x-button>
                        <span wire:loading wire:target="send" class="text-sm text-gray-600">{{ $aiReads ? __('NONOX AI kontrollerar kvittot…') : __('Skickar…') }}</span>
                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('open', false)">{{ __('Avbryt') }}</button>
                    </div>
                </form>
            @endif
            @if($expenses->isNotEmpty())
                <ul class="mt-4 divide-y divide-gray-100 border-t border-gray-100">
                    @foreach($expenses as $item)
                        <li class="flex flex-wrap items-center gap-3 py-3 text-sm">
                            <span class="min-w-0 flex-1">
                                @if($item->ai_status === 'PENDING')
                                    <span class="block font-medium text-gray-900">{{ __('NONOX AI läser kvittot…') }}</span>
                                    <span class="block text-gray-600">{{ $item->receipt_name }}</span>
                                @elseif($item->amount_ore->ore === 0)
                                    <span class="block font-medium text-gray-900">{{ $item->description ?? $item->receipt_name }}</span>
                                    <span class="block text-gray-600">{{ __('Den som godkänner fyller i beloppet.') }}</span>
                                @else
                                    <span class="block font-medium text-gray-900">{{ $item->description }}</span>
                                    <span class="block text-gray-600 tabular-nums">{{ $item->date->toDateString() }} · {{ $item->amount_ore->format() }}</span>
                                @endif
                                @if($item->status === 'REJECTED' && $item->rejection_reason)<span class="block text-accent-800">{{ $item->rejection_reason }}</span>@endif
                            </span>
                            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $statuses[$item->status][1] ?? '' }}">{{ $statuses[$item->status][0] ?? $item->status }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif
</div>
