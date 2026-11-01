{{-- This installation's rate limits and token lifetimes, read from config/services.php (NONOX_CONNECT_*). --}}
@use('App\Support\Developer\DeveloperPortal', 'Dev')
@php
    $connect = (array) config('services.connect');
    $en = $lang === 'en';
    $perMinute = $en ? ' calls/minute' : ' anrop/minut';
    $rows = [
        [$en ? 'Per app or API key' : 'Per app eller API-nyckel', ($connect['rate_limit_per_credential'] ?? 120).$perMinute, 'NONOX_CONNECT_RATE_LIMIT_PER_CREDENTIAL'],
        [$en ? 'Per company (all apps and keys together)' : 'Per företag (alla appar och nycklar tillsammans)', ($connect['rate_limit_per_company'] ?? 600).$perMinute, 'NONOX_CONNECT_RATE_LIMIT_PER_COMPANY'],
        [$en ? 'Per IP address, before authentication' : 'Per IP-adress, före inloggning', ($connect['rate_limit_per_ip'] ?? 600).$perMinute, 'NONOX_CONNECT_RATE_LIMIT_PER_IP'],
        [$en ? 'Token endpoint per IP address' : 'Tokenadressen per IP-adress', ($connect['rate_limit_token_endpoint'] ?? 30).$perMinute, 'NONOX_CONNECT_RATE_LIMIT_TOKEN_ENDPOINT'],
        [$en ? 'Access token lifetime' : 'Access tokens giltighet', max(1, min(15, (int) ($connect['access_token_minutes'] ?? 15))).($en ? ' minutes' : ' minuter'), 'NONOX_CONNECT_ACCESS_TOKEN_MINUTES'],
        [$en ? 'Refresh token lifetime' : 'Refresh tokens giltighet', ($connect['refresh_token_days'] ?? 30).($en ? ' days' : ' dagar'), 'NONOX_CONNECT_REFRESH_TOKEN_DAYS'],
        [$en ? 'Longest API key validity' : 'Längsta giltighet för en API-nyckel', ($connect['max_key_days'] ?? 365).($en ? ' days' : ' dagar'), 'NONOX_CONNECT_MAX_KEY_DAYS'],
        [$en ? 'Idempotency keys are kept' : 'Idempotensnycklar sparas', ($connect['idempotency_hours'] ?? 24).($en ? ' hours' : ' timmar'), null],
    ];
@endphp
<div class="dv-table" tabindex="0">
  <table>
    <thead><tr><th scope="col">{{ Dev::t('limit', $lang) }}</th><th scope="col">{{ Dev::t('value', $lang) }}</th><th scope="col">{{ Dev::t('setting', $lang) }}</th></tr></thead>
    <tbody>
      @foreach($rows as [$label, $value, $setting])
        <tr><th scope="row" style="font-weight:600">{{ $label }}</th><td style="white-space:nowrap;font-variant-numeric:tabular-nums">{{ $value }}</td><td>@if($setting)<code>{{ $setting }}</code>@else – @endif</td></tr>
      @endforeach
    </tbody>
  </table>
</div>
