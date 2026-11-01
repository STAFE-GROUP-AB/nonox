{{-- One tag of the reference (/utvecklare/referens/{tag}): every operation with an anchor per operationId. --}}
@extends('developers.layout')
@use('App\Support\Developer\DeveloperPortal', 'Dev')

@section('content')
<div class="dv-wide" style="max-width:1320px">
  <p class="dv-eyebrow"><a href="{{ Dev::url('developers.reference', [], $lang) }}" style="color:inherit">{{ Dev::t('reference', $lang) }}</a></p>
  <h1>{{ $name }}</h1>
  <p class="dv-lead">{{ $tag['description'][$lang] }}</p>
  <nav aria-label="{{ Dev::t('endpoints', $lang) }}" style="margin-top:22px">
    <ul style="list-style:none;margin:0;padding:0;display:grid;gap:6px">
      @foreach($operations as $operation)
        <li><a href="#{{ $operation['id'] }}" style="display:inline-flex;flex-wrap:wrap;gap:4px 10px;align-items:baseline;text-decoration:none"><span class="dv-method dv-m-{{ $operation['method'] }}">{{ strtoupper($operation['method']) }}</span><code>{{ $operation['path'] }}</code><span style="color:var(--muted)">{{ $operation['summary'][$lang] }}</span></a></li>
      @endforeach
    </ul>
  </nav>

  <div style="margin-top:24px">
    @foreach($operations as $operation)
      @include('developers.partials.operation', ['operation' => $operation])
    @endforeach
  </div>
</div>
@endsection
