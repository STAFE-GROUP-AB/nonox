@php($input = 'mt-1 block w-full border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-xs')
<div class="space-y-6">
    <x-ui.card>
        @if($canCreate || $canConvert)
            <x-slot:cta>
                <div class="flex flex-wrap gap-3">
                    @if($canConvert)
                        <a href="{{ route('inventory.requisitions.convert') }}" class="inline-flex items-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-stone-50">{{ $toOrder > 0 ? __('Beställ (:count rader väntar)', ['count' => $toOrder]) : __('Beställ') }}</a>
                    @endif
                    @if($canCreate)
                        <a href="{{ route('inventory.requisitions.create') }}" class="inline-flex items-center rounded-lg bg-accent-600 px-4 py-2 text-sm font-semibold text-white hover:bg-accent-700">{{ __('Ny inköpsanmodan') }}</a>
                    @endif
                </div>
            </x-slot:cta>
        @endif

        <div class="mb-4 grid max-w-2xl gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">{{ __('Visa') }}</span>
                <select wire:model.live="status" class="{{ $input }}">
                    <option value="oppna">{{ __('Öppna') }}</option>
                    <option value="vantar">{{ __('Väntar på attest') }}</option>
                    <option value="attesterade">{{ __('Attesterade, ej beställda') }}</option>
                    <option value="alla">{{ __('Alla') }}</option>
                </select>
            </label>
            @if($seesAll)
                <label class="flex min-h-11 items-end gap-2 pb-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model.live="mine" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                    {{ __('Bara mina') }}
                </label>
            @endif
        </div>

        @if($requisitions->isEmpty())
            <x-ui.empty-state :title="__('Inga inköpsanmodan')">{{ __('Skriv en inköpsanmodan när du behöver något som företaget ska köpa. Den går till attest, och när den är attesterad lägger en inköpare den på en inköpsorder.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-gray-600">
                            <th class="py-2 pr-4 font-medium">{{ __('Nummer') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Skriven av') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Behövs senast') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Rader') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Beräknat belopp exkl. moms') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($requisitions as $requisition)
                            <tr wire:key="pr-{{ $requisition->id }}">
                                <td class="py-2 pr-4 tabular-nums"><a href="{{ route('inventory.requisitions.show', $requisition->id) }}" class="font-semibold text-accent-700 underline">{{ $requisition->number }}</a></td>
                                <td class="py-2 pr-4">{{ $requisition->requester?->name }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :tone="$requisition->status->tone()">{{ $requisition->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4 tabular-nums">{{ $requisition->needed_by?->toDateString() ?? '–' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $requisition->rows->count() }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ App\Support\Money\Money::ofOre($requisition->estimatedOre())->format() }}@if($requisition->hasUnpricedRows()) <span class="text-gray-500" title="{{ __('Minst en rad saknar pris.') }}">*</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-sm text-gray-500">{{ __('* Minst en rad saknar pris, så beloppet är lägre än det verkliga.') }}</p>
            <div class="mt-4">{{ $requisitions->links() }}</div>
        @endif
    </x-ui.card>
</div>
