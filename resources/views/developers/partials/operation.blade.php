{{--
    One operation of the reference: the documentation on the left (method,
    path, scopes, parameters, request body, every response) and the code
    samples and examples on the right. Its id is the operationId, the anchor
    the sidebar, the search and the guides link to. A webhook ($webhook)
    has no code samples: it is a request NONOX sends.
--}}
@use('App\Support\Developer\DeveloperPortal', 'Dev')
@use('App\Support\Developer\CodeBlock')
@use('App\Support\Developer\CodeSamples')
@php
    $webhook ??= false;
    $statusText = fn (string $status): string => \Symfony\Component\HttpFoundation\Response::$statusTexts[(int) $status] ?? '';
    $inLabel = ['path' => Dev::t('in_path', $lang), 'query' => Dev::t('in_query', $lang), 'header' => Dev::t('in_header', $lang)];
    $success = array_values(array_filter($operation['responses'], fn (array $response): bool => str_starts_with($response['status'], '2')));
    $errors = array_values(array_filter($operation['responses'], fn (array $response): bool => ! str_starts_with($response['status'], '2')));
    $errorsGuide = Dev::url('developers.guide', ['guide' => 'fel'], $lang);
@endphp
<section class="dv-op" id="{{ $operation['id'] }}" aria-labelledby="{{ $operation['id'] }}-title">
  <div class="dv-op-doc">
    <h2 id="{{ $operation['id'] }}-title"><a href="#{{ $operation['id'] }}">{{ $operation['summary'][$lang] }}</a>@if($operation['status'] === 'planned') <span class="dv-planned">{{ Dev::t('planned', $lang) }}</span>@endif</h2>
    <p class="dv-endpoint">
      <span class="dv-method dv-m-{{ $operation['method'] }}">{{ strtoupper($operation['method']) }}</span>
      <code>{{ $operation['path'] }}</code>
      <span class="dv-op-id" style="margin-left:auto">{{ $operation['id'] }}</span>
    </p>
    <p>{{ $operation['description'][$lang] }}</p>
    @if($lang === 'sv' && $operation['description']['en'] !== $operation['description']['sv'])
      <details class="dv-response" style="margin-top:-4px">
        <summary><span class="dv-type">{{ 'Mer i detalj (på engelska)' }}</span></summary>
        <div class="dv-response-body" lang="en"><p style="margin:0">{{ $operation['description']['en'] }}</p></div>
      </details>
    @endif

    @unless($webhook)
      <h3>{{ Dev::t('scopes', $lang) }}</h3>
      @if($operation['scopes'] === [] || $operation['scopes'] === [[]])
        <p style="margin:0;color:var(--muted)">{{ Dev::t('no_scope', $lang) }}</p>
      @else
        <p style="margin:0">
          @foreach($operation['scopes'] as $group)
            @foreach($group as $scope)<a href="{{ Dev::url('developers.guide', ['guide' => 'behorigheter'], $lang) }}#{{ Illuminate\Support\Str::slug($scope) }}"><code>{{ $scope }}</code></a>@if(! $loop->last) + @endif @endforeach
            @if(! $loop->last) {{ Dev::t('scope_any', $lang) }} @endif
          @endforeach
        </p>
      @endif
    @endunless

    @if($operation['parameters'] !== [])
      <h3>{{ Dev::t('parameters', $lang) }}</h3>
      <ul class="dv-props">
        @foreach($operation['parameters'] as $parameter)
          <li>
            <div class="dv-prop-head">
              <code class="dv-prop-name">{{ $parameter['name'] }}</code>
              <span class="dv-type">{{ $parameter['schema']['label'] ?? 'string' }}@if($parameter['schema']['format'] ?? null) ({{ $parameter['schema']['format'] }})@endif</span>
              <span class="dv-badge">{{ $inLabel[$parameter['in']] ?? $parameter['in'] }}</span>
              @if($parameter['required'])<span class="dv-badge dv-badge-req">{{ Dev::t('required', $lang) }}</span>@endif
            </div>
            @if($parameter['description'][$lang] !== '')<p class="dv-prop-desc">{{ $parameter['description'][$lang] }}</p>@endif
            @php $schema = $parameter['schema']; @endphp
            @if($schema !== null && ($meta = Dev::meta($schema, $lang, $parameter['example'])) !== '')
              <p class="dv-prop-meta">{!! $meta !!}</p>
            @endif
          </li>
        @endforeach
      </ul>
    @endif

    @if($operation['body'] !== null)
      <h3>{{ Dev::t('request_body', $lang) }}</h3>
      @foreach($operation['body']['contents'] as $content)
        <p class="dv-schema-head"><code>{{ $content['type'] }}</code>@if($content['schema'])<span class="dv-type">{{ $content['schema']['label'] }}</span>@endif @if($operation['body']['required'])<span class="dv-badge dv-badge-req">{{ Dev::t('required', $lang) }}</span>@endif</p>
        @if($operation['body']['description'] !== '')<p class="dv-prop-desc" style="margin:0 0 10px">{{ $operation['body']['description'] }}</p>@endif
        @if($content['schema'])
          @if($content['schema']['description'][$lang] !== '')<p class="dv-prop-desc" style="margin:0 0 10px">{{ $content['schema']['description'][$lang] }}</p>@endif
          @include('developers.partials.props', ['node' => $content['schema']])
        @endif
      @endforeach
    @endif

    <h3>{{ Dev::t('responses', $lang) }}</h3>
    @foreach($operation['responses'] as $response)
      <details class="dv-response" @if($loop->first) open @endif>
        <summary>
          <span class="dv-status dv-s{{ substr($response['status'], 0, 1) }}">{{ $response['status'] }}</span>
          <span>{{ $statusText($response['status']) }}@if($statusText($response['status']) !== '') – @endif{{ $response['description'] }}</span>
        </summary>
        <div class="dv-response-body">
          @if($response['ref'] !== null && ! str_starts_with($response['status'], '2'))
            <p class="dv-prop-meta" style="margin:0 0 8px"><a href="{{ $errorsGuide }}">{{ $lang === 'en' ? 'All error codes' : 'Alla felkoder' }}</a> · application/problem+json</p>
          @endif
          @foreach($response['contents'] as $content)
            @if($content['schema'] && str_starts_with($response['status'], '2'))
              <p class="dv-schema-head"><code>{{ $content['type'] }}</code><span class="dv-type">{{ $content['schema']['label'] }}</span></p>
              @include('developers.partials.props', ['node' => $content['schema']])
            @elseif(! str_contains($content['type'], 'json'))
              <p class="dv-schema-head"><code>{{ $content['type'] }}</code></p>
            @endif
            @if(! str_starts_with($response['status'], '2'))
              <ul class="dv-props">
                @foreach($content['examples'] as $example)
                  @php $problem = json_decode($example['json'], true); @endphp
                  <li>
                    <div class="dv-prop-head"><code class="dv-prop-name">{{ $problem['code'] ?? $example['name'] }}</code>@if($example['summary'])<span class="dv-type">{{ $example['summary'] }}</span>@endif</div>
                    @if(isset($problem['detail']))<p class="dv-prop-desc" lang="sv">{{ $problem['detail'] }}</p>@endif
                  </li>
                @endforeach
              </ul>
            @endif
          @endforeach
          @if($response['headers'] !== [])
            <p class="dv-prop-meta" style="margin-top:10px">{{ Dev::t('response_headers', $lang) }}:
              @foreach($response['headers'] as $header)<code title="{{ $header['description'] }}">{{ $header['name'] }}</code>@if(! $loop->last), @endif @endforeach
            </p>
          @endif
        </div>
      </details>
    @endforeach
  </div>

  <div class="dv-op-code">
    @unless($webhook)
      <h3>{{ Dev::t('code_examples', $lang) }}</h3>
      {!! CodeBlock::tabs(array_map(
          fn (string $id, string $code): array => ['id' => $id, 'label' => CodeSamples::LANGUAGES[$id][0], 'language' => CodeSamples::LANGUAGES[$id][1], 'code' => $code],
          array_keys($samples = CodeSamples::for($operation, $spec['server'])),
          $samples,
      ), $lang, Dev::t('code_examples', $lang)) !!}
    @else
      @foreach($operation['body']['contents'] ?? [] as $content)
        @foreach($content['examples'] as $example)
          <h3>{{ $lang === 'en' ? 'Body NONOX sends' : 'Innehållet som NONOX skickar' }}</h3>
          {!! CodeBlock::html("POST ".($lang === 'en' ? '/your/webhook' : '/din/webhook')." HTTP/1.1\nContent-Type: application/json\nNonox-Signature: t=1772444713,v1=5f8e2a1c…\nNonox-Event-Id: ".(json_decode($example['json'], true)['id'] ?? ''), 'http', $lang, 'HTTP') !!}
          {!! CodeBlock::html($example['json'], 'json', $lang, 'JSON') !!}
        @endforeach
      @endforeach
    @endunless

    @foreach($success as $response)
      @foreach($response['contents'] as $content)
        @foreach($content['examples'] as $example)
          <h3>{{ Dev::t('responses', $lang) }} · {{ $response['status'] }}</h3>
          {!! CodeBlock::html($example['json'], 'json', $lang, $content['type']) !!}
        @endforeach
      @endforeach
    @endforeach

    @if($errors !== [] && ! $webhook)
      <h3>{{ Dev::t('problem', $lang) }}</h3>
      @foreach($errors as $response)
        @foreach($response['contents'] as $content)
          <details class="dv-response">
            <summary><span class="dv-status dv-s{{ substr($response['status'], 0, 1) }}">{{ $response['status'] }}</span><span>{{ count($content['examples']) }} {{ $lang === 'en' ? (count($content['examples']) === 1 ? 'example' : 'examples') : (count($content['examples']) === 1 ? 'exempel' : 'exempel') }}</span></summary>
            <div class="dv-response-body">
              @foreach($content['examples'] as $example)
                {!! CodeBlock::html($example['json'], 'json', $lang, $example['summary'] ?? $example['name']) !!}
              @endforeach
            </div>
          </details>
        @endforeach
      @endforeach
    @endif
  </div>
</section>
