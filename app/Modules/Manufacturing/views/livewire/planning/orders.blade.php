@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm')
@php($name = fn ($employee) => $employee ? trim($employee->first_name.' '.$employee->last_name) : '–')
<div class="space-y-4">
    @include('manufacturing::livewire.planning.run-bar')

    <x-ui.card>
        @if($forecasts->isEmpty())
            <x-ui.empty-state :title="__('Inga ordrar i planen')">
                {{ __('Frisläpp en tillverkningsorder, eller koppla ett utkast till en öppen kundorder, så kommer den med i planen.') }}
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Order') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Kund') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Utlovat') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Prognos') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Skillnad') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Väntar på') }}</th>
                            <th class="py-2"><span class="sr-only">{{ __('Åtgärder') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($forecasts as $forecast)
                            @php($difference = $forecast->promised_on && $forecast->forecast_delivery_on ? $calendar->workingDaysBetween($forecast->promised_on->toDateString(), $forecast->forecast_delivery_on->toDateString()) : null)
                            <tr wire:key="order-{{ $forecast->id }}">
                                <td class="py-2 pr-4">
                                    <span class="font-semibold text-ink">{{ $forecast->order->reference() }}</span>
                                    <span class="text-gray-600">{{ $forecast->order->name }}</span>
                                    @if($forecast->order->plan_priority !== null)<x-ui.badge tone="accent">{{ __('Prioritet :p', ['p' => $forecast->order->plan_priority]) }}</x-ui.badge>@endif
                                </td>
                                <td class="py-2 pr-4">{{ $forecast->salesDocument?->customer?->name ?? __('Lagertillverkning') }}@if($forecast->salesDocument) <span class="text-gray-500">({{ __('order :n', ['n' => $forecast->salesDocument->number]) }})</span>@endif</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $forecast->promised_on?->toDateString() ?? '–' }}</td>
                                <td class="py-2 pr-4 tabular-nums">
                                    {{ $forecast->forecast_delivery_on?->toDateString() ?? __('Efter horisonten') }}
                                    @if($forecast->hasFlag('assumed_material'))<span class="block text-xs text-amber-800">{{ __('Antaget materialdatum') }}</span>@endif
                                    @if($forecast->hasFlag('lock_broken'))<span class="block text-xs text-red-700">{{ __('Låsning går inte att hålla') }}</span>@endif
                                    @if($forecast->hasFlag('lock_out_of_order'))<span class="block text-xs text-red-700">{{ __('Ett låst steg börjar innan steget före är klart') }}</span>@endif
                                    @if($forecast->hasFlag('no_resource'))<span class="block text-xs text-red-700">{{ __('Ingen med rätt kompetens') }}</span>@endif
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $difference === null ? '–' : trans_choice(':count arbetsdag|:count arbetsdagar', $difference, ['count' => ($difference > 0 ? '+' : '').$difference]) }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :tone="$forecast->status->tone()">{{ $forecast->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4">{{ $forecast->waits_for->label() }}@if($forecast->material_ready_on) <span class="text-xs text-gray-500">({{ __('material :date', ['date' => $forecast->material_ready_on->toDateString()]) }})</span>@endif</td>
                                <td class="py-2 text-right">
                                    <button type="button" wire:click="open({{ $forecast->production_order_id }})" class="text-sm font-semibold text-accent-700 underline" aria-expanded="{{ $open?->id === $forecast->production_order_id ? 'true' : 'false' }}">{{ $open?->id === $forecast->production_order_id ? __('Stäng') : __('Visa steg') }}</button>
                                </td>
                            </tr>
                            @if($open?->id === $forecast->production_order_id)
                                <tr wire:key="detail-{{ $forecast->id }}">
                                    <td colspan="8" class="bg-stone-50 px-3 py-4">
                                        @if($canPlan)
                                            <form wire:submit="savePriority({{ $open->id }})" class="mb-4 flex flex-wrap items-end gap-3">
                                                <x-ui.field for="priority-{{ $open->id }}" :label="__('Prioritet')" error="priority.{{ $open->id }}" :hint="__('Lägst först. Tomt betyder ingen egen prioritet; då gäller utlovat datum.')">
                                                    <input id="priority-{{ $open->id }}" wire:model="priority.{{ $open->id }}" inputmode="numeric" class="{{ $input }} w-28 text-right" placeholder="{{ $open->plan_priority }}">
                                                </x-ui.field>
                                                <x-secondary-button type="submit">{{ __('Spara prioritet') }}</x-secondary-button>
                                            </form>
                                        @endif
                                        <ol class="space-y-3">
                                            @foreach($open->operations as $operation)
                                                <li wire:key="op-{{ $operation->id }}" class="rounded-lg border border-stone-200 bg-white p-3">
                                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                        <p class="font-semibold text-ink">{{ $operation->operation->name }} <span class="font-normal text-gray-600">{{ App\Modules\Manufacturing\Livewire\Planning\OrdersPage::duration($operation->planned_minutes) }}</span></p>
                                                        @foreach($locks->get($operation->id, collect()) as $lock)
                                                            <span class="text-xs">
                                                                <x-ui.badge tone="accent">{{ $lock->kind->label() }}: {{ $name($lock->employee) }}@if($lock->minutes) ({{ $lock->minutes }} min)@endif</x-ui.badge>
                                                                @if($canPlan)<button type="button" wire:click="release({{ $lock->id }})" class="ml-1 underline">{{ __('Ta bort') }}</button>@endif
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                    <ul class="mt-1 text-sm text-gray-700">
                                                        @forelse($bars->get($operation->id, collect()) as $bar)
                                                            <li class="flex flex-wrap items-center gap-2">
                                                                {{ $name($bar->employee) }}@if($bar->workstation), {{ $bar->workstation->name }}@endif:
                                                                <span class="tabular-nums">{{ $bar->starts_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i') }} – {{ $bar->ends_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i') }}</span>
                                                                @if($bar->locked)<x-ui.badge>{{ __('Låst') }}</x-ui.badge>@elseif($canPlan && $bar->part === 0)<button type="button" wire:click="lock({{ $bar->id }})" class="text-xs font-semibold text-accent-700 underline">{{ __('Lås här') }}</button>@endif
                                                            </li>
                                                        @empty
                                                            <li class="text-gray-500">{{ __('Inget kvar att planera, eller efter horisonten.') }}</li>
                                                        @endforelse
                                                    </ul>
                                                    @if($canPlan)
                                                        <div class="mt-3 grid gap-3 lg:grid-cols-3">
                                                            <form wire:submit="assign({{ $operation->id }})" class="flex items-end gap-2">
                                                                <x-ui.field for="assign-{{ $operation->id }}" :label="__('Flytta till')" error="assign.employee_id">
                                                                    <select id="assign-{{ $operation->id }}" wire:model="assignTo.{{ $operation->id }}" class="{{ $input }}">
                                                                        <option value="">{{ __('Välj person') }}</option>
                                                                        @foreach($qualified($operation->operation->required_skill_id) as $person)
                                                                            <option value="{{ $person->id }}">{{ $name($person) }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                </x-ui.field>
                                                                <x-secondary-button type="submit">{{ __('Flytta') }}</x-secondary-button>
                                                            </form>
                                                            <form wire:submit="split({{ $operation->id }})" class="flex items-end gap-2">
                                                                <x-ui.field for="split-{{ $operation->id }}" :label="__('Dela med')" error="split.employee_id">
                                                                    <select id="split-{{ $operation->id }}" wire:model="splitTo.{{ $operation->id }}" class="{{ $input }}">
                                                                        <option value="">{{ __('Välj person') }}</option>
                                                                        @foreach($qualified($operation->operation->required_skill_id) as $person)
                                                                            <option value="{{ $person->id }}">{{ $name($person) }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                </x-ui.field>
                                                                <x-ui.field for="split-minutes-{{ $operation->id }}" :label="__('Minuter')" error="split.minutes">
                                                                    <input id="split-minutes-{{ $operation->id }}" wire:model="splitMinutes.{{ $operation->id }}" inputmode="numeric" class="{{ $input }} w-20 text-right">
                                                                </x-ui.field>
                                                                <x-secondary-button type="submit">{{ __('Dela') }}</x-secondary-button>
                                                            </form>
                                                            <form wire:submit="saveRemaining({{ $operation->id }}, 'OVERRUN')" class="flex items-end gap-2">
                                                                <x-ui.field for="remaining-{{ $operation->id }}" :label="__('Återstår, minuter')" error="remaining.minutes" :hint="__('Ny uppskattning när steget tar längre tid. Tomt går tillbaka till planerad tid.')">
                                                                    <input id="remaining-{{ $operation->id }}" wire:model="remaining.{{ $operation->id }}" inputmode="numeric" class="{{ $input }} w-24 text-right" placeholder="{{ $operation->remaining_minutes_override }}">
                                                                </x-ui.field>
                                                                <x-secondary-button type="submit">{{ __('Spara') }}</x-secondary-button>
                                                            </form>
                                                        </div>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ol>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
