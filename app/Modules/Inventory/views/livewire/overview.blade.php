@php($q = fn (?int $milli): string => App\Modules\Inventory\Domain\Quantity::format((int) $milli))
<div class="space-y-6">
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Nyckeltal') }}">
        @if($value !== null)
            <a href="{{ route('inventory.reports') }}" class="block rounded-3xl bg-ink px-5 py-4 text-white shadow-xs transition hover:bg-black">
                <span class="block text-sm text-stone-300">{{ __('Lagervärde i dag') }}</span>
                <x-ui.amount :money="App\Support\Money\Money::ofOre($value->valueOre)" :on-dark="true" class="mt-1 block text-2xl font-bold tracking-tight text-white" />
                <span class="mt-2 inline-flex rounded-full bg-white/10 px-2.5 py-1 text-xs text-stone-200">{{ $value->method->label() }}</span>
            </a>
        @endif
        <a href="{{ route('inventory.articles') }}" class="block rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200 transition hover:ring-stone-300">
            <span class="block text-sm text-gray-500">{{ __('Lagerförda artiklar') }}</span>
            <span class="mt-1 block text-2xl font-bold tracking-tight text-ink tabular-nums">{{ $stocked }}</span>
        </a>
        <a href="{{ route('inventory.articles', ['visa' => 'lagt']) }}" @class(['block rounded-3xl px-5 py-4 shadow-xs transition', 'bg-accent-50 ring-1 ring-accent-200 hover:ring-accent-300' => $lowCount > 0, 'bg-white ring-1 ring-stone-200 hover:ring-stone-300' => $lowCount === 0])>
            <span class="block text-sm text-gray-500">{{ __('Under beställningspunkten') }}</span>
            <span class="mt-1 block text-2xl font-bold tracking-tight text-ink tabular-nums">{{ $lowCount }}</span>
        </a>
        <a href="{{ route('inventory.pickLists') }}" class="block rounded-3xl bg-white px-5 py-4 shadow-xs ring-1 ring-stone-200 transition hover:ring-stone-300">
            <span class="block text-sm text-gray-500">{{ __('Att plocka och ta emot') }}</span>
            <span class="mt-1 block text-2xl font-bold tracking-tight text-ink tabular-nums">{{ $openPickLists }} / {{ $openReceipts }}</span>
            <span class="mt-2 inline-flex text-xs text-gray-500">{{ __('plocklistor / inleveranser') }}</span>
        </a>
    </section>

    <x-ui.card :title="__('Behöver beställas')" :description="__('Artiklar där saldot har nått beställningspunkten.')">
        @if($low->isEmpty())
            <x-ui.empty-state :title="__('Inget behöver beställas')">{{ __('Ange en beställningspunkt på artikeln under Avancerat, så syns den här när saldot når dit.') }}</x-ui.empty-state>
        @else
            <ul class="divide-y divide-stone-200">
                @foreach($low as $article)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm" wire:key="low-{{ $article->id }}">
                        <span><span class="font-semibold tabular-nums">{{ $article->number }}</span> {{ $article->name }}</span>
                        <span class="tabular-nums text-accent-700">{{ $q((int) $article->stock_milli) }} {{ $article->unit }} · {{ __('beställ :quantity', ['quantity' => $q($article->reorder_quantity_milli)]) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.card :title="__('Senaste rörelserna')">
        @if($movements->isEmpty())
            <x-ui.empty-state :title="__('Inga rörelser ännu')">{{ __('Lägg upp ett lagerställe och ta emot varor med en inleverans. Varje inleverans, plock, flytt och inventering syns här.') }}</x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th class="py-2 pr-4 font-medium">{{ __('Datum') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Typ') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Artikel') }}</th>
                            <th class="py-2 pr-4 font-medium">{{ __('Plats') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('Antal') }}</th>
                            <th class="py-2 font-medium">{{ __('Av') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($movements as $movement)
                            <tr wire:key="movement-{{ $movement->id }}">
                                <td class="py-2 pr-4 tabular-nums whitespace-nowrap">{{ $movement->occurred_on->toDateString() }}</td>
                                <td class="py-2 pr-4">{{ $movement->type->label() }}</td>
                                <td class="py-2 pr-4"><span class="tabular-nums">{{ $movement->article?->number }}</span> {{ $movement->article?->name }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $movement->location?->full_code }}</td>
                                <td @class(['py-2 pr-4 text-right tabular-nums whitespace-nowrap', 'text-accent-700' => $movement->quantity_milli < 0])>{{ $movement->quantity_milli > 0 ? '+' : '' }}{{ $q($movement->quantity_milli) }} {{ $movement->article?->unit }}</td>
                                <td class="py-2 text-gray-600">{{ $movement->creator?->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
