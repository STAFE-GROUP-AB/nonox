@php
    use App\Modules\Payroll\Models\CompTimeEntry;
    use App\Modules\Payroll\Support\CompTimeLedger;

    $input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm';
@endphp
<div class="space-y-6">
    <x-ui.card>
        <h2 class="text-base font-bold text-ink">{{ __('Saldo per anställd') }}</h2>
        <p class="mt-1 text-sm text-gray-600">{{ __('Komptid tjänas in när övertid eller mertid tas ut som ledighet i stället för pengar, under Planering → Tid att attestera. Saldot visas på lönespecen och i Min sida.') }}</p>
        @php($withBalance = $employees->filter(fn ($employee) => ($balances[$employee->id] ?? 0) !== 0))
        @if($withBalance->isEmpty())
            <x-ui.empty-state :title="__('Ingen har komptid')">{{ __('När en period med övertid sparas som komptid syns saldot här.') }}</x-ui.empty-state>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th scope="col" class="py-2 pr-4 font-medium">{{ __('Anställd') }}</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Saldo (timmar)') }}</th>
                            <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Visa') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums">
                        @foreach($withBalance as $employee)
                            <tr wire:key="balance-{{ $employee->id }}">
                                <td class="py-2 pr-4">{{ $employee->first_name }} {{ $employee->last_name }}</td>
                                <td @class(['py-2 pr-4 text-right', 'text-red-800' => $balances[$employee->id] < 0])>{{ CompTimeLedger::hours($balances[$employee->id]) }}</td>
                                <td class="py-2 text-right"><button type="button" wire:click="$set('person', {{ $employee->id }})" class="text-sm font-semibold text-accent-700 hover:underline">{{ __('Visa rader') }}</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if($canManage)
        <x-ui.card>
            <h2 class="text-base font-bold text-ink">{{ __('Registrera') }}</h2>
            <form wire:submit="record" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <x-ui.field for="ct-employee" :label="__('Anställd')" error="employee_id">
                    <select id="ct-employee" wire:model="form.employee_id" class="{{ $input }} mt-1">
                        <option value="">{{ __('Välj') }}</option>
                        @foreach($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="ct-kind" :label="__('Typ')" error="kind">
                    <select id="ct-kind" wire:model="form.kind" class="{{ $input }} mt-1">
                        <option value="{{ CompTimeEntry::TAKEN }}">{{ __('Uttag som ledighet') }}</option>
                        <option value="{{ CompTimeEntry::PAID_OUT }}">{{ __('Utbetalning') }}</option>
                        <option value="{{ CompTimeEntry::ADJUSTMENT }}">{{ __('Justering (till exempel ingående saldo)') }}</option>
                    </select>
                </x-ui.field>
                <x-ui.field for="ct-date" :label="__('Datum')" error="date">
                    <input id="ct-date" type="date" wire:model="form.date" class="{{ $input }} mt-1">
                </x-ui.field>
                <x-ui.field for="ct-hours" :label="__('Timmar')" :hint="__('En justering kan vara negativ.')" error="hours">
                    <input id="ct-hours" type="text" inputmode="decimal" wire:model="form.hours" class="{{ $input }} mt-1 tabular-nums" placeholder="7,5">
                </x-ui.field>
                <x-ui.field for="ct-note" :label="__('Anteckning')" error="note">
                    <input id="ct-note" wire:model="form.note" maxlength="500" class="{{ $input }} mt-1">
                </x-ui.field>
                <div class="sm:col-span-2 lg:col-span-5">
                    <p class="mb-3 text-sm text-gray-600">{{ __('En utbetalning betalas i lönekörningen för datumets månad med timvärdet av lönen. Uttagen ledighet betalas för timavlönade på samma sätt; för månadsavlönade betalar lönen redan dagen.') }}</p>
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($person !== null)
        @php($chosen = $employees->firstWhere('id', $person))
        <x-ui.card>
            <h2 class="text-base font-bold text-ink">{{ __('Rader för :name', ['name' => $chosen ? $chosen->first_name.' '.$chosen->last_name : '']) }}</h2>
            @if($entries->isEmpty())
                <p class="mt-2 text-sm text-gray-600">{{ __('Inga rader ännu.') }}</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-gray-500">
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">{{ __('Timmar') }}</th>
                                <th scope="col" class="py-2 pr-4 font-medium">{{ __('Underlag') }}</th>
                                <th scope="col" class="py-2 text-right font-medium"><span class="sr-only">{{ __('Ta bort') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 tabular-nums">
                            @foreach($entries as $entry)
                                <tr wire:key="entry-{{ $entry->id }}">
                                    <td class="py-2 pr-4">{{ $entry->date->toDateString() }}</td>
                                    <td class="py-2 pr-4">{{ CompTimeEntry::kindLabel($entry->kind) }}</td>
                                    <td @class(['py-2 pr-4 text-right', 'text-red-800' => $entry->minutes < 0])>{{ ($entry->minutes < 0 ? '−' : '+').CompTimeLedger::hours(abs($entry->minutes)) }}</td>
                                    <td class="py-2 pr-4 text-gray-600">
                                        @if($entry->kind === CompTimeEntry::EARNED)
                                            {{ __(':worked min övertid eller mertid × :factor', ['worked' => $entry->worked_minutes, 'factor' => str_replace('.', ',', (string) $entry->factor)]) }}@if($entry->pay_code) ({{ $entry->pay_code }})@endif
                                        @endif
                                        {{ $entry->note }}
                                    </td>
                                    <td class="py-2 text-right">
                                        @if($canManage && $entry->kind !== CompTimeEntry::EARNED)
                                            <button type="button" wire:click="delete({{ $entry->id }})" wire:confirm="{{ __('Ta bort raden ur komptidssaldot?') }}" class="text-sm font-semibold text-red-800 hover:underline">{{ __('Ta bort') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
