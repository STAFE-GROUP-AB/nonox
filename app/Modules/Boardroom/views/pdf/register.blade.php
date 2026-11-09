<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title>{{ __('Aktiebok') }}</title>
    <style>
        @page { margin: 22mm 14mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #17171a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p { margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; border-bottom: 1px solid #17171a; padding: 3px 4px; }
        td { border-bottom: 1px solid #ddd; padding: 3px 4px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #555; }
        .label { position: fixed; top: -14mm; left: 0; right: 0; font-size: 8px; font-weight: bold; color: #7a2e0e; }
        .notice { border: 1px solid #7a2e0e; padding: 6px; margin: 6px 0; color: #7a2e0e; }
    </style>
</head>
<body>
    <div class="label">
        @if($masked)
            {{ __('Frivillig kopia utan personnummer – inte aktieboken för granskning') }}
        @else
            {{ __('Aktiebok – fullständig version för granskning hos bolaget') }}
        @endif
    </div>

    <h1>{{ __('Aktiebok för :name', ['name' => $company->legal_name ?: $company->name]) }}</h1>
    <p>{{ __('Organisationsnummer') }}: {{ $company->org_number }}</p>
    <p>{{ __('Aktieboken per :date', ['date' => $date->toDateString()]) }}</p>

    @if($masked)
        <div class="notice">{{ __('Det här är en frivillig kopia där personnummer har ersatts med födelsedatum. Den uppfyller inte skyldigheten att hålla aktieboken tillgänglig hos bolaget för alla som vill ta del av den (aktiebolagslagen 5 kap. 10 §). Den fullständiga aktieboken visas hos bolaget.') }}</div>
    @else
        <p class="muted">{{ __('Aktieboken hålls tillgänglig hos bolaget för alla som vill ta del av den (aktiebolagslagen 5 kap. 10 §).') }}</p>
    @endif

    @if($articles !== null && $articles->clauses() !== [])
        <p><strong>{{ __('Förbehåll enligt bolagsordningen, för alla aktier') }}:</strong> {{ implode(', ', $articles->clauses()) }}.</p>
    @endif

    <table>
        <thead>
            <tr>
                <th>{{ __('Aktienummer') }}</th>
                <th class="num">{{ __('Antal') }}</th>
                @if($classes->count() > 1)<th>{{ __('Aktieslag') }}</th>@endif
                <th>{{ __('Ägare') }}</th>
                <th>{{ $masked ? __('Födelsedatum eller org.nr') : __('Person-, org.- eller id-nummer') }}</th>
                <th>{{ __('Postadress') }}</th>
                <th>{{ __('Aktiebrev') }}</th>
                <th>{{ __('Anteckningar') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($state->rows() as $row)
                @php($snapshot = $state->snapshot($row->holderId))
                <tr>
                    <td class="num">{{ $row->from }}–{{ $row->to }}</td>
                    <td class="num">{{ number_format($row->count(), 0, ',', ' ') }}</td>
                    @if($classes->count() > 1)<td>{{ $classes->get($row->classId)?->code }}</td>@endif
                    <td>{{ $snapshot['name'] ?? '' }}</td>
                    <td>{{ \App\Modules\Boardroom\Models\Shareholder::identity($snapshot, $masked) }}</td>
                    <td>{{ \App\Modules\Boardroom\Models\Shareholder::address($snapshot) }}</td>
                    <td>{{ $row->certificate ? __('Utfärdat') : __('Nej') }}</td>
                    <td>
                        @if($row->pendingIssueId !== null)<p>{{ __('Nyemission, ej registrerad') }}</p>@endif
                        @foreach($row->notes as $note)
                            <p>{{ \App\Modules\Boardroom\Domain\ShareEventKind::from($note['kind'])->label() }} {{ $note['date'] }}: {{ $note['text'] }}</p>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">{{ __('Aktieboken har inga aktier på det här datumet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <p style="margin-top: 10px;">{{ __('Antal aktier') }}: {{ number_format($state->totalShares(), 0, ',', ' ') }}.
        {{ __('Registrerat aktiekapital') }}: {{ \App\Support\Money\Money::ofOre($state->capitalOre)->format() }}.
        @if($state->quotaValueOre() !== null)
            {{ __('Kvotvärde') }}: {{ $state->quotaIsExact() ? '' : __('cirka') }} {{ \App\Support\Money\Money::ofOre((int) $state->quotaValueOre())->format() }}.
        @endif
    </p>
    @if($classes->count() > 0)
        <p>
            @foreach($classes as $class)
                {{ $class->code }} ({{ $class->name }}): {{ __(':votes röst per aktie', ['votes' => $class->votesLabel()]) }}@if($class->restriction_text). {{ $class->restriction_text }}@endif@if(! $loop->last); @endif
            @endforeach
        </p>
    @endif
    <p class="muted">{{ __('Aktieboken förs i NONOX med automatiserad behandling. Varje ändring är en egen händelse med datum som inte kan ändras i efterhand. Utskriven :time av :user.', ['time' => $createdAt->format('Y-m-d H:i'), 'user' => $user->name]) }}</p>
</body>
</html>
