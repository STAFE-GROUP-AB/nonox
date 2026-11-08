@php
    use App\Domain\Mileage\Distance;
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.4pt; line-height: 1.35; color: #1f2933; margin: 14mm; }
    h1 { font-size: 15pt; margin: 0 0 1mm; }
    .muted { color: #616e7c; }
    table { width: 100%; border-collapse: collapse; margin-top: 5mm; }
    th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #616e7c; border-bottom: 0.3mm solid #9aa5b1; padding: 1.2mm 1mm; }
    td { border-bottom: 0.2mm solid #e4e7eb; padding: 1.2mm 1mm; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    tfoot td { font-weight: bold; border-bottom: none; border-top: 0.3mm solid #9aa5b1; }
</style>
</head>
<body>
    <p class="muted">{{ $company->name }} · {{ $company->org_number }}</p>
    <h1>{{ $title }}</h1>
    <p class="muted">{{ __('Förare: :name', ['name' => $driver]) }}</p>
    <table>
        <thead>
            <tr>
                <th>{{ __('Datum') }}</th>
                <th>{{ __('Bil') }}</th>
                <th>{{ __('Typ') }}</th>
                <th>{{ __('Från – till') }}</th>
                <th>{{ __('Ärende') }}</th>
                <th>{{ __('Mätare') }}</th>
                <th class="num">{{ __('Sträcka') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($trips as $trip)
                <tr>
                    <td>{{ $trip->trip_date->toDateString() }}</td>
                    <td>{{ $trip->vehicle->displayName() }}</td>
                    <td>{{ $trip->kind->label() }}</td>
                    <td>{{ $trip->route() }}</td>
                    <td>{{ $trip->purpose }}@if($trip->corrects_trip_id) ({{ __('rättelse') }}: {{ $trip->correction_reason }})@endif</td>
                    <td>@if($trip->odometer_start_km !== null){{ $trip->odometer_start_km }}–{{ $trip->odometer_end_km }}@endif</td>
                    <td class="num">{{ Distance::formatKm($trip->distance_hm) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6">{{ __('Summa') }}</td>
                <td class="num">{{ Distance::formatKm($totalHm) }} ({{ Distance::formatMil($totalHm) }})</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
