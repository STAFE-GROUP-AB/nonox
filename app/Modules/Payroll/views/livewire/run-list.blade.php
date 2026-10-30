<div class="space-y-6">
    @if($canCreate)
        <x-ui.card :title="__('Ny lönekörning')">
            <form wire:submit="create" class="flex flex-wrap items-end gap-4">
                <x-ui.field for="run_period" :label="__('Månad')" error="period">
                    <input id="run_period" type="month" class="block border-gray-300 focus:border-accent-500 focus:ring-accent-500 rounded-lg shadow-sm mt-1" wire:model="period">
                </x-ui.field>
                <x-button wire:loading.attr="disabled">{{ __('Skapa lönekörning') }}</x-button>
            </form>
            <p class="mt-2 text-sm text-gray-600">{{ __('Utbetalningsdagen tas från löneinställningarna och flyttas till fredag om den infaller en helg. Du kan beräkna om tills lönekörningen är attesterad.') }}</p>
        </x-ui.card>
    @endif

    <x-ui.card>
        @if($runs->isEmpty())
            <x-ui.empty-state :title="__('Inga lönekörningar ännu')">{{ __('Skapa den första lönekörningen när de anställda och reglerna är på plats.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($runs as $run)
                    <li class="flex flex-wrap items-center gap-4 py-3">
                        <a href="{{ route('payroll.runs.show', $run) }}" class="min-w-0 flex-1 font-medium text-accent-600 hover:text-accent-700">{{ $run->title() }}</a>
                        <span class="tabular-nums text-gray-600">{{ __('Utbetalning :date', ['date' => $run->payment_date->toDateString()]) }}</span>
                        <span class="tabular-nums text-gray-600">{{ $run->payslips_count === 1 ? __('1 lönebesked') : __(':count lönebesked', ['count' => $run->payslips_count]) }}</span>
                        <x-ui.amount :money="App\Support\Money\Money::ofOre((int) $run->payslips_sum_net_ore)" />
                        <x-ui.badge :tone="$run->isOpen() ? 'neutral' : 'success'">{{ $run->statusLabel() }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
