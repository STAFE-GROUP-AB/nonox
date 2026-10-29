<x-app-layout>
    <div class="max-w-5xl mx-auto py-10 px-4 sm:px-6 lg:px-8 space-y-8">
        <x-ui.page-heading :title="__('Verifikationsserier')" :lead="__('Varje verifikation får ett löpnummer i en serie, till exempel A1, A2, A3. Serien visar varifrån verifikationen kommer.')" />
        <x-ui.bookkeeping-nav />

        <x-ui.card>
            <ul class="divide-y divide-gray-100">
                @foreach($series as $item)
                    <li class="flex items-center gap-4 py-3">
                        <span class="flex size-9 flex-none items-center justify-center rounded-lg bg-gray-100 font-semibold text-gray-900">{{ $item->code }}</span>
                        <span class="text-gray-900">{{ __($item->name) }}</span>
                        @if($item->is_default)
                            <x-ui.badge tone="accent">{{ __('Används för manuella verifikationer') }}</x-ui.badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <p class="text-sm text-gray-500">{{ __('Numreringen är obruten inom varje serie och räkenskapsår, som bokföringslagen kräver. Numret sätts när verifikationen bokförs.') }}</p>
    </div>
</x-app-layout>
