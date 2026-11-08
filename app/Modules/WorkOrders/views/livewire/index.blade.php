@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6">
    <x-ui.card>
        @if($canManage)
            <x-slot:cta>
                <a href="{{ route('workOrders.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Ny arbetsorder') }}</a>
            </x-slot:cta>
        @endif

        <div class="mb-4 flex flex-wrap items-end gap-4">
            <label class="block grow sm:max-w-xs">
                <span class="text-sm font-medium text-gray-700">{{ __('Sök nummer, rubrik eller kund') }}</span>
                <input type="search" wire:model.live.debounce.300ms="search" class="{{ $input }}">
            </label>
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="oppna">{{ __('Öppna') }}</option>
                    <option value="alla">{{ __('Alla') }}</option>
                    @foreach($statuses as $each)
                        <option value="{{ $each->value }}">{{ $each->label() }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if($workOrders->isEmpty())
            <x-ui.empty-state :title="$search === '' ? __('Inga arbetsorder ännu') : __('Ingen arbetsorder matchar sökningen')">
                {{ $canManage ? __('Skapa en arbetsorder för ett jobb hos en kund. Tid och material registreras på den, och den blir fakturaunderlaget.') : __('Arbetsorder som du är tilldelad syns här.') }}
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200" aria-label="{{ __('Arbetsorder') }}">
                @foreach($workOrders as $workOrder)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <a href="{{ route('workOrders.show', $workOrder->id) }}" class="font-medium text-gray-900 hover:underline">{{ $workOrder->number }} · {{ $workOrder->title }}</a>
                            <p class="text-sm text-gray-500">{{ $workOrder->customer?->name }} · {{ $workOrder->price_form->label() }}@if($workOrder->tax_reduction) · {{ $workOrder->tax_reduction }}@endif @if($workOrder->reverse_charge) · {{ __('Omvänd byggmoms') }}@endif</p>
                            @if(($deadlines[$workOrder->id] ?? null) !== null)
                                <p class="mt-1 text-sm font-medium text-amber-800" role="status">{{ __('Ofakturerat arbete måste faktureras senast :date.', ['date' => $deadlines[$workOrder->id]->toDateString()]) }}</p>
                            @endif
                        </div>
                        <x-ui.badge>{{ $workOrder->status->label() }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $workOrders->links() }}</div>
        @endif
    </x-ui.card>
</div>
