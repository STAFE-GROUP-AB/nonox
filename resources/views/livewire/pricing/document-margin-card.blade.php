<div>
    @if($margin !== null)
        <x-ui.card :title="__('Täckningsbidrag')" :description="__('Internt. Visas inte för kunden och inte på PDF:en.')">
            <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4" data-margin-card>
                <div><dt class="text-gray-500">{{ __('Försäljning (rader med känd kostnad)') }}</dt><dd class="font-medium tabular-nums">{{ App\Support\Money\Money::ofOre($margin->netOre)->format() }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Kostnad') }}</dt><dd class="font-medium tabular-nums">{{ App\Support\Money\Money::ofOre($margin->costOre)->format() }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Täckningsbidrag') }}</dt><dd class="font-medium tabular-nums">{{ App\Support\Money\Money::ofOre($margin->contributionOre())->format() }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Täckningsgrad') }}</dt><dd class="font-medium tabular-nums">{{ App\Domain\Pricing\PriceFloor::percent((int) $margin->marginBp()) }}</dd></div>
            </dl>
            @if($margin->uncosted > 0)
                <p class="mt-3 text-sm text-gray-600">{{ __(':count rader saknar känd kostnad och räknas inte med.', ['count' => $margin->uncosted]) }}</p>
            @endif
            @if($floor?->isOn() && $margin->rows !== [] && collect($margin->rows)->contains(fn ($row) => $row->belowFloor))
                <p class="mt-3 text-sm text-amber-900">{{ __('Någon rad ligger under företagets lägsta täckningsgrad (:floor).', ['floor' => $floor->label()]) }}</p>
            @endif
        </x-ui.card>
    @endif
</div>
