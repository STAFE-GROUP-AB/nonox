@php
    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm';
    $labels = ['SICK' => __('Sjuk'), 'VAB' => __('Vård av barn'), 'VACATION' => __('Semester')];
    $statuses = ['REQUESTED' => [__('Väntar på svar'), 'bg-gray-100 text-gray-700'], 'APPROVED' => [__('Godkänd'), 'bg-green-50 text-green-800'], 'REJECTED' => [__('Inte godkänd'), 'bg-accent-50 text-accent-800']];
@endphp
<div>
    @if($employee)
        <x-ui.card>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Frånvaro') }}</p>
            @if($form === null)
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-left hover:border-gray-300" wire:click="open('SICK')">
                        <span class="block font-semibold text-gray-900">{{ __('Sjukanmälan') }}</span>
                        <span class="block text-xs text-gray-500">{{ __('Jag är sjuk') }}</span>
                    </button>
                    <button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-left hover:border-gray-300" wire:click="open('VAB')">
                        <span class="block font-semibold text-gray-900">{{ __('Vård av barn') }}</span>
                        <span class="block text-xs text-gray-500">{{ __('Mitt barn är sjukt') }}</span>
                    </button>
                    <button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-left hover:border-gray-300" wire:click="open('VACATION')">
                        <span class="block font-semibold text-gray-900">{{ __('Ansök om semester') }}</span>
                        <span class="block text-xs text-gray-500">{{ __('Ledigt med semester') }}</span>
                    </button>
                </div>
            @else
                <form wire:submit="send" class="mt-3 space-y-4">
                    <p class="text-lg font-semibold text-gray-900">{{ ['SICK' => __('Sjukanmälan'), 'VAB' => __('Vård av barn'), 'VACATION' => __('Ansök om semester')][$form] }}</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field for="absence_from" :label="$form === 'VACATION' ? __('Första semesterdagen') : __('Första dagen')" error="date_from">
                            <input id="absence_from" type="date" class="{{ $input }} mt-1" wire:model.live="absence.date_from">
                        </x-ui.field>
                        <x-ui.field for="absence_to" :label="$form === 'VACATION' ? __('Sista semesterdagen') : __('Sista dagen (ungefär)')" error="date_to">
                            <input id="absence_to" type="date" class="{{ $input }} mt-1" wire:model.live="absence.date_to">
                        </x-ui.field>
                    </div>
                    @if($form === 'VACATION' && $vacationDays !== null)
                        <p class="text-sm text-gray-700">{{ __(':count semesterdagar. Lördagar, söndagar och helgdagar räknas inte.', ['count' => $vacationDays]) }}</p>
                    @endif
                    @if($form !== 'VACATION')
                        <p class="text-sm text-gray-600">{{ __('Vet du inte hur länge? Ange en ungefärlig dag, din arbetsgivare kan ändra den. Från den åttonde dagen behövs läkarintyg.') }}</p>
                    @endif
                    <x-ui.field for="absence_note" :label="__('Meddelande (frivilligt)')" error="note">
                        <textarea id="absence_note" rows="2" class="{{ $input }} mt-1" wire:model="absence.note"></textarea>
                    </x-ui.field>
                    <div class="flex items-center gap-4">
                        <x-button wire:loading.attr="disabled">{{ $form === 'VACATION' ? __('Skicka ansökan') : __('Skicka anmälan') }}</x-button>
                        <button type="button" class="text-sm text-gray-600 underline" wire:click="$set('form', null)">{{ __('Avbryt') }}</button>
                    </div>
                </form>
            @endif

            @if($requests->isNotEmpty())
                <ul class="mt-6 divide-y divide-gray-100 border-t border-gray-100">
                    @foreach($requests as $request)
                        <li class="flex flex-wrap items-center gap-3 py-3 text-sm" wire:key="request-{{ $request->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-gray-900">{{ $labels[$request->type] ?? $request->type }}</span>
                                <span class="block text-gray-600 tabular-nums">{{ $request->date_from->toDateString() }} – {{ $request->date_to->toDateString() }}</span>
                                @if($request->status === 'REJECTED' && $request->rejection_reason)
                                    <span class="block text-accent-800">{{ $request->rejection_reason }}</span>
                                @endif
                            </span>
                            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $statuses[$request->status][1] }}">{{ $statuses[$request->status][0] }}</span>
                            @if($request->status === 'REQUESTED')
                                <button type="button" class="text-xs text-gray-600 underline" wire:click="cancel({{ $request->id }})">{{ __('Återkalla') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    @endif
</div>
