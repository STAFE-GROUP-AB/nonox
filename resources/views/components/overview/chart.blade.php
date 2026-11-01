{{--
    One chart on Översikt → Statistik och prognos: a title, one sentence that
    says what the chart shows (also its accessible name), the chart, and the
    same figures as a table behind a toggle (ADR-027). The slot is the table.
--}}
@props(['kind', 'title', 'summary', 'data', 'labels', 'note' => null])

<x-ui.card :title="$title" {{ $attributes->class('min-w-0') }} x-data="{ table: false }" data-chart="{{ $kind }}">
    <x-slot:action>
        <button type="button" x-on:click="table = ! table" x-bind:aria-expanded="table ? 'true' : 'false'" aria-controls="chart-table-{{ $kind }}"
                class="rounded-sm text-sm font-semibold text-accent-600 hover:text-accent-700 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-accent-500">
            <span x-show="! table">{{ __('Visa som tabell') }}</span>
            <span x-show="table" x-cloak>{{ __('Visa som diagram') }}</span>
        </button>
    </x-slot:action>

    <p class="-mt-3 mb-4 text-sm text-gray-600" id="chart-summary-{{ $kind }}">{{ $summary }}</p>

    <div x-show="! table" class="relative h-64 sm:h-72" x-data="overviewChart(@js($kind), @js($data), @js($labels))" wire:key="chart-{{ $kind }}-{{ md5(json_encode($data)) }}">
        <canvas x-ref="canvas" role="img" aria-labelledby="chart-summary-{{ $kind }}">{{ $summary }}</canvas>
    </div>

    <div x-show="table" x-cloak id="chart-table-{{ $kind }}" class="-mx-2 overflow-x-auto">
        {{ $slot }}
    </div>

    @if($note)
        <div class="mt-4 rounded-2xl bg-paper px-4 py-3 text-sm text-gray-700">{{ $note }}</div>
    @endif
</x-ui.card>
