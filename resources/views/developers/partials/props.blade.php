{{--
    The fields of a schema node (SchemaTree), recursively: an object's
    properties, an array's item fields, a map's value fields, or each variant
    of a oneOf/anyOf. Nested objects fold out with <details>.
--}}
@use('App\Support\Developer\DeveloperPortal', 'Dev')
@php
    $depth ??= 0;
    $fieldsOf = function (array $node): ?array {
        if ($node['properties'] !== []) {
            return $node;
        }
        foreach (['items', 'additional'] as $key) {
            if ($node[$key] !== null && ($node[$key]['properties'] !== [] || $node[$key]['variants'] !== null)) {
                return $node[$key];
            }
        }

        return $node['variants'] !== null ? $node : null;
    };
    $target = $fieldsOf($node);
@endphp
@if($target !== null && $depth < 7)
  @if($target['properties'] !== [])
    <ul class="dv-props">
      @foreach($target['properties'] as $property)
        @php
            $child = $property['node'];
            $description = $child['description'][$lang];
            $nested = $fieldsOf($child);
            $count = $nested === null ? 0 : max(count($nested['properties']), count($nested['variants']['options'] ?? []));
        @endphp
        <li>
          <div class="dv-prop-head">
            <code class="dv-prop-name">{{ $property['name'] }}</code>
            <span class="dv-type">{{ $child['label'] }}@if($child['format']) ({{ $child['format'] }})@endif</span>
            @if($property['required'])<span class="dv-badge dv-badge-req">{{ Dev::t('required', $lang) }}</span>@endif
            @if($child['nullable'])<span class="dv-badge">{{ Dev::t('nullable', $lang) }}</span>@endif
            @if($child['readOnly'])<span class="dv-badge">{{ Dev::t('read_only', $lang) }}</span>@endif
            @if($child['writeOnly'])<span class="dv-badge">{{ Dev::t('write_only', $lang) }}</span>@endif
          </div>
          @if($description !== '')
            <p class="dv-prop-desc">{{ $description }}</p>
          @elseif($child['items'] !== null && $child['items']['description'][$lang] !== '')
            <p class="dv-prop-desc">{{ $child['items']['description'][$lang] }}</p>
          @endif
          @if(($meta = Dev::meta($child, $lang)) !== '')
            <p class="dv-prop-meta">{!! $meta !!}</p>
          @endif
          @if($nested !== null && ! $child['recursive'])
            <details>
              <summary><svg class="dv-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"></path></svg>{{ $lang === 'en' ? "{$count} fields" : "{$count} fält" }}@if($child['type'] === 'array') · {{ $lang === 'en' ? 'each item' : 'varje post' }}@endif</summary>
              @include('developers.partials.props', ['node' => $child, 'depth' => $depth + 1])
            </details>
          @endif
        </li>
      @endforeach
    </ul>
  @elseif($target['variants'] !== null)
    <ul class="dv-props">
      @foreach($target['variants']['options'] as $option)
        <li>
          <div class="dv-prop-head">
            <span class="dv-badge">{{ Dev::t($target['variants']['kind'] === 'oneOf' ? 'one_of' : 'any_of', $lang) }}</span>
            <span class="dv-type">{{ $option['label'] }}</span>
          </div>
          @if($option['description'][$lang] !== '')<p class="dv-prop-desc">{{ $option['description'][$lang] }}</p>@endif
          @include('developers.partials.props', ['node' => $option, 'depth' => $depth + 1])
        </li>
      @endforeach
    </ul>
  @endif
@endif
