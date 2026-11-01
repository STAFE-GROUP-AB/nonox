{{-- The OAuth endpoints from the spec's x-oauth. --}}
@php
    $labels = $lang === 'en'
        ? ['authorization_endpoint' => 'Authorization (consent)', 'token_endpoint' => 'Token', 'metadata' => 'Metadata (RFC 8414)']
        : ['authorization_endpoint' => 'Godkännande', 'token_endpoint' => 'Token', 'metadata' => 'Metadata (RFC 8414)'];
@endphp
<div class="dv-table" tabindex="0">
  <table>
    <thead><tr><th scope="col">{{ $lang === 'en' ? 'Endpoint' : 'Adress' }}</th><th scope="col">{{ $lang === 'en' ? 'Method and URL' : 'Metod och adress' }}</th></tr></thead>
    <tbody>
      @foreach($labels as $key => $label)
        @if(isset($spec['oauth'][$key]))
          <tr>
            <th scope="row" style="font-weight:600">{{ $label }}</th>
            <td><span class="dv-method dv-m-{{ strtolower($spec['oauth'][$key]['method']) }}">{{ $spec['oauth'][$key]['method'] }}</span> <code>{{ $spec['oauth'][$key]['url'] }}</code></td>
          </tr>
        @endif
      @endforeach
    </tbody>
  </table>
</div>
