{{-- The addresses of the installation that serves this page, for self-hosters. --}}
@php
    $base = rtrim(url('/'), '/');
    $en = $lang === 'en';
@endphp
<div class="dv-table" tabindex="0">
  <table>
    <thead><tr><th scope="col">{{ $en ? 'On this installation' : 'På den här installationen' }}</th><th scope="col">URL</th></tr></thead>
    <tbody>
      <tr><th scope="row" style="font-weight:600">API</th><td><code>{{ $base }}/api/v1</code></td></tr>
      <tr><th scope="row" style="font-weight:600">{{ $en ? 'Authorization (consent)' : 'Godkännande' }}</th><td><code>{{ $base }}/anslut</code></td></tr>
      <tr><th scope="row" style="font-weight:600">Token</th><td><code>{{ $base }}/oauth/token</code></td></tr>
      <tr><th scope="row" style="font-weight:600">Metadata</th><td><code>{{ $base }}/.well-known/oauth-authorization-server</code></td></tr>
      <tr><th scope="row" style="font-weight:600">OpenAPI</th><td><code>{{ route('developers.spec', ['format' => 'yaml']) }}</code></td></tr>
    </tbody>
  </table>
</div>
