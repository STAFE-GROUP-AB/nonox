<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title>{{ __('Personalliggare') }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #17171a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; }
        p { margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; border-bottom: 1px solid #17171a; padding: 3px 4px; }
        td { border-bottom: 1px solid #ddd; padding: 3px 4px; vertical-align: top; }
        .muted { color: #555; }
    </style>
</head>
<body>
    <h1>{{ __('Personalliggare') }} – {{ $report->company->legal_name ?: $report->company->name }} ({{ $report->company->org_number }})</h1>
    <p>{{ __('Period: :from – :to', ['from' => $report->from->format('Y-m-d H:i'), 'to' => $report->to->format('Y-m-d H:i')]) }}</p>
    <p class="muted">{{ __('Utskriven :time från NONOX Ekonomisystem.', ['time' => $report->createdAt->format('Y-m-d H:i:s')]) }}</p>
    @foreach($report->locations as $location)
        <p>{{ __('Lokal') }}: {{ $location->name }}, {{ $location->address() }} ({{ $location->business_type->label() }})</p>
    @endforeach

    <h2>{{ __('Incheckade just nu') }} ({{ count($report->present) }})</h2>
    <table>
        <thead><tr><th>{{ __('Namn') }}</th><th>{{ __('Person- eller ID-nummer') }}</th><th>{{ __('Arbetsgivare') }}</th><th>{{ __('Lokal') }}</th><th>{{ __('Incheckad') }}</th></tr></thead>
        <tbody>
            @forelse($report->present as $shift)
                <tr><td>{{ $shift->personName }}</td><td>{{ $shift->identity()->formatted() }}</td><td>{{ $shift->employerLabel() }}</td><td>{{ $report->locationName($shift->locationId) }}</td><td>{{ $shift->start?->format('Y-m-d H:i') }}</td></tr>
            @empty
                <tr><td colspan="5">{{ __('Ingen är incheckad.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('Arbetspass under perioden') }} ({{ count($report->shifts) }})</h2>
    <table>
        <thead><tr><th>{{ __('Namn') }}</th><th>{{ __('Person- eller ID-nummer') }}</th><th>{{ __('Arbetsgivare') }}</th><th>{{ __('Lokal') }}</th><th>{{ __('Början') }}</th><th>{{ __('Slut') }}</th><th>{{ __('Anmärkning') }}</th></tr></thead>
        <tbody>
            @foreach($report->shifts as $shift)
                <tr>
                    <td>{{ $shift->personName }}</td>
                    <td>{{ $shift->identity()->formatted() }}</td>
                    <td>{{ $shift->employerLabel() }}</td>
                    <td>{{ $report->locationName($shift->locationId) }}</td>
                    <td>{{ $shift->start?->format('Y-m-d H:i') ?? __('saknas') }}</td>
                    <td>{{ $shift->end?->format('Y-m-d H:i') ?? __('saknas') }}</td>
                    <td>{{ $shift->corrected() ? __('Rättad eller manuell') : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>{{ __('Alla registreringar, med rättelser') }} ({{ $report->entries->count() }})</h2>
    <table>
        <thead><tr><th>{{ __('Post') }}</th><th>{{ __('Tid') }}</th><th>{{ __('Händelse') }}</th><th>{{ __('Namn') }}</th><th>{{ __('Person- eller ID-nummer') }}</th><th>{{ __('Sätt') }}</th><th>{{ __('Rättelse och orsak') }}</th></tr></thead>
        <tbody>
            @foreach($report->entries as $entry)
                <tr>
                    <td>{{ $entry->id }}</td>
                    <td>{{ $entry->occurred_at->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $entry->type->label() }}{{ $entry->effectiveDirection() ? ' ('.$entry->effectiveDirection()->label().')' : '' }}</td>
                    <td>{{ $entry->person_name }}</td>
                    <td>{{ $entry->formattedPersonalNumber() }}</td>
                    <td>
                        {{ $entry->method->label() }}
                        @foreach($entry->flags as $flag)
                            <br>{{ App\Modules\StaffLedger\Domain\EntryFlag::tryFrom($flag)?->label() ?? $flag }}
                        @endforeach
                    </td>
                    <td>
                        @if($entry->corrects_entry_id)
                            {{ __('Rättar post :id: :kind.', ['id' => $entry->corrects_entry_id, 'kind' => $entry->correction?->label()]) }}
                        @endif
                        {{ $entry->reason }}
                        @if($entry->user) ({{ $entry->user->name }}) @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
