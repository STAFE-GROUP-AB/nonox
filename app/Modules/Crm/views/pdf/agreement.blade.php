@php
    $account = $agreement->account;
    $deadline = $agreement->noticeDeadline();
@endphp
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<title>{{ $agreement->title }}</title>
<style>
    @page { margin: 18mm 16mm 22mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2933; }
    h1 { font-size: 18pt; margin: 0 0 2mm; letter-spacing: -0.3pt; }
    h2 { font-size: 11pt; margin: 8mm 0 2mm; }
    .muted { color: #616e7c; }
    .right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .meta td { padding: 1.2mm 0; border-bottom: 1px solid #e4e7eb; }
    .box { border: 1px solid #cbd2d9; border-radius: 2mm; padding: 4mm; margin-top: 8mm; }
    @include('invoicing.partials.brand-style')
</style>
</head>
<body>
    <div class="brand-bar"></div>
    <table class="head">
        <tr>
            <td style="width: 55%">
                @include('invoicing.partials.brand-sender')
            </td>
            <td>
                <h1>{{ __('Avtal') }}</h1>
                <span class="muted">{{ $agreement->kind->label() }}</span>
            </td>
        </tr>
    </table>

    <h2>{{ $agreement->title }}</h2>

    <table class="meta">
        <tr><td class="muted" style="width: 40%">{{ __('Parter') }}</td><td>{{ $company->legal_name ?: $company->name }}@if($company->org_number) ({{ $company->org_number }})@endif {{ __('och') }} {{ $account?->name }}@if($account?->org_number) ({{ $account->org_number }})@endif</td></tr>
        <tr><td class="muted">{{ __('Startdatum') }}</td><td>{{ $agreement->start_date->toDateString() }}</td></tr>
        <tr><td class="muted">{{ __('Slutdatum') }}</td><td>{{ $agreement->end_date?->toDateString() ?? __('Tills vidare') }}</td></tr>
        <tr><td class="muted">{{ __('Uppsägningstid') }}</td><td>{{ $agreement->notice_months === 0 ? __('Ingen') : ($agreement->notice_months === 1 ? __('1 månad') : __(':count månader', ['count' => $agreement->notice_months])) }}@if($deadline) · {{ __('senast :date för innevarande period', ['date' => $deadline->toDateString()]) }}@endif</td></tr>
        <tr><td class="muted">{{ __('Förlängning') }}</td><td>{{ $agreement->auto_renew && $agreement->renewal_months ? __('Förlängs automatiskt med :months månader i taget om det inte sägs upp', ['months' => $agreement->renewal_months]) : __('Förlängs inte automatiskt') }}</td></tr>
        @if($agreement->value_ore > 0)
            <tr><td class="muted">{{ __('Värde') }}</td><td>{{ $value->format() }} {{ $agreement->value_period->label() }} {{ __('exklusive moms') }}</td></tr>
        @endif
    </table>

    @if($agreement->body_text)
        <h2>{{ __('Villkor') }}</h2>
        <p>{!! nl2br(e($agreement->body_text)) !!}</p>
    @endif

    <div class="box">
        <span class="muted">{{ __('Avtalet signeras elektroniskt. Ett signeringsbevis med dokumentets kontrollsumma (SHA-256) hör till avtalet.') }}</span>
    </div>
</body>
</html>
