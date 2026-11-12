@php($input = 'block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs text-sm')
@php($person = fn ($id) => ($p = $people->firstWhere('id', $id)) ? trim($p->first_name.' '.$p->last_name) : '–')
@php($operationLabel = fn ($id) => ($op = $operations->firstWhere('id', $id)) ? ($orders->get($op->production_order_id)?->reference().' '.$op->operation->name) : '–')
<div class="space-y-4">
    @include('manufacturing::livewire.planning.run-bar')

    <x-ui.card :title="__('Lägg till en ändring')" :description="__('Ändringarna räknas i minnet mot planen som den är nu. Inget ändras förrän du trycker Tillämpa.')">
        <form wire:submit="add" class="grid gap-4 sm:grid-cols-6">
            <x-ui.field for="scenario-type" :label="__('Ändring')" class="sm:col-span-2">
                <select id="scenario-type" wire:model.live="form.type" class="{{ $input }}">
                    <option value="OVERTIME">{{ __('Övertid') }}</option>
                    <option value="ASSIGN">{{ __('Flytta arbetssteg till person') }}</option>
                    <option value="SPLIT">{{ __('Dela arbetssteg med person') }}</option>
                    <option value="PRIORITY">{{ __('Ändra prioritet') }}</option>
                </select>
            </x-ui.field>
            @if($form['type'] === 'PRIORITY')
                <x-ui.field for="scenario-order" :label="__('Tillverkningsorder')" class="sm:col-span-2">
                    <select id="scenario-order" wire:model="form.production_order_id" class="{{ $input }}">
                        <option value="">{{ __('Välj order') }}</option>
                        @foreach($orders as $order)
                            <option value="{{ $order->id }}">{{ $order->reference() }} {{ $order->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="scenario-priority" :label="__('Prioritet (lägst först)')" class="sm:col-span-2">
                    <input id="scenario-priority" wire:model="form.priority" inputmode="numeric" class="{{ $input }} text-right">
                </x-ui.field>
            @else
                <x-ui.field for="scenario-person" :label="__('Person')" class="sm:col-span-2">
                    <select id="scenario-person" wire:model="form.employee_id" class="{{ $input }}">
                        <option value="">{{ __('Välj person') }}</option>
                        @foreach($people as $p)
                            <option value="{{ $p->id }}">{{ trim($p->first_name.' '.$p->last_name) }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                @if($form['type'] === 'OVERTIME')
                    <x-ui.field for="scenario-date" :label="__('Datum')" error="date" class="sm:col-span-2">
                        <input id="scenario-date" type="date" wire:model="form.date" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="scenario-from" :label="__('Från')" class="sm:col-span-1">
                        <input id="scenario-from" type="time" wire:model="form.from" class="{{ $input }}">
                    </x-ui.field>
                    <x-ui.field for="scenario-to" :label="__('Till')" class="sm:col-span-1">
                        <input id="scenario-to" type="time" wire:model="form.to" class="{{ $input }}">
                    </x-ui.field>
                @else
                    <x-ui.field for="scenario-operation" :label="__('Arbetssteg')" class="sm:col-span-2">
                        <select id="scenario-operation" wire:model="form.order_operation_id" class="{{ $input }}">
                            <option value="">{{ __('Välj arbetssteg') }}</option>
                            @foreach($operations as $op)
                                <option value="{{ $op->id }}">{{ $orders->get($op->production_order_id)?->reference() }} {{ $op->operation->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    @if($form['type'] === 'SPLIT')
                        <x-ui.field for="scenario-minutes" :label="__('Minuter till personen')" class="sm:col-span-2">
                            <input id="scenario-minutes" wire:model="form.minutes" inputmode="numeric" class="{{ $input }} text-right">
                        </x-ui.field>
                    @endif
                @endif
            @endif
            <div class="sm:col-span-6"><x-secondary-button type="submit">{{ __('Lägg till') }}</x-secondary-button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="__('Scenariot')">
        @if($changes === [])
            <p class="text-sm text-gray-600">{{ __('Inga ändringar ännu.') }}</p>
        @else
            <ul class="divide-y divide-stone-100 text-sm">
                @foreach($changes as $index => $change)
                    <li wire:key="change-{{ $index }}" class="flex items-center justify-between gap-3 py-2">
                        <span>
                            @switch($change['type'])
                                @case('OVERTIME') {{ __('Övertid för :person :from–:to', ['person' => $person($change['employee_id']), 'from' => $change['starts_at'], 'to' => substr($change['ends_at'], 11)]) }} @break
                                @case('ASSIGN') {{ __('Flytta :operation till :person', ['operation' => $operationLabel($change['order_operation_id']), 'person' => $person($change['employee_id'])]) }} @break
                                @case('SPLIT') {{ __('Dela :operation: :minutes min till :person', ['operation' => $operationLabel($change['order_operation_id']), 'minutes' => $change['minutes'], 'person' => $person($change['employee_id'])]) }} @break
                                @case('PRIORITY') {{ __('Prioritet :priority för :order', ['priority' => $change['priority'] ?? '–', 'order' => $orders->get($change['production_order_id'])?->reference()]) }} @break
                            @endswitch
                        </span>
                        <button type="button" wire:click="remove({{ $index }})" class="text-sm underline">{{ __('Ta bort') }}</button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-button type="button" wire:click="compute" wire:loading.attr="disabled">{{ __('Räkna') }}</x-button>
                @if($outcomeRows !== null && $scenarioId !== null)
                    <x-secondary-button type="button" wire:click="apply" wire:confirm="{{ __('Tillämpa scenariot? Ändringarna sparas och planen beräknas om.') }}">{{ __('Tillämpa') }}</x-secondary-button>
                @endif
            </div>
        @endif

        @if($outcomeRows !== null)
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Order') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Utan ändringar') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Med ändringar') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Ändring') }}</th>
                            <th class="py-2 font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($outcomeRows as $orderId => $row)
                            @php($status = App\Modules\Manufacturing\Domain\ForecastStatus::from($row['status']))
                            <tr wire:key="outcome-{{ $orderId }}">
                                <td class="py-2 pr-4">{{ $orders->get($orderId)?->reference() }} {{ $orders->get($orderId)?->name }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['before'] ?? __('Efter horisonten') }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['after'] ?? __('Efter horisonten') }}</td>
                                <td @class(['py-2 pr-4 text-right tabular-nums', 'text-red-700' => ($row['change'] ?? 0) > 0, 'text-green-800' => ($row['change'] ?? 0) < 0])>{{ $row['change'] === null ? '–' : (($row['change'] > 0 ? '+' : '').$row['change']) }}</td>
                                <td class="py-2"><x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
