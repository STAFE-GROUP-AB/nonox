<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title>{{ __('Protokoll') }}</title>
    <style>
        @page { margin: 20mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #17171a; line-height: 1.45; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 11px; margin: 14px 0 4px; }
        p { margin: 0 0 5px; }
        .muted { color: #555; }
        .item { margin-bottom: 8px; }
        .box { border: 1px solid #17171a; padding: 8px; margin-top: 16px; }
    </style>
</head>
<body>
    @php($meetingData = $body['meeting'] ?? [])
    <h1>
        {{ \App\Modules\Boardroom\Domain\MeetingKind::from($meetingData['kind'] ?? $meeting->kind->value)->label() }}
        @if($minutes->number !== null) – {{ __('protokoll nr :number', ['number' => $minutes->number]) }} @endif
    </h1>
    <p>{{ $company->legal_name ?: $company->name }}, {{ $company->org_number }}</p>
    <p>{{ __('Datum') }}: {{ $meetingData['date'] ?? $meeting->meeting_date->toDateString() }}@if(! empty($meetingData['time'])) {{ __('kl.') }} {{ $meetingData['time'] }}@endif</p>
    <p>{{ __('Plats') }}: {{ ($meetingData['place'] ?? null) ?: '–' }}@if(! empty($meetingData['digital'])) ({{ __('digitalt deltagande') }})@endif</p>

    @if($corrects)
        <p><strong>{{ __('Rättar protokollet som godkändes :date. Det tidigare protokollet finns kvar.', ['date' => $corrects->approved_at?->format('Y-m-d H:i')]) }}</strong></p>
    @endif

    @if(! empty($body['attendees']))
        <h2>{{ __('Närvarande') }}</h2>
        @foreach($body['attendees'] as $attendee)
            <p>{{ $attendee['name'] }} ({{ \App\Modules\Boardroom\Models\Meeting::roleLabel($attendee['role']) }})</p>
        @endforeach
    @endif

    @if(! empty($body['voting_list']))
        <h2>{{ __('Röstlängd') }}</h2>
        <table style="width: 100%; border-collapse: collapse;">
            <tr><th style="text-align: left;">{{ __('Namn') }}</th><th style="text-align: left;">{{ __('Roll') }}</th><th style="text-align: right;">{{ __('Aktier') }}</th><th style="text-align: right;">{{ __('Röster') }}</th></tr>
            @foreach($body['voting_list'] as $row)
                <tr><td>{{ $row['name'] }}</td><td>{{ \App\Modules\Boardroom\Models\Meeting::roleLabel($row['role']) }}</td><td style="text-align: right;">{{ number_format($row['shares'], 0, ',', ' ') }}</td><td style="text-align: right;">{{ number_format($row['votes_tenths'] / 10, $row['votes_tenths'] % 10 === 0 ? 0 : 1, ',', ' ') }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('Ärenden') }}</h2>
    @foreach($body['items'] ?? [] as $item)
        <div class="item">
            <p><strong>§ {{ $item['position'] }} {{ $item['title'] }}</strong></p>
            @if(! empty($item['proposal']))<p class="muted">{{ __('Förslag') }}: {{ $item['proposal'] }}</p>@endif
            <p>{{ ($item['decision'] ?? null) ?: '–' }}</p>
            @if(! empty($item['dissent']))<p>{{ __('Reservation') }}: {{ $item['dissent'] }}</p>@endif
        </div>
    @endforeach

    @if(! empty($body['notes']))
        <h2>{{ __('Övrigt') }}</h2>
        <p>{{ $body['notes'] }}</p>
    @endif

    <div class="box">
        <p>{{ __('Ordförande') }}: {{ $body['chair'] ?? '–' }}</p>
        <p>{{ __('Protokollförare') }}: {{ $body['minute_taker'] ?? '–' }}</p>
        <p>{{ __('Justerare') }}: {{ empty($body['adjusters']) ? '–' : implode(', ', $body['adjusters']) }}</p>
        <p>{{ __('Markerat som justerat i NONOX av :name :time.', ['name' => $checker?->name ?? '–', 'time' => $minutes->checked_at?->format('Y-m-d H:i')]) }}</p>
        <p><strong>{{ __('Godkänt i NONOX av :name :time.', ['name' => $approver->name, 'time' => $minutes->approved_at?->format('Y-m-d H:i')]) }}</strong></p>
        <p class="muted">{{ __('Godkännandet i NONOX visar vem som godkände protokollet och när. Det är inte en underskrift. Skriv under protokollet för hand eller med en elektronisk underskrift när lagen kräver det.') }}</p>
    </div>
</body>
</html>
