@extends('portal::portal.layout', ['title' => __('Kontoutdrag')])

@php
    $key = app(App\Modules\Portal\Support\PortalContext::class)->key();
    $kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format();
    $date = $statement['date']->toDateString();
@endphp

@section('content')
    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Kontoutdrag') }}</h1>
    <p class="mt-2 text-gray-700">{{ __('Öppna poster den :date, räknade från fakturorna och betalningarna som har kommit in. Kontoutdraget är en sammanställning och inte ett dokument som företaget har utfärdat.', ['date' => $date]) }}</p>

    <form method="GET" action="{{ route('portal.statement', ['portalKey' => $key]) }}" class="mt-4 flex flex-wrap items-end gap-3">
        <div>
            <label for="datum" class="block text-sm font-medium">{{ __('Datum') }}</label>
            <input id="datum" name="datum" type="date" value="{{ $date }}" max="{{ now()->toDateString() }}" class="mt-1 rounded-lg border-gray-300" @error('datum') aria-invalid="true" aria-describedby="datum-error" @enderror>
        </div>
        <button type="submit" class="rounded-lg bg-white px-4 py-2 font-semibold ring-1 ring-stone-300">{{ __('Visa') }}</button>
        <a href="{{ route('portal.statement.pdf', ['portalKey' => $key, 'datum' => $date]) }}" class="rounded-lg bg-gray-900 px-4 py-2 font-semibold text-white">{{ __('Ladda ner PDF') }}</a>
        @error('datum')<p id="datum-error" class="w-full text-sm text-red-700">{{ $message }}</p>@enderror
    </form>

    @if($statement['rows'] === [])
        <p class="mt-6 rounded-2xl bg-white p-6 ring-1 ring-stone-200">{{ __('Inga öppna poster den dagen. Allt var betalt.') }}</p>
    @else
        <div class="mt-6 overflow-x-auto rounded-2xl bg-white ring-1 ring-stone-200">
            <table class="w-full min-w-[40rem] text-sm">
                <caption class="sr-only">{{ __('Öppna poster') }}</caption>
                <thead>
                    <tr class="border-b border-stone-200 text-left text-gray-600">
                        <th scope="col" class="px-4 py-3 font-normal">{{ __('Faktura') }}</th>
                        <th scope="col" class="px-4 py-3 font-normal">{{ __('Förfallodag') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-normal">{{ __('Belopp') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-normal">{{ __('Betalt och krediterat') }}</th>
                        <th scope="col" class="px-4 py-3 text-right font-normal">{{ __('Öppet') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($statement['rows'] as $row)
                        <tr class="border-b border-stone-100">
                            <td class="px-4 py-3">{{ $row['invoice']->title() }}</td>
                            <td class="px-4 py-3 tabular-nums {{ $row['overdue'] ? 'text-red-700' : '' }}">{{ $row['invoice']->due_date->toDateString() }}@if($row['overdue']) <span class="sr-only">{{ __('förfallen') }}</span>@endif</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $kr($row['total']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $kr($row['paid'] + $row['credited']) }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums">{{ $kr($row['open']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><th scope="row" colspan="4" class="px-4 py-3 text-right">{{ __('Summa öppet') }}</th><td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $kr($statement['total']) }}</td></tr>
                    <tr><th scope="row" colspan="4" class="px-4 py-3 text-right font-normal text-gray-600">{{ __('Varav förfallet') }}</th><td class="px-4 py-3 text-right tabular-nums">{{ $kr($statement['overdue']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    @endif
@endsection
