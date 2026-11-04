{{-- The reference overview (/utvecklare/referens): every tag and operation, generated from docs/api/openapi.yaml. --}}
@extends('developers.layout')
@use('App\Support\Developer\DeveloperPortal', 'Dev')

@section('content')
<div class="dv-wide">
  <p class="dv-eyebrow">{{ $spec['info']['title'] }} · v{{ $spec['info']['version'] }} · OpenAPI 3.1</p>
  <h1>{{ Dev::t('reference', $lang) }}</h1>
  <div class="dv-prose" style="max-width:820px">
    @if($lang === 'en')
      {!! Illuminate\Support\Str::markdown($spec['info']['description']['en'], ['html_input' => 'escape']) !!}
    @else
      <p class="dv-lead">{{ $spec['info']['description']['sv'] }}</p>
    @endif
    <p>{{ $lang === 'en' ? 'This reference is generated from' : 'Referensen genereras från' }} <a href="{{ route('developers.spec', ['format' => 'yaml']) }}">openapi.yaml</a> ({{ $lang === 'en' ? 'also as' : 'också som' }} <a href="{{ route('developers.spec', ['format' => 'json']) }}">openapi.json</a>), {{ $lang === 'en' ? 'the same file the tests check every route against. Load it into Postman, Insomnia or a client generator.' : 'samma fil som testerna kontrollerar varje adress mot. Läs in den i Postman, Insomnia eller en kodgenerator.' }}</p>
  </div>

  <div class="dv-endpoint" style="max-width:640px;margin-top:22px">
    <span class="dv-type">{{ $lang === 'en' ? 'Base URL' : 'Basadress' }}</span><code>{{ $spec['server'] }}</code>
  </div>

  @foreach($spec['tags'] as $tag)
    <section aria-labelledby="tag-{{ $tag['slug'] }}" style="margin-top:48px">
      <h2 class="dv-h2" id="tag-{{ $tag['slug'] }}"><a href="{{ Dev::url('developers.tag', ['tag' => $tag['slug']], $lang) }}" style="color:inherit;text-decoration:none">{{ $lang === 'en' ? $tag['name_en'] : $tag['name'] }}</a></h2>
      <p style="color:var(--muted);margin:0 0 6px">{{ $tag['description'][$lang] }}</p>
      <div class="dv-table" tabindex="0">
        <table>
          <thead><tr><th scope="col">{{ Dev::t('endpoints', $lang) }}</th><th scope="col">{{ $lang === 'en' ? 'Does' : 'Gör' }}</th><th scope="col">{{ Dev::t('scopes', $lang) }}</th></tr></thead>
          <tbody>
            @foreach($tag['operations'] as $id)
              @php $operation = $spec['operations'][$id]; @endphp
              <tr>
                <td><a href="{{ Dev::operationUrl($id, $lang) }}" style="text-decoration:none;color:inherit;display:inline-flex;gap:8px;align-items:baseline"><span class="dv-method dv-m-{{ $operation['method'] }}">{{ strtoupper($operation['method']) }}</span><code>{{ $operation['path'] }}</code></a></td>
                <td><a href="{{ Dev::operationUrl($id, $lang) }}" style="color:var(--acct)">{{ $operation['summary'][$lang] }}</a></td>
                <td>@forelse(array_unique(array_merge(...($operation['scopes'] ?: [[]]))) as $scope)<code>{{ $scope }}</code> @empty <span style="color:var(--muted)">–</span> @endforelse</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  @endforeach

  <section aria-labelledby="ref-webhooks" style="margin-top:48px">
    <h2 class="dv-h2" id="ref-webhooks">Webhooks</h2>
    <p style="color:var(--muted);max-width:760px">{{ $lang === 'en' ? 'voucher.posted, invoice.sent, invoice.paid, supplier_invoice.approved, planning.published, planning.attested, sie.uploaded, stock.changed and quote.signed are sent to your https address after the change is saved, signed with HMAC-SHA256. The webhook guide shows how to verify the signature in PHP, JavaScript and Python.' : 'voucher.posted, invoice.sent, invoice.paid, supplier_invoice.approved, planning.published, planning.attested, sie.uploaded, stock.changed och quote.signed skickas till din https-adress när ändringen är sparad, signerade med HMAC-SHA256. Guiden om webhooks visar hur signaturen kontrolleras i PHP, JavaScript och Python.' }}</p>
    <p><a class="dv-btn dv-btn-ghost" href="{{ Dev::url('developers.guide', ['guide' => 'webhooks'], $lang) }}">{{ App\Support\Developer\Guides::title('webhooks', $lang) }}</a></p>
  </section>
</div>
@endsection
