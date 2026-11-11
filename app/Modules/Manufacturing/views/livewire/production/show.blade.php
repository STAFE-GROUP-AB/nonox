@php
    use App\Modules\Inventory\Domain\Quantity;
    use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
    use App\Modules\Manufacturing\Livewire\Production\ProductionOrderPage;
    use App\Support\Money\Money;

    $input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs';
    $button = 'inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700';
    $secondary = 'inline-flex items-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-stone-50';
    $money = fn (int $ore) => Money::ofOre($ore);
    $q = fn (int $milli): string => Quantity::format($milli);
    $status = $order->status;
@endphp
<div class="space-y-6">
    <x-ui.page-heading :title="__('Tillverkningsorder :number', ['number' => $order->reference()])" :lead="__('En tillverkningsorder bokförs inte. Komponenterna lämnar lagret till sitt lagervärde, och de färdiga produkterna tas emot till tillverkningskostnaden. Pågående order räknas som produkter i arbete vid bokslutet.')" />

    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-600">{{ __('Produkt') }}</dt><dd class="font-semibold">{{ $order->article?->name }} <span class="font-normal text-gray-500">{{ $order->article?->number }}</span></dd></div>
                <div><dt class="text-gray-600">{{ __('Antal') }}</dt><dd class="tabular-nums">{{ $q($order->quantity_milli) }} {{ $stockUnit }}@if($order->entered_quantity !== null && $order->unit) <span class="text-gray-500">({{ $order->entered_quantity }} {{ $order->unit->code }})</span>@endif</dd></div>
                <div><dt class="text-gray-600">{{ __('Status') }}</dt><dd><x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge></dd></div>
                <div><dt class="text-gray-600">{{ __('Planerad start') }}</dt><dd class="tabular-nums">{{ $order->planned_start_on?->toDateString() ?? '–' }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Klart senast') }}</dt><dd class="tabular-nums">{{ $order->due_on?->toDateString() ?? '–' }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Tas emot på') }}</dt><dd>{{ $order->location?->label() ?? '–' }}</dd></div>
                <div><dt class="text-gray-600">{{ __('Kundorder') }}</dt><dd>
                    @if($order->salesDocument)
                        <a href="{{ route('invoicing.orders.show', ['document' => $order->salesDocument->id]) }}" class="text-accent-700 underline">{{ $order->salesDocument->title() }}</a> · {{ $order->salesDocument->customer?->name }}
                    @else
                        {{ __('Mot lager') }}
                    @endif
                </dd></div>
                <div><dt class="text-gray-600">{{ __('Stycklista') }}</dt><dd>{{ $order->name }}@if($order->source) <span class="text-gray-500">{{ __('kopia av version :version', ['version' => $order->source->version]) }}</span>@endif</dd></div>
                @if($order->started_on)
                    <div><dt class="text-gray-600">{{ __('Startad') }}</dt><dd class="tabular-nums">{{ $order->started_on->toDateString() }}</dd></div>
                @endif
                @if($order->finished_on)
                    <div><dt class="text-gray-600">{{ __('Färdig') }}</dt><dd class="tabular-nums">{{ $order->finished_on->toDateString() }} · {{ $q((int) $order->produced_milli) }} {{ $stockUnit }}</dd></div>
                @endif
                @if($order->cancelled_on)
                    <div class="sm:col-span-3"><dt class="text-gray-600">{{ __('Makulerad') }}</dt><dd>{{ $order->cancelled_on->toDateString() }}@if($order->cancel_reason) · {{ $order->cancel_reason }}@endif</dd></div>
                @endif
                @if($order->notes)
                    <div class="sm:col-span-3"><dt class="text-gray-600">{{ __('Anteckningar för verkstaden') }}</dt><dd class="whitespace-pre-line">{{ $order->notes }}</dd></div>
                @endif
            </dl>

            @if($canManage)
                <div class="flex flex-wrap gap-3">
                    @if($status->isOpen() && ! $editing)
                        <button type="button" wire:click="edit" class="{{ $secondary }}">{{ __('Ändra') }}</button>
                    @endif
                    @if($status === ProductionOrderStatus::Draft)
                        <button type="button" wire:click="release" wire:confirm="{{ __('Frisläppa ordern? Komponenterna reserveras och räknas i inköpsförslaget. Antal och stycklista ändras sedan bara om ordern tas tillbaka till utkast.') }}" class="{{ $button }}">{{ __('Frisläpp') }}</button>
                    @endif
                    @if($status === ProductionOrderStatus::Released)
                        <button type="button" wire:click="$set('starting', true)" class="{{ $button }}">{{ __('Starta') }}</button>
                        <button type="button" wire:click="backToDraft" wire:confirm="{{ __('Ta tillbaka ordern till utkast? Reservationen av komponenterna tas bort.') }}" class="{{ $secondary }}">{{ __('Tillbaka till utkast') }}</button>
                    @endif
                    @if($status === ProductionOrderStatus::Started)
                        <button type="button" wire:click="issue" wire:confirm="{{ __('Ta ut det som återstår av komponenterna ur lagret? Uttaget kan bara ångras genom att makulera ordern.') }}" class="{{ $secondary }}">{{ __('Ta ut komponenter') }}</button>
                        <button type="button" wire:click="openFinish" class="{{ $button }}">{{ __('Färdigmeld') }}</button>
                    @endif
                    @if($status->isOpen())
                        <button type="button" wire:click="$set('cancelling', true)" class="text-sm font-semibold text-gray-700 underline">{{ __('Makulera') }}</button>
                    @endif
                </div>
            @endif
        </div>

        @if($status === ProductionOrderStatus::Released && ! $allCovered)
            <p class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{{ __('Allt material finns inte i lager. Se vad som saknas nedan, eller gör inköpsorder från inköpsförslaget.') }} <a href="{{ route('inventory.purchaseProposals') }}" class="font-semibold underline">{{ __('Inköpsförslag') }}</a></p>
        @endif
    </x-ui.card>

    @if($starting && $status === ProductionOrderStatus::Released)
        <x-ui.card :title="__('Starta ordern')" :description="__('Ordern startar i dag. Komponenterna kan tas ut ur lagret nu eller senare; det som återstår tas ut när ordern färdigmeldas.')">
            <form wire:submit="start" class="space-y-4">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="issueOnStart" class="mt-1 rounded-sm border-stone-300 text-ink focus:ring-accent-500">
                    <span class="text-sm text-gray-700">{{ __('Ta ut alla komponenter ur lagret nu') }}</span>
                </label>
                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" wire:loading.attr="disabled" wire:confirm="{{ __('Starta ordern? Uttag ur lagret kan bara ångras genom att makulera ordern.') }}">{{ __('Starta ordern') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('starting', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($cancelling && $status->isOpen())
        <x-ui.card :title="__('Makulera ordern')" :description="__('Numret finns kvar, men ordern gäller inte längre. Komponenter som har tagits ut läggs tillbaka i lagret till det värde de lämnade med. Rapporterad arbetstid finns kvar på ordern.')">
            <form wire:submit="cancelOrder" class="space-y-4">
                <x-ui.field for="to-cancel-reason" :label="__('Varför makuleras ordern?')" error="reason" :hint="$status === ProductionOrderStatus::Started ? __('Krävs för en startad order.') : null">
                    <input id="to-cancel-reason" wire:model="cancelReason" maxlength="255" class="{{ $input }}">
                </x-ui.field>
                <div class="flex flex-wrap gap-3">
                    <x-danger-button type="submit" wire:loading.attr="disabled" wire:confirm="{{ __('Makulera tillverkningsordern? Det går inte att ångra.') }}">{{ __('Makulera ordern') }}</x-danger-button>
                    <x-secondary-button type="button" wire:click="$set('cancelling', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($editing && $status->isOpen())
        <x-ui.card :title="__('Ändra ordern')" :description="$status === ProductionOrderStatus::Draft ? __('Antalet ändrar vad ordern behöver av varje komponent och de planerade minuterna.') : __('Antalet ändras bara medan ordern är ett utkast.')">
            <form wire:submit="saveDetails" class="grid gap-5 sm:grid-cols-6">
                @if($status === ProductionOrderStatus::Draft)
                    <x-ui.field for="to-edit-quantity" :label="__('Antal')" error="form.quantity" class="sm:col-span-2">
                        <input id="to-edit-quantity" wire:model="form.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums" required>
                    </x-ui.field>
                    <x-ui.field for="to-edit-unit" :label="__('Enhet')" error="form.unit" class="sm:col-span-1">
                        <select id="to-edit-unit" wire:model="form.unit" class="{{ $input }}">
                            @foreach($orderUnits as $unit)
                                <option value="{{ $loop->first ? '' : $unit }}">{{ $unit }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                @endif
                <x-ui.field for="to-edit-location" :label="__('Tas emot på')" error="form.location_id" class="sm:col-span-3">
                    <select id="to-edit-location" wire:model="form.location_id" class="{{ $input }}">
                        <option value="">{{ __('Välj lagerställe') }}</option>
                        @foreach($locations as $location)
                            <option value="{{ $location->id }}">{{ $location->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="to-edit-start" :label="__('Planerad start')" error="form.planned_start_on" class="sm:col-span-2">
                    <input id="to-edit-start" type="date" wire:model="form.planned_start_on" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="to-edit-due" :label="__('Klart senast')" error="form.due_on" class="sm:col-span-2">
                    <input id="to-edit-due" type="date" wire:model="form.due_on" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="to-edit-notes" :label="__('Anteckningar för verkstaden')" error="form.notes" class="sm:col-span-6">
                    <textarea id="to-edit-notes" wire:model="form.notes" rows="3" maxlength="2000" class="{{ $input }}"></textarea>
                </x-ui.field>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editing', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($finishing && $status === ProductionOrderStatus::Started)
        <x-ui.card :title="__('Färdigmeld ordern')" :description="__('Det som återstår av komponenterna tas ut ur lagret, och produkterna tas emot till tillverkningskostnaden: komponenternas lagervärde plus arbetet till arbetsstegens timkostnad. Skriv den faktiska tiden per arbetssteg; tomt betyder den planerade tiden, eller den tid som redan är rapporterad.')">
            <form wire:submit="finishOrder" class="grid gap-5 sm:grid-cols-6">
                <x-ui.field for="to-finish-date" :label="__('Färdig den')" error="finish.date" class="sm:col-span-2">
                    <input id="to-finish-date" type="date" wire:model="finish.date" class="{{ $input }}" required>
                </x-ui.field>
                <x-ui.field for="to-finish-produced" :label="__('Antal tillverkade (:unit)', ['unit' => $stockUnit])" error="finish.produced" class="sm:col-span-2">
                    <input id="to-finish-produced" wire:model="finish.produced" inputmode="decimal" class="{{ $input }} text-right tabular-nums" required>
                </x-ui.field>
                <fieldset class="sm:col-span-6">
                    <legend class="text-sm font-semibold text-ink">{{ __('Faktisk tid per arbetssteg, minuter') }}</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-3">
                        @foreach($order->operations as $operation)
                            @php($already = (int) ($reported->get($operation->id)?->getAttribute('minutes') ?? 0))
                            <x-ui.field for="to-finish-minutes-{{ $operation->id }}" :label="$operation->operation?->name ?? __('Arbetssteg')" error="finish.minutes.{{ $operation->id }}" :hint="$already > 0 ? __('Rapporterat :minutes min', ['minutes' => $already]) : __('Planerat :minutes min', ['minutes' => $operation->planned_minutes])">
                                <input id="to-finish-minutes-{{ $operation->id }}" wire:model="finish.minutes.{{ $operation->id }}" inputmode="numeric" placeholder="{{ $already > 0 ? $already : $operation->planned_minutes }}" class="{{ $input }} text-right tabular-nums">
                            </x-ui.field>
                        @endforeach
                    </div>
                </fieldset>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled" wire:confirm="{{ __('Färdigmelda ordern? Komponenterna tas ut ur lagret och produkterna tas emot. Det går inte att ändra efteråt.') }}">{{ __('Färdigmeld och ta emot i lagret') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('finishing', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if($editingStructure && $status === ProductionOrderStatus::Draft)
        <x-ui.card :title="__('Ändra orderns stycklista')" :description="__('Ändringen gäller bara den här ordern. Produktens stycklista är oförändrad.')">
            <form wire:submit="saveStructure" class="grid gap-5 sm:grid-cols-6">
                <x-ui.field for="to-structure-name" :label="__('Namn')" error="structure.name" class="sm:col-span-3">
                    <input id="to-structure-name" wire:model="structure.name" maxlength="255" class="{{ $input }}">
                </x-ui.field>
                <x-ui.field for="to-structure-yield" :label="__('Gäller för antal')" error="structure.yield" class="sm:col-span-2" :hint="__('Hur många av produkten raderna räcker till. Oftast 1.')">
                    <input id="to-structure-yield" wire:model="structure.yield" inputmode="decimal" class="{{ $input }} text-right tabular-nums" required>
                </x-ui.field>
                <fieldset class="sm:col-span-6">
                    <legend class="text-sm font-semibold text-ink">{{ __('Komponenter och arbetssteg') }}</legend>
                    <x-input-error for="structure.lines" class="mb-2" />
                    <div class="space-y-3">
                        @foreach($structure['rows'] ?? [] as $index => $row)
                            <div class="grid gap-3 rounded-2xl bg-stone-50 p-3 sm:grid-cols-12" wire:key="to-row-{{ $index }}">
                                @if(($row['type'] ?? 'component') === 'operation')
                                    <x-ui.field for="to-row-{{ $index }}-operation" :label="__('Arbetssteg')" error="structure.lines.{{ $index }}.operation_id" class="sm:col-span-6">
                                        <select id="to-row-{{ $index }}-operation" wire:model="structure.rows.{{ $index }}.operation_id" class="{{ $input }}">
                                            <option value="">{{ __('Välj arbetssteg') }}</option>
                                            @foreach($activeOperations as $option)
                                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                    <x-ui.field for="to-row-{{ $index }}-minutes" :label="__('Minuter')" error="structure.lines.{{ $index }}.minutes" class="sm:col-span-2">
                                        <input id="to-row-{{ $index }}-minutes" wire:model="structure.rows.{{ $index }}.minutes" inputmode="numeric" class="{{ $input }} text-right tabular-nums">
                                    </x-ui.field>
                                    <div class="sm:col-span-2"></div>
                                @else
                                    <x-ui.field for="to-row-{{ $index }}-article" :label="__('Komponent')" error="structure.lines.{{ $index }}.article_id" class="sm:col-span-4">
                                        <select id="to-row-{{ $index }}-article" wire:model.live="structure.rows.{{ $index }}.article_id" class="{{ $input }}">
                                            <option value="">{{ __('Välj artikel') }}</option>
                                            @foreach($articles as $option)
                                                <option value="{{ $option->id }}">{{ $option->number }} {{ $option->name }} ({{ $option->stockUnit?->code ?? $option->unit }})</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                    <x-ui.field for="to-row-{{ $index }}-quantity" :label="__('Nettomängd')" error="structure.lines.{{ $index }}.quantity" class="sm:col-span-2">
                                        <input id="to-row-{{ $index }}-quantity" wire:model="structure.rows.{{ $index }}.quantity" inputmode="decimal" class="{{ $input }} text-right tabular-nums">
                                    </x-ui.field>
                                    <x-ui.field for="to-row-{{ $index }}-unit" :label="__('Enhet')" error="structure.lines.{{ $index }}.unit" class="sm:col-span-2">
                                        <select id="to-row-{{ $index }}-unit" wire:model="structure.rows.{{ $index }}.unit" class="{{ $input }}">
                                            @foreach($units[(int) $row['article_id']] ?? [] as $unit)
                                                <option value="{{ $loop->first ? '' : $unit }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                    </x-ui.field>
                                    <x-ui.field for="to-row-{{ $index }}-loss" :label="__('Spill %')" error="structure.lines.{{ $index }}.loss" class="sm:col-span-2">
                                        <input id="to-row-{{ $index }}-loss" wire:model="structure.rows.{{ $index }}.loss" inputmode="decimal" class="{{ $input }} text-right tabular-nums" placeholder="0">
                                    </x-ui.field>
                                @endif
                                <div class="flex items-end sm:col-span-2">
                                    <button type="button" wire:click="removeRow({{ $index }})" class="text-sm font-semibold text-gray-700 underline">{{ __('Ta bort') }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex flex-wrap gap-4">
                        <button type="button" wire:click="addRow('component')" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till komponent') }}</button>
                        <button type="button" wire:click="addRow('operation')" class="text-sm font-semibold text-accent-700 underline">{{ __('Lägg till arbetssteg') }}</button>
                    </div>
                </fieldset>
                <div class="flex flex-wrap gap-3 sm:col-span-6">
                    <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara orderns stycklista') }}</x-button>
                    <x-secondary-button type="button" wire:click="$set('editingStructure', false)">{{ __('Avbryt') }}</x-secondary-button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Komponenter')" :description="$status->isOpen() ? __('Behövs är det som återstår att ta ut, spill inräknat. Reserverat är det andra frisläppta och startade order behöver.') : null">
        @if($canManage && $status === ProductionOrderStatus::Draft && ! $editingStructure)
            <x-slot:cta>
                <x-secondary-button type="button" wire:click="editStructure">{{ __('Ändra orderns stycklista') }}</x-secondary-button>
            </x-slot:cta>
        @endif
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-gray-600">
                        <th class="py-2 pr-4 font-medium">{{ __('Komponent') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Ordern behöver') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Uttaget') }}</th>
                        @if($status->isOpen())
                            <th class="py-2 pr-4 text-right font-medium">{{ __('I lager') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Reserverat av andra') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Kommer') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Saknas') }}</th>
                        @endif
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Uttaget värde') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($order->components as $part)
                        @php($item = $availability[$part->id])
                        @php($unit = $part->component?->stockUnitCode())
                        <tr wire:key="to-component-{{ $part->id }}">
                            <td class="py-2 pr-4">
                                <span class="tabular-nums">{{ $part->component?->number }}</span> {{ $part->component?->name }}
                                @if($part->entered_unit)<span class="block text-xs text-gray-500">{{ __(':quantity :unit per :yield', ['quantity' => $part->entered_quantity, 'unit' => $part->entered_unit, 'yield' => $q($order->yield_milli)]) }}</span>@endif
                                @if(! $item['stocked'])<span class="block text-xs text-gray-500">{{ __('Ej lagerförd: räknas till kalkylpriset') }}</span>@endif
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums whitespace-nowrap">{{ $q($part->required_milli) }} {{ $unit }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums whitespace-nowrap">{{ $q($part->issued_milli) }}</td>
                            @if($status->isOpen())
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $item['stocked'] ? $q($item['on_hand']) : '–' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $item['stocked'] ? $q($item['reserved']) : '–' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">@if($item['coming'] > 0){{ $q($item['coming']) }}@if($item['coming_from'])<span class="block text-xs text-gray-500">{{ __('från :date', ['date' => substr((string) $item['coming_from'], 0, 10)]) }}</span>@endif @else – @endif</td>
                                <td @class(['py-2 pr-4 text-right tabular-nums', 'font-semibold text-accent-700' => $item['short'] > 0])>{{ $item['short'] > 0 ? $q($item['short']) : '–' }}</td>
                            @endif
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($part->issued_value_ore)" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Arbetssteg')" :description="__('Planerad tid är stycklistans minuter för hela antalet. Rapporterad tid kommer från färdigmeldningen, och senare från verkstadsterminalen.')">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-gray-600">
                        <th class="py-2 pr-4 font-medium">{{ __('Arbetssteg') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Planerad tid') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Rapporterad tid') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Timkostnad') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Arbetskostnad') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($order->operations as $operation)
                        @php($row = $reported->get($operation->id))
                        <tr wire:key="to-operation-{{ $operation->id }}">
                            <td class="py-2 pr-4">{{ $operation->operation?->name }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ProductionOrderPage::duration($operation->planned_minutes) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row ? ProductionOrderPage::duration((int) $row->getAttribute('minutes')) : '–' }}</td>
                            <td class="py-2 pr-4 text-right">@if($operation->operation)<x-ui.amount :money="$money($operation->operation->hourly_cost_ore)" />@endif</td>
                            <td class="py-2 pr-4 text-right">@if($row)<x-ui.amount :money="$money((int) $row->getAttribute('cost'))" />@else – @endif</td>
                        </tr>
                    @endforeach
                    @if($order->operations->isEmpty())
                        <tr><td colspan="5" class="py-2 text-gray-600">{{ __('Ordern har inga arbetssteg.') }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card :title="__('Kostnad')" :description="__('Kalkylen räknas från orderns stycklista med dagens priser. Utfallet är det som har tagits ut och rapporterats på ordern; för en färdig order är det tillverkningskostnaden som produkterna togs emot till.')">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-left text-gray-600">
                        <th class="py-2 pr-4 font-medium"></th>
                        <th class="py-2 pr-4 text-right font-medium">{{ __('Kalkyl') }}</th>
                        <th class="py-2 pr-4 text-right font-medium">{{ $status === ProductionOrderStatus::Finished ? __('Tillverkningskostnad') : __('Utfall hittills') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @php($final = $status === ProductionOrderStatus::Finished)
                    <tr>
                        <td class="py-2 pr-4">{{ __('Material') }}</td>
                        <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($estimate['material'])" /></td>
                        <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($final ? (int) $order->material_cost_ore : $actual['material'])" /></td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-4">{{ __('Arbete') }}</td>
                        <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($estimate['labour'])" /></td>
                        <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($final ? (int) $order->labour_cost_ore : $actual['labour'])" /></td>
                    </tr>
                    @if($overheadBp > 0 || (int) $order->overhead_cost_ore > 0)
                        <tr>
                            <td class="py-2 pr-4">{{ __('Omkostnader :percent', ['percent' => ProductionOrderPage::percent($final ? $order->overhead_bp : $overheadBp)]) }}</td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($estimate['overhead'])" /></td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($final ? (int) $order->overhead_cost_ore : $actual['overhead'])" /></td>
                        </tr>
                    @endif
                    <tr class="font-semibold">
                        <td class="py-2 pr-4">{{ __('Totalt') }}</td>
                        <td class="py-2 pr-4 text-right">@if($estimate['complete'])<x-ui.amount :money="$money($estimate['material'] + $estimate['labour'] + $estimate['overhead'])" />@else <span class="text-accent-700">{{ __('Saknar pris') }}</span>@endif</td>
                        <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money($final ? (int) $order->totalCostOre() : $actual['material'] + $actual['labour'] + $actual['overhead'])" /></td>
                    </tr>
                    @if($final)
                        <tr>
                            <td class="py-2 pr-4">{{ __('Per :unit', ['unit' => $stockUnit]) }}</td>
                            <td class="py-2 pr-4 text-right">@if($estimate['complete'])<x-ui.amount :money="$money($costing->materialPerUnitOre + $costing->labourPerUnitOre)" />@endif</td>
                            <td class="py-2 pr-4 text-right"><x-ui.amount :money="$money((int) $order->unit_cost_ore)" /></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
        @if($overheadBp === 0)
            <p class="mt-3 text-xs text-gray-500">{{ __('Omkostnader räknas inte in i lagervärdet. Det väljs under Tillverkning → Inställningar.') }}</p>
        @endif
    </x-ui.card>
</div>
