@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
@php($kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format())
@php($hours = fn (int $minutes): string => rtrim(rtrim(number_format($minutes / 60, 2, ',', ' '), '0'), ','))
<div class="space-y-6">
    {{-- Warnings: advice only, never a block. --}}
    @if($approximate !== null && $approximate['exceeded'])
        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">{{ __('Huvudarbetet är nu :recorded inklusive moms, mer än :limit (det ungefärliga priset :approximate plus avtalad gräns). Enligt konsumenttjänstlagen får ett ungefärligt pris inte överskridas med mer än 15 procent om inget annat avtalats. Kontakta kunden innan arbetet fortsätter. Godkända ÄTA räknas inte in: :changes.', ['recorded' => $kr($approximate['recorded']), 'limit' => $kr($approximate['limit']), 'approximate' => $kr($approximate['approximate']), 'changes' => $kr($approximate['change_orders'])]) }}</p>
    @elseif($approximate !== null)
        <p class="rounded-lg bg-stone-50 px-4 py-3 text-sm text-gray-700" role="status">{{ __('Upparbetat huvudarbete :recorded av ungefärligt pris :approximate inklusive moms (gräns :limit).', ['recorded' => $kr($approximate['recorded']), 'approximate' => $kr($approximate['approximate']), 'limit' => $kr($approximate['limit'])]) }}</p>
    @endif
    @foreach($deadlines as $deadline)
        <p @class(['rounded-lg px-4 py-3 text-sm', 'bg-red-50 text-red-900' => $deadline['overdue'], 'bg-amber-50 text-amber-900' => ! $deadline['overdue']]) role="alert">{{ $deadline['overdue'] ? __('Ofakturerat arbete från :month skulle ha fakturerats senast :date.', ['month' => $deadline['month'], 'date' => $deadline['deadline']]) : __('Ofakturerat arbete från :month måste faktureras senast :date.', ['month' => $deadline['month'], 'date' => $deadline['deadline']]) }}</p>
    @endforeach
    @if($staffLedger)
        <p class="rounded-lg bg-stone-50 px-4 py-3 text-sm text-gray-700" role="status">{{ __('Kan kräva personalliggare på byggarbetsplatsen: kunden är ett företag och det förväntade värdet är högre än fyra prisbasbelopp. Byggherren ansvarar för utrustningen; personalliggaren för bygg finns inte i NONOX än.') }}</p>
    @endif
    @foreach($rotAconto as $warning)
        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">{{ __('A conto-faktura :number med avdrag betalades :year men arbetet var inte klart den 31 januari året efter. Kontrollera avdraget med Skatteverket.', ['number' => $warning['invoice_number'], 'year' => $warning['paid_year']]) }}</p>
    @endforeach
    @if($changeOrders->contains(fn ($changeOrder) => ! $changeOrder->status->allowsInvoicing() && ! $changeOrder->status->isDecided()))
        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">{{ __('Tid och material på ÄTA som inte är godkända hålls tillbaka från fakturaunderlaget tills kunden har godkänt priset.') }}</p>
    @endif

    <x-ui.card :title="__('Arbetsorder :number', ['number' => $workOrder->number])">
        @if($canManage && $workOrder->status->isOpen())
            <x-slot:cta>
                <a href="{{ route('workOrders.edit', $workOrder->id) }}" class="text-sm font-medium text-accent-700 hover:underline">{{ __('Ändra') }}</a>
            </x-slot:cta>
        @endif
        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">{{ __('Kund') }}</dt><dd>{{ $customer?->name }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Status') }}</dt><dd>{{ $workOrder->status->label() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Prisform') }}</dt><dd>{{ $workOrder->price_form->label() }}@if($workOrder->contract_sum_ore) · {{ $kr($workOrder->contract_sum_ore->ore) }} {{ __('exkl. moms') }}@endif @if($workOrder->approximate_price_ore) · {{ $kr($workOrder->approximate_price_ore->ore) }} {{ __('inkl. moms') }}@endif</dd></div>
            <div><dt class="text-gray-500">{{ __('Moms') }}</dt><dd>{{ $workOrder->reverse_charge ? __('Omvänd betalningsskyldighet för byggtjänster') : ($workOrder->vat_rate / 100).' %' }}</dd></div>
            @if($workOrder->tax_reduction)
                <div><dt class="text-gray-500">{{ __('Avdrag') }}</dt><dd>{{ App\Domain\Invoicing\TaxReduction::label($workOrder->tax_reduction) }}@if($workOrder->property_designation) · {{ $workOrder->property_designation }}@endif @if($workOrder->buyers->isNotEmpty()) · {{ $workOrder->buyers->map(fn ($buyer) => $buyer->customer?->name.' '.($buyer->share_bp / 100).' %')->implode(', ') }}@endif</dd></div>
            @endif
            @if($workOrder->project)
                <div><dt class="text-gray-500">{{ __('Projekt') }}</dt><dd>{{ $workOrder->project->label() }}</dd></div>
            @endif
            <div><dt class="text-gray-500">{{ __('Plats') }}</dt><dd>{{ collect([$workOrder->site_address_line1, trim($workOrder->site_postal_code.' '.$workOrder->site_city)])->filter()->implode(', ') ?: '–' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Utförare') }}</dt><dd>{{ $workOrder->assignments->map(fn ($assignment) => $assignment->user?->name)->filter()->implode(', ') ?: '–' }}</dd></div>
            @if($workOrder->standard_contract)
                <div><dt class="text-gray-500">{{ __('Standardavtal') }}</dt><dd>{{ $workOrder->standard_contract }}</dd></div>
            @endif
        </dl>
        @if($consumer)
            <p class="mt-3 text-xs text-gray-500">{{ __('Kunden är konsument: tilläggsarbete ska godkännas av kunden innan det görs (8 § konsumenttjänstlagen), och kunden kan begära en specificerad faktura (40 §).') }}</p>
        @endif
        <div class="mt-4 flex flex-wrap gap-3">
            @if($canReport && $workOrder->status->acceptsReports())
                <a href="{{ route('workOrders.field', $workOrder->id) }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Rapportera på plats') }}</a>
            @endif
            @if($canManage)
                @foreach($nextStatuses as $next)
                    <x-secondary-button type="button" wire:click="changeStatus('{{ $next->value }}')" wire:confirm="{{ match ($next->value) { 'CLOSED' => __('Avsluta arbetsordern? Den kan inte ändras efteråt, och portkod och nycklar tas bort.'), 'CANCELLED' => __('Makulera arbetsordern? Den kan inte ändras efteråt, och portkod och nycklar tas bort.'), default => __('Ändra status till :status?', ['status' => mb_strtolower($next->label())]) } }}">{{ __('Ändra till :status', ['status' => mb_strtolower($next->label())]) }}</x-secondary-button>
                @endforeach
                <a href="{{ route('workOrders.specification', $workOrder->id) }}" class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">{{ __('Specificerat underlag (PDF)') }}</a>
            @endif
        </div>
    </x-ui.card>

    @if($canInvoice && $workOrder->status->isOpen())
        <x-ui.card :title="__('Fakturaunderlag')" :description="__('Tid och material som inte är fakturerat blir ett fakturautkast. ROT eller RUT, omvänd byggmoms och arbetade timmar följer med. Ingenting bokförs förrän du skapar fakturan.')">
            <form wire:submit="createBasis" class="flex flex-wrap items-end gap-4">
                <x-ui.field for="basis-date" :label="__('Till och med')" error="work_order"><input id="basis-date" type="date" wire:model="basisDate" class="{{ $input }}"></x-ui.field>
                <x-button type="submit" wire:loading.attr="disabled" wire:confirm="{{ __('Skapa ett fakturautkast av det som inte är fakturerat? Posterna reserveras för utkastet tills det skapas eller tas bort.') }}">{{ __('Skapa fakturaunderlag') }}</x-button>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Tid')">
        @if($canManage && $workOrder->status->acceptsReports())
            <form wire:submit="recordTime" class="mb-6 grid gap-4 sm:grid-cols-6">
                <x-ui.field for="time-kind" :label="__('Typ av arbete')" error="time.labour_kind_id" class="sm:col-span-2">
                    <select id="time-kind" wire:model.live="time.labour_kind_id" class="{{ $input }}">
                        @foreach($kinds as $kind)<option value="{{ $kind->id }}">{{ $kind->name }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="time-date" :label="__('Datum')" error="time.work_date" class="sm:col-span-2"><input id="time-date" type="date" wire:model="time.work_date" class="{{ $input }}"></x-ui.field>
                @if(optional($kinds->firstWhere('id', (int) $time['labour_kind_id']))->unit?->isTime() ?? true)
                    <x-ui.field for="time-hours" :label="__('Timmar')" error="time.hours" class="sm:col-span-2"><input id="time-hours" wire:model="time.hours" inputmode="decimal" placeholder="1,5" class="{{ $input }}"></x-ui.field>
                @else
                    <x-ui.field for="time-quantity" :label="__('Antal')" error="time.quantity" class="sm:col-span-2"><input id="time-quantity" wire:model="time.quantity" inputmode="decimal" class="{{ $input }}"></x-ui.field>
                @endif
                <x-ui.field for="time-change" :label="__('Del')" error="time.change_order_id" class="sm:col-span-2">
                    <select id="time-change" wire:model="time.change_order_id" class="{{ $input }}">
                        <option value="">{{ __('Huvudarbete') }}</option>
                        @foreach($changeOrders as $changeOrder)<option value="{{ $changeOrder->id }}">{{ $changeOrder->label() }} {{ $changeOrder->title }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="time-rate" :label="__('Annat pris (valfritt)')" error="time.rate" class="sm:col-span-2"><input id="time-rate" wire:model="time.rate" inputmode="decimal" class="{{ $input }}"></x-ui.field>
                <x-ui.field for="time-note" :label="__('Anteckning')" error="time.note" class="sm:col-span-2"><input id="time-note" wire:model="time.note" maxlength="255" class="{{ $input }}"></x-ui.field>
                <label class="flex items-center gap-2 text-sm sm:col-span-3"><input type="checkbox" wire:model="time.billable" class="rounded border-gray-300 text-accent-600"> {{ __('Debiterbar') }}</label>
                <div class="sm:col-span-3 sm:text-right"><x-button type="submit" wire:loading.attr="disabled">{{ __('Registrera tid') }}</x-button></div>
            </form>
        @endif
        @include('work-orders::livewire.partials.entries', ['entries' => $timeEntries, 'kind' => 'time'])
    </x-ui.card>

    <x-ui.card :title="__('Material')">
        @if($canManage && $workOrder->status->acceptsReports())
            <form wire:submit="recordMaterial" class="mb-6 grid gap-4 sm:grid-cols-6">
                <x-ui.field for="material-source" :label="__('Varifrån')" error="material.source" class="sm:col-span-2">
                    <select id="material-source" wire:model.live="material.source" class="{{ $input }}">
                        <option value="FREE">{{ __('Övrigt material') }}</option>
                        <option value="SUPPLIER_INVOICE_ROW">{{ __('Från leverantörsfaktura') }}</option>
                        <option value="STOCK">{{ __('Från lager') }}</option>
                    </select>
                </x-ui.field>
                @if($material['source'] === 'SUPPLIER_INVOICE_ROW')
                    <x-ui.field for="material-row" :label="__('Rad på leverantörsfaktura i projektet')" error="material.supplier_invoice_row_id" class="sm:col-span-4">
                        <select id="material-row" wire:model="material.supplier_invoice_row_id" class="{{ $input }}">
                            <option value="">{{ $supplierRows->isEmpty() ? __('Inga attesterade rader på projektet') : __('Välj rad') }}</option>
                            @foreach($supplierRows as $row)<option value="{{ $row->id }}">{{ $row->description ?: __('Rad') }} · {{ $row->amount_ore->format() }}</option>@endforeach
                        </select>
                    </x-ui.field>
                @else
                    <x-ui.field for="material-description" :label="__('Material')" error="material.description" class="sm:col-span-4"><input id="material-description" wire:model="material.description" maxlength="255" class="{{ $input }}"></x-ui.field>
                    <x-ui.field for="material-quantity" :label="__('Antal')" error="material.quantity" class="sm:col-span-1"><input id="material-quantity" wire:model="material.quantity" inputmode="decimal" class="{{ $input }}"></x-ui.field>
                    <x-ui.field for="material-unit" :label="__('Enhet')" error="material.unit" class="sm:col-span-1"><input id="material-unit" wire:model="material.unit" maxlength="10" class="{{ $input }}"></x-ui.field>
                    <x-ui.field for="material-cost" :label="__('Inköpspris per enhet')" error="material.unit_cost" class="sm:col-span-2"><input id="material-cost" wire:model="material.unit_cost" inputmode="decimal" class="{{ $input }}"></x-ui.field>
                @endif
                <x-ui.field for="material-price" :label="__('Försäljningspris per enhet (valfritt)')" error="material.unit_price" class="sm:col-span-2"><input id="material-price" wire:model="material.unit_price" inputmode="decimal" class="{{ $input }}"></x-ui.field>
                <x-ui.field for="material-change" :label="__('Del')" error="material.change_order_id" class="sm:col-span-2">
                    <select id="material-change" wire:model="material.change_order_id" class="{{ $input }}">
                        <option value="">{{ __('Huvudarbete') }}</option>
                        @foreach($changeOrders as $changeOrder)<option value="{{ $changeOrder->id }}">{{ $changeOrder->label() }} {{ $changeOrder->title }}</option>@endforeach
                    </select>
                </x-ui.field>
                <x-ui.field for="material-date" :label="__('Datum')" error="material.work_date" class="sm:col-span-2"><input id="material-date" type="date" wire:model="material.work_date" class="{{ $input }}"></x-ui.field>
                <div class="sm:col-span-6 sm:text-right"><x-button type="submit" wire:loading.attr="disabled">{{ __('Registrera material') }}</x-button></div>
            </form>
            @if($material['source'] === 'STOCK')
                <p class="mb-4 text-sm text-gray-500">{{ __('Material från lagret registreras enklast på plats, där du kan skanna artikeln.') }}</p>
            @endif
        @endif
        @include('work-orders::livewire.partials.entries', ['entries' => $materialEntries, 'kind' => 'material'])
    </x-ui.card>

    @include('work-orders::livewire.partials.change-orders')

    @if($workOrder->price_form === App\Modules\WorkOrders\Domain\PriceForm::Fixed)
        <x-ui.card :title="__('Betalningsplan')" :description="__('A conto-fakturor med egen ROT eller RUT på sin andel av arbetet. Resten tas på slutfakturan.')">
            @if($editingPlan)
                <form wire:submit="savePlan" class="space-y-3">
                    @foreach($plan as $index => $instalment)
                        <div class="grid gap-3 sm:grid-cols-4" wire:key="instalment-{{ $index }}">
                            <x-ui.field :for="'plan-label-'.$index" :label="__('Benämning')" :error="'plan.instalments.'.$index.'.label'"><input id="plan-label-{{ $index }}" wire:model="plan.{{ $index }}.label" class="{{ $input }}"></x-ui.field>
                            <x-ui.field :for="'plan-amount-'.$index" :label="__('Belopp exklusive moms')" :error="'plan.instalments.'.$index.'.amount'"><input id="plan-amount-{{ $index }}" wire:model="plan.{{ $index }}.amount" inputmode="decimal" class="{{ $input }}"></x-ui.field>
                            <x-ui.field :for="'plan-due-'.$index" :label="__('Datum')"><input id="plan-due-{{ $index }}" type="date" wire:model="plan.{{ $index }}.due_on" class="{{ $input }}"></x-ui.field>
                            <x-ui.field :for="'plan-milestone-'.$index" :label="__('Eller delmoment')"><input id="plan-milestone-{{ $index }}" wire:model="plan.{{ $index }}.milestone" class="{{ $input }}"></x-ui.field>
                        </div>
                    @endforeach
                    @error('plan.plan')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                    @if($consumer)
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="planAgreed" class="mt-1 rounded border-gray-300 text-accent-600"> {{ __('Planen är avtalad med kunden. Förskott och a conto kräver en särskild överenskommelse med en konsument.') }}</label>
                    @endif
                    <div class="flex flex-wrap gap-3">
                        <x-secondary-button type="button" wire:click="addInstalment">{{ __('Lägg till delbetalning') }}</x-secondary-button>
                        <x-button type="submit" wire:loading.attr="disabled">{{ __('Spara betalningsplanen') }}</x-button>
                    </div>
                </form>
            @elseif($instalments->isEmpty())
                <x-ui.empty-state :title="__('Ingen betalningsplan')">{{ __('Utan plan faktureras hela det fasta priset på en faktura.') }}</x-ui.empty-state>
            @else
                <ul class="divide-y divide-stone-200">
                    @foreach($instalments as $instalment)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                            <span>{{ $instalment->label }} · {{ $instalment->amount_ore->format() }} {{ __('exkl. moms') }}@if($instalment->due_on) · {{ $instalment->due_on->toDateString() }}@endif @if($instalment->milestone) · {{ $instalment->milestone }}@endif</span>
                            @if($instalment->invoiceLink)
                                <a href="{{ route('invoicing.invoices.show', $instalment->invoiceLink->invoice_id) }}" class="text-sm text-accent-700 hover:underline">{{ __('Fakturerad') }}</a>
                            @elseif($canInvoice)
                                <x-secondary-button type="button" wire:click="invoiceInstalment({{ $instalment->id }})" wire:confirm="{{ __('Skapa ett fakturautkast för delbetalningen?') }}">{{ __('Fakturera') }}</x-secondary-button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($canManage && ! $editingPlan && $instalments->every(fn ($instalment) => $instalment->invoiceLink === null))
                <x-secondary-button type="button" class="mt-3" wire:click="editPlan">{{ $instalments->isEmpty() ? __('Lägg till betalningsplan') : __('Ändra betalningsplanen') }}</x-secondary-button>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :title="__('Fakturor från arbetsordern')">
        @if($links->isEmpty())
            <x-ui.empty-state :title="__('Inga fakturor ännu')">{{ __('Skapa fakturaunderlag när det finns tid och material att fakturera.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($links as $link)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <a href="{{ route('invoicing.invoices.show', $link->invoice_id) }}" class="hover:underline">{{ $link->invoice?->number ? __('Faktura :number', ['number' => $link->invoice->number]) : __('Fakturautkast') }}</a>
                        <span class="text-sm text-gray-500">{{ match ($link->kind) { 'INSTALMENT' => __('A conto'), 'FINAL' => __('Slutfaktura'), default => __('Löpande') } }} · {{ $link->invoice?->total_ore?->format() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
