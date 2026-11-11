@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm')
@php($name = fn ($employee) => $employee ? trim($employee->first_name.' '.$employee->last_name) : __('borttagen person'))
@php($day = fn ($date) => $date?->locale('sv')->isoFormat('D MMM'))
<div class="space-y-4">
    @include('manufacturing::livewire.planning.run-bar')

    <label class="flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" wire:model.live="showClosed" class="rounded-sm border-stone-300 text-ink focus:ring-accent-500">
        {{ __('Visa stängda avvikelser') }}
    </label>

    @if($messageChangeId !== null && $preview)
        <x-ui.card :title="__('Meddelande till kunden')" :description="__('Så här ser mejlet ut. Det skickas först när du trycker Skicka, från företagets namn med företagets e-post som svarsadress.')">
            <iframe srcdoc="{{ $preview }}" title="{{ __('Förhandsvisning av mejlet') }}" class="h-72 w-full rounded-lg border border-stone-200 bg-white" sandbox></iframe>
            <form wire:submit="send" class="mt-4 flex flex-wrap items-end gap-3">
                <x-ui.field for="recipient" :label="__('Mottagare')" error="recipient">
                    <input id="recipient" type="email" wire:model="recipient" class="{{ $input }} w-72" required>
                </x-ui.field>
                <x-button type="submit" wire:loading.attr="disabled">{{ __('Skicka till kunden') }}</x-button>
                <x-secondary-button type="button" wire:click="cancelConfirm">{{ __('Skicka inte nu') }}</x-secondary-button>
            </form>
        </x-ui.card>
    @elseif($confirming['document'] !== null)
        <x-ui.card :title="__('Bekräfta nytt leveransdatum')" :description="__('Datumet sparas på kundordern som bekräftat. Kunden får inget förrän du har sett meddelandet och tryckt Skicka.')">
            <form wire:submit="confirm" class="flex flex-wrap items-end gap-3">
                <x-ui.field for="confirm-date" :label="__('Leveransdatum')" error="confirming.date">
                    <input id="confirm-date" type="date" wire:model="confirming.date" class="{{ $input }}" required>
                </x-ui.field>
                <x-button type="submit" wire:loading.attr="disabled" wire:confirm="{{ __('Bekräfta datumet på ordern? Det blir det datum kunden kan få veta.') }}">{{ __('Bekräfta datum') }}</x-button>
                <x-secondary-button type="button" wire:click="cancelConfirm">{{ __('Avbryt') }}</x-secondary-button>
            </form>
        </x-ui.card>
    @endif

    @forelse($deviations as $deviation)
        <x-ui.card wire:key="deviation-{{ $deviation->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-semibold text-ink">
                        {{ $deviation->type->label() }}
                        <x-ui.badge :tone="$deviation->status === App\Modules\Manufacturing\Domain\DeviationStatus::Open ? 'warning' : 'neutral'">{{ $deviation->status->label() }}</x-ui.badge>
                    </p>
                    <p class="text-sm text-gray-600">
                        @if($deviation->type === App\Modules\Manufacturing\Domain\DeviationType::Absence)
                            {{ __('Frånvarande: :name, :from – :to', ['name' => $name($deviation->employee), 'from' => $day($deviation->starts_on), 'to' => $day($deviation->ends_on)]) }}
                        @elseif(isset($deviation->details['workstation_id']))
                            {{ __(':station ur drift från :from', ['station' => $workstations->get($deviation->details['workstation_id']), 'from' => $day($deviation->starts_on)]) }}@if($deviation->ends_on) {{ __('till :to', ['to' => $day($deviation->ends_on)]) }}@endif
                        @elseif(isset($deviation->details['after']))
                            {{ __('Väntat leveransdatum flyttat från :before till :after', ['before' => $deviation->details['before'], 'after' => $deviation->details['after']]) }}
                        @elseif(array_key_exists('remaining_minutes', $deviation->details))
                            {{ __('Ny uppskattning: :minutes min kvar', ['minutes' => $deviation->details['remaining_minutes'] ?? '–']) }}
                        @endif
                        · {{ __('registrerad :at', ['at' => $deviation->created_at?->setTimezone('Europe/Stockholm')->format('Y-m-d H:i')]) }}
                    </p>
                </div>
                @if($canPlan && $deviation->status === App\Modules\Manufacturing\Domain\DeviationStatus::Open)
                    <div class="flex flex-wrap gap-2">
                        @if($deviation->impacts->isNotEmpty())
                            <x-secondary-button type="button" wire:click="suggest({{ $deviation->id }})">{{ $suggestingId === $deviation->id ? __('Dölj förslag') : __('Visa förslag') }}</x-secondary-button>
                        @endif
                        <x-secondary-button type="button" wire:click="decide({{ $deviation->id }}, 'HANDLED')">{{ __('Markera hanterad') }}</x-secondary-button>
                        <x-secondary-button type="button" wire:click="decide({{ $deviation->id }}, 'DISMISSED')">{{ __('Avfärda') }}</x-secondary-button>
                    </div>
                @endif
            </div>

            @if($deviation->resolution === 'NO_IMPACT')
                <p class="mt-3 text-sm text-gray-600">{{ __('Ingen leverans flyttades.') }}</p>
            @elseif($deviation->run_after_id === null)
                <p class="mt-3 text-sm text-gray-600">{{ __('Planen räknas om. Påverkan visas strax.') }}</p>
            @elseif($deviation->impacts->isNotEmpty())
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">{{ __('Order') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Utlovat') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Prognos före') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Prognos efter') }}</th>
                                <th class="py-2 pr-4 text-right font-medium">{{ __('Ändring') }}</th>
                                <th class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($deviation->impacts as $impact)
                                <tr wire:key="impact-{{ $impact->id }}">
                                    <td class="py-2 pr-4">{{ $impact->order->reference() }} {{ $impact->order->name }}</td>
                                    <td class="py-2 pr-4">{{ $impact->customer?->name ?? __('Lagertillverkning') }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $impact->promised_on?->toDateString() ?? '–' }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $impact->forecast_before_on?->toDateString() ?? __('Efter horisonten') }}</td>
                                    <td class="py-2 pr-4 tabular-nums">{{ $impact->forecast_after_on?->toDateString() ?? __('Efter horisonten') }}</td>
                                    <td @class(['py-2 pr-4 text-right tabular-nums', 'text-red-700' => ($impact->change_working_days ?? 0) > 0, 'text-green-800' => ($impact->change_working_days ?? 0) < 0])>{{ $impact->change_working_days === null ? '–' : (($impact->change_working_days > 0 ? '+' : '').$impact->change_working_days) }}</td>
                                    <td class="py-2 text-right">
                                        @if($canConfirm && $impact->sales_document_id && $impact->forecast_after_on)
                                            <button type="button" wire:click="startConfirm({{ $deviation->id }}, '{{ $impact->sales_document_id }}', '{{ $impact->forecast_after_on->toDateString() }}')" class="text-sm font-semibold text-accent-700 underline">{{ __('Nytt datum till kunden') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($suggestingId === $deviation->id)
                <div class="mt-4 space-y-2">
                    <p class="text-sm font-semibold text-ink">{{ __('Förslag, beräknade innan de visas') }}</p>
                    @forelse($suggestions as $index => $suggestion)
                        <div wire:key="suggestion-{{ $index }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-stone-200 p-3 text-sm">
                            <div>
                                <p class="font-medium text-ink">
                                    @switch($suggestion->kind)
                                        @case('MOVE') {{ __('Flytta :operation från :from till :to', $suggestion->facts) }} @break
                                        @case('SPLIT') {{ __('Dela :operation: :minutes min till :to', $suggestion->facts) }} @break
                                        @case('OVERTIME') {{ __('Övertid för :person :date kl. :from–:to', $suggestion->facts) }} @break
                                        @case('PRIORITY') {{ __('Lägg :order först', $suggestion->facts) }} @break
                                        @case('NEW_DATE') {{ __('Nytt datum till kunden: :date', $suggestion->facts) }} @break
                                    @endswitch
                                </p>
                                <p class="text-gray-600">
                                    @if($suggestion->kind !== 'NEW_DATE')
                                        {{ $suggestion->helps ? __('Prognos :before → :after', ['before' => $suggestion->before ?? __('efter horisonten'), 'after' => $suggestion->after ?? __('efter horisonten')]) : __('Hjälper inte: prognosen blir :after', ['after' => $suggestion->after ?? __('efter horisonten')]) }}
                                        @if($suggestion->delayed !== []) · {{ trans_choice(':count annan order blir senare|:count andra ordrar blir senare', count($suggestion->delayed)) }}@endif
                                        @if($suggestion->costOre !== null) · {{ __('uppskattad arbetskostnad :amount', ['amount' => App\Support\Money\Money::ofOre($suggestion->costOre)->format()]) }}@endif
                                    @endif
                                </p>
                                @if($suggestion->kind === 'OVERTIME')
                                    <p class="text-xs text-gray-500">{{ __('Kontrollera arbetstidsreglerna innan övertiden bestäms. Kostnaden räknas på arbetsstegets timkostnad, inte på övertidsersättningen.') }}</p>
                                @endif
                            </div>
                            @if($suggestion->changes !== [] && $suggestion->helps)
                                <x-secondary-button type="button" wire:click="apply({{ $index }})" wire:confirm="{{ __('Tillämpa förslaget? Planen ändras och beräknas om.') }}">{{ __('Tillämpa') }}</x-secondary-button>
                            @elseif($suggestion->kind === 'NEW_DATE' && $canConfirm)
                                @php($impact = $deviation->impacts->firstWhere('production_order_id', $suggestion->orderId))
                                @if($impact?->sales_document_id)
                                    <x-secondary-button type="button" wire:click="startConfirm({{ $deviation->id }}, '{{ $impact->sales_document_id }}', '{{ $suggestion->facts['date'] }}')">{{ __('Bekräfta till kunden') }}</x-secondary-button>
                                @endif
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-600">{{ __('Inga förslag: ingen order har arbete kvar att flytta.') }}</p>
                    @endforelse
                </div>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card>
            <x-ui.empty-state :title="__('Inga öppna avvikelser')">
                {{ __('När någon blir frånvarande, en leverans försenas, en arbetsstation stannar eller ett steg tar längre tid visas det här, med vad det gör med leveranserna.') }}
            </x-ui.empty-state>
        </x-ui.card>
    @endforelse
</div>
