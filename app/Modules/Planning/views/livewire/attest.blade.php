@php
    use App\Modules\Planning\Domain\WorkingTimeChecks;
    use App\Modules\Planning\Models\Attestation;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
    $locale = app()->getLocale();
    $hours = fn (int $minutes): string => WorkingTimeChecks::hours($minutes);
    $span = fn ($interval): string => ($interval->start?->format('H:i') ?? '–').'–'.($interval->end?->format('H:i') ?? '?').($interval->breakMinutes > 0 ? ' ('.__(':min min rast', ['min' => $interval->breakMinutes]).')' : '');
    $signed = fn (int $minutes): string => $minutes === 0 ? '0' : ($minutes > 0 ? '+' : '−').$hours(abs($minutes));
    $statusLabel = [Attestation::OPEN => __('Att attestera'), Attestation::ATTESTED => __('Attesterad'), Attestation::SENT => __('Skickad till lönen')];
@endphp

<div class="space-y-6">
    {{-- Filters and bulk actions --}}
    <div class="flex flex-wrap items-end justify-between gap-4 rounded-3xl bg-white p-4 shadow-xs ring-1 ring-stone-200">
        <div class="flex flex-wrap items-end gap-3">
            <x-ui.field for="at-from" :label="__('Från')"><input id="at-from" type="date" wire:model.live="from" class="{{ $input }} mt-1"></x-ui.field>
            <x-ui.field for="at-to" :label="__('Till')"><input id="at-to" type="date" wire:model.live="to" class="{{ $input }} mt-1"></x-ui.field>
            <x-ui.field for="at-person" :label="__('Person')">
                <select id="at-person" wire:model.live="person" class="{{ $input }} mt-1">
                    <option value="">{{ __('Alla') }}</option>
                    @foreach($employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field for="at-filter" :label="__('Visa')">
                <select id="at-filter" wire:model.live="filter" class="{{ $input }} mt-1">
                    <option value="alla">{{ __('Alla dagar') }}</option>
                    <option value="avvikelser">{{ __('Med avvikelser') }}</option>
                    <option value="att-attestera">{{ __('Att attestera') }}</option>
                    <option value="attesterade">{{ __('Attesterade och skickade') }}</option>
                </select>
            </x-ui.field>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-secondary-button type="button" wire:click="attestClean" wire:loading.attr="disabled" :disabled="$clean === 0">
                {{ __('Attestera alla utan avvikelser (:count)', ['count' => $clean]) }}
            </x-secondary-button>
            <x-button type="button" wire:click="send" wire:loading.attr="disabled" :disabled="$sendable === 0"
                wire:confirm="{{ __('Skicka :count attesterade dagar till lönen? De blir godkända tidrapporter som nästa lönekörning betalar, och dagarna låses här.', ['count' => $sendable]) }}">
                {{ __('Skicka till lönen (:count)', ['count' => $sendable]) }}
            </x-button>
        </div>
    </div>

    <p class="text-sm text-gray-600">
        {{ trans_choice('{0} Inga dagar att attestera.|{1} 1 dag att attestera.|[2,*] :count dagar att attestera.', $open, ['count' => $open]) }}
        {{ trans_choice('{0}|{1} 1 dag är skickad till lönen.|[2,*] :count dagar är skickade till lönen.', $sent, ['count' => $sent]) }}
        @unless($ledger)
            {{ __('Personalliggaren är inte på, så den faktiska tiden är den planerade tills du ändrar den.') }}
        @endunless
    </p>

    @if($groups->isEmpty())
        <x-ui.empty-state :title="__('Ingen tid att visa för perioden')">{{ __('Här visas dagar med publicerade pass eller arbetad tid. Publicera schemat under Schema, eller välj en annan period.') }}</x-ui.empty-state>
    @endif

    @foreach($groups as $employeeId => $rows)
        @php($employee = $rows->first()->employee)
        <x-ui.card wire:key="person-{{ $employeeId }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-ink">{{ $employee->first_name }} {{ $employee->last_name }}</h2>
                    <p class="text-sm text-gray-600 tabular-nums">
                        {{ __('Planerat :planned, faktiskt :actual', ['planned' => $hours($rows->sum(fn ($row) => $row->comparison->plannedMinutes())), 'actual' => $hours($rows->sum(fn ($row) => $row->comparison->actualMinutes()))]) }}
                    </p>
                </div>
                @if($rows->contains(fn ($row) => $row->canAttest()))
                    <x-secondary-button type="button" wire:click="attestPerson({{ $employeeId }})" wire:loading.attr="disabled">{{ __('Attestera personens dagar') }}</x-secondary-button>
                @endif
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="py-2 pe-3">{{ __('Dag') }}</th>
                            <th scope="col" class="py-2 pe-3">{{ __('Plan') }}</th>
                            <th scope="col" class="py-2 pe-3">{{ __('Faktiskt') }}</th>
                            <th scope="col" class="py-2 pe-3 text-right">{{ __('Skillnad') }}</th>
                            <th scope="col" class="py-2 pe-3">{{ __('OB') }}</th>
                            <th scope="col" class="py-2 pe-3">{{ __('Till lönen') }}</th>
                            <th scope="col" class="py-2 pe-3">{{ __('Avvikelser') }}</th>
                            <th scope="col" class="py-2 text-right">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 align-top">
                        @foreach($rows as $row)
                            <tr wire:key="day-{{ $row->key() }}">
                                <td class="py-2 pe-3 whitespace-nowrap font-semibold text-ink">{{ ucfirst($row->date->locale($locale)->isoFormat('ddd D MMM')) }}</td>
                                <td class="py-2 pe-3 whitespace-nowrap tabular-nums">
                                    @forelse($row->comparison->planned as $interval)<div>{{ $span($interval) }}</div>@empty<span class="text-gray-400">{{ __('Inget pass') }}</span>@endforelse
                                </td>
                                <td class="py-2 pe-3 whitespace-nowrap tabular-nums">
                                    @forelse($row->comparison->actual as $interval)<div>{{ $span($interval) }}</div>@empty<span class="text-gray-400">{{ __('Ingen tid') }}</span>@endforelse
                                </td>
                                <td @class(['py-2 pe-3 text-right tabular-nums whitespace-nowrap', 'text-red-800' => $row->comparison->differenceMinutes() < 0])>{{ $signed($row->comparison->differenceMinutes()) }}</td>
                                <td class="py-2 pe-3 whitespace-nowrap tabular-nums">
                                    @forelse($row->basis->ob as $code => $minutes)<div>{{ $code }}: {{ $hours($minutes) }}</div>@empty<span class="text-gray-400">–</span>@endforelse
                                </td>
                                <td class="py-2 pe-3 whitespace-nowrap tabular-nums">
                                    @forelse($row->basis->minutes as $code => $minutes)<div>{{ $code }}: {{ $hours($minutes) }}</div>@empty<span class="text-gray-400">{{ __('Inget') }}</span>@endforelse
                                </td>
                                <td class="py-2 pe-3 min-w-64">
                                    @foreach($row->allDeviations() as $deviation)
                                        <p @class(['text-red-800 font-semibold' => $deviation->blocking, 'text-amber-900' => ! $deviation->blocking && ! $deviation->informational, 'text-gray-500' => $deviation->informational])>{{ $deviation->message }}</p>
                                    @endforeach
                                    @if($row->lockReason)
                                        <p class="text-amber-900">{{ $row->lockReason }}</p>
                                    @endif
                                    @if(! $row->allDeviations() && ! $row->lockReason)
                                        <span class="text-green-800">{{ __('Enligt schemat') }}</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    <x-ui.badge :tone="$row->isOpen() ? 'neutral' : ($row->status() === Attestation::SENT ? 'success' : 'accent')">{{ $statusLabel[$row->status()] }}</x-ui.badge>
                                    <div class="mt-2 flex justify-end gap-2">
                                        @if($row->isOpen())
                                            <button type="button" wire:click="edit({{ $employeeId }}, '{{ $row->date->toDateString() }}')" class="text-sm font-semibold text-accent-700 hover:underline">{{ __('Ändra') }}</button>
                                            @if($row->canAttest())
                                                <button type="button" wire:click="attestDay({{ $employeeId }}, '{{ $row->date->toDateString() }}')" class="text-sm font-semibold text-accent-700 hover:underline">{{ __('Attestera') }}</button>
                                            @endif
                                        @elseif($row->attestation)
                                            <button type="button" wire:click="reopen({{ $row->attestation->id }})"
                                                wire:confirm="{{ $row->status() === Attestation::SENT ? __('Öppna dagen igen? Tidrapporterna tas bort ur lönen, så länge lönekörningen inte är attesterad.') : __('Öppna dagen igen?') }}"
                                                class="text-sm font-semibold text-gray-700 hover:underline">{{ __('Öppna igen') }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endforeach

    <x-dialog-modal wire:model="showEditor" maxWidth="lg">
        <x-slot name="title">{{ __('Ändra faktisk tid') }}</x-slot>
        <x-slot name="content">
            <form id="actual-form" wire:submit="saveActual" class="space-y-4">
                <p class="text-sm text-gray-600">{{ __('Tiden gäller bara attesten och lönen. Personalliggaren ändras inte; en fel in- eller utcheckning rättas där.') }}</p>
                <label class="flex items-center gap-2 text-sm">
                    <x-checkbox wire:model.live="form.none" />
                    {{ __('Arbetade inte den här dagen') }}
                </label>
                @unless($form['none'])
                    @foreach($form['intervals'] as $index => $interval)
                        <div class="grid gap-3 sm:grid-cols-3" wire:key="interval-{{ $index }}">
                            <x-ui.field for="actual-start-{{ $index }}" :label="__('Från')" error="intervals.{{ $index }}.start">
                                <input id="actual-start-{{ $index }}" type="time" wire:model="form.intervals.{{ $index }}.start" class="{{ $input }} mt-1 tabular-nums">
                            </x-ui.field>
                            <x-ui.field for="actual-end-{{ $index }}" :label="__('Till')" :hint="__('Före starttiden betyder nästa dag.')" error="intervals.{{ $index }}.end">
                                <input id="actual-end-{{ $index }}" type="time" wire:model="form.intervals.{{ $index }}.end" class="{{ $input }} mt-1 tabular-nums">
                            </x-ui.field>
                            <x-ui.field for="actual-break-{{ $index }}" :label="__('Rast (minuter)')" error="intervals.{{ $index }}.break_minutes">
                                <input id="actual-break-{{ $index }}" type="number" min="0" max="600" step="5" wire:model="form.intervals.{{ $index }}.break_minutes" class="{{ $input }} mt-1 tabular-nums">
                            </x-ui.field>
                        </div>
                    @endforeach
                    @if(count($form['intervals']) < App\Modules\Planning\Actions\SaveActualTime::MAX_INTERVALS)
                        <button type="button" wire:click="addInterval" class="text-sm font-semibold text-accent-700 hover:underline">{{ __('Lägg till ett pass till (till exempel efter en rast på OB-tid)') }}</button>
                    @endif
                @endunless
                <x-ui.field for="actual-note" :label="__('Anteckning')" error="note">
                    <input id="actual-note" wire:model="form.note" maxlength="500" class="{{ $input }} mt-1">
                </x-ui.field>
            </form>
        </x-slot>
        <x-slot name="footer">
            <div class="flex w-full flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="clearActual" class="text-sm font-semibold text-gray-700 underline">{{ $ledger ? __('Använd personalliggaren igen') : __('Använd schemat igen') }}</button>
                <div class="flex gap-3">
                    <x-secondary-button type="button" wire:click="$set('showEditor', false)">{{ __('Avbryt') }}</x-secondary-button>
                    <x-button type="submit" form="actual-form">{{ __('Spara') }}</x-button>
                </div>
            </div>
        </x-slot>
    </x-dialog-modal>
</div>
