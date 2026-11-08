@php($kr = fn (int $ore): string => App\Support\Money\Money::ofOre($ore)->format())
@php($hours = fn (?int $minutes): string => rtrim(rtrim(number_format(((int) $minutes) / 60, 2, ',', ' '), '0'), ','))
@php($group = fn ($entry): string => $entry->change_order_id === null ? __('Huvudarbete') : ($changeOrders->get($entry->change_order_id)?->label() ?? ''))
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ __('Specificerat underlag') }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2933; }
    h1 { font-size: 15pt; margin: 0 0 2mm; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; }
    .muted { color: #616e7c; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 1mm 1mm; text-align: left; border-bottom: 1px solid #e4e7eb; }
    .right { text-align: right; }
</style>
</head>
<body>
    <p class="muted">{{ $company->legal_name ?: $company->name }}</p>
    <h1>{{ __('Specificerat underlag för arbetsorder :number', ['number' => $workOrder->number]) }}</h1>
    <p class="muted">{{ $workOrder->title }} · {{ __('Kund: :name', ['name' => $customer->name]) }} · {{ $workOrder->price_form->label() }}</p>

    <h2>{{ __('Tid') }}</h2>
    <table>
        <tr><th>{{ __('Datum') }}</th><th>{{ __('Del') }}</th><th>{{ __('Typ av arbete') }}</th><th class="right">{{ __('Antal') }}</th><th class="right">{{ __('Pris') }}</th><th class="right">{{ __('Belopp') }}</th></tr>
        @forelse($time as $entry)
            <tr>
                <td>{{ $entry->work_date->toDateString() }}</td>
                <td>{{ $group($entry) }}</td>
                <td>{{ $entry->labourKind?->name }}</td>
                <td class="right">{{ $entry->minutes !== null ? $hours($entry->minutes).' '.__('tim') : rtrim(rtrim(number_format(((int) $entry->quantity_milli) / 1000, 3, ',', ' '), '0'), ',').' '.$entry->labourKind?->unit->label() }}</td>
                <td class="right">{{ $kr($entry->rate_ore) }}</td>
                <td class="right">{{ $kr($entry->netOre()) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('Ingen tid registrerad.') }}</td></tr>
        @endforelse
    </table>

    <h2>{{ __('Material') }}</h2>
    <table>
        <tr><th>{{ __('Datum') }}</th><th>{{ __('Del') }}</th><th>{{ __('Material') }}</th><th class="right">{{ __('Antal') }}</th><th class="right">{{ __('Pris') }}</th><th class="right">{{ __('Belopp') }}</th></tr>
        @forelse($material as $entry)
            <tr>
                <td>{{ $entry->work_date->toDateString() }}</td>
                <td>{{ $group($entry) }}</td>
                <td>{{ $entry->description }}</td>
                <td class="right">{{ rtrim(rtrim(number_format($entry->quantity_milli / 1000, 3, ',', ' '), '0'), ',') }} {{ $entry->unit }}</td>
                <td class="right">{{ $kr($entry->unit_price_ore) }}</td>
                <td class="right">{{ $kr($entry->netOre()) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('Inget material registrerat.') }}</td></tr>
        @endforelse
    </table>

    @if($changeOrders->isNotEmpty())
        <h2>{{ __('ÄTA') }}</h2>
        <table>
            @foreach($changeOrders as $changeOrder)
                <tr><td>{{ $changeOrder->label() }}</td><td>{{ $changeOrder->title }}</td><td>{{ $changeOrder->status->label() }}</td><td class="right">{{ $kr($changeOrder->netOre()) }}</td></tr>
            @endforeach
        </table>
    @endif
    <p class="muted">{{ __('Belopp exklusive moms.') }}</p>
</body>
</html>
