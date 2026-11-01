{{-- A guide (docs/api/guider) or the changelog (docs/api/CHANGELOG.md), rendered from Markdown. --}}
@extends('developers.layout')
@use('App\Support\Developer\DeveloperPortal', 'Dev')

@section('content')
<div class="dv-with-toc">
  <article class="dv-prose" style="min-width:0">
    <p class="dv-eyebrow">{{ $guide === null ? Dev::t('developers', $lang) : Dev::t('guides', $lang) }}</p>
    <h1>{{ $page['title'] }}</h1>
    {!! $page['html'] !!}

    @if($previous || $next)
      <nav class="dv-pager" aria-label="{{ Dev::t('guides', $lang) }}">
        @if($previous)
          <a href="{{ $previous['url'] }}" rel="prev"><span>← {{ Dev::t('previous', $lang) }}</span>{{ $previous['title'] }}</a>
        @endif
        @if($next)
          <a href="{{ $next['url'] }}" rel="next" class="dv-next"><span>{{ Dev::t('next', $lang) }} →</span>{{ $next['title'] }}</a>
        @endif
      </nav>
    @endif

    <p class="dv-meta-links">
      <a href="{{ App\Support\Landing\LandingPage::links()['github'] }}/blob/main/{{ $source }}">{{ Dev::t('edit', $lang) }}</a>
    </p>
  </article>

  @if(count($page['toc']) > 1)
    <nav class="dv-toc" aria-label="{{ Dev::t('on_this_page', $lang) }}">
      <h2>{{ Dev::t('on_this_page', $lang) }}</h2>
      <ul>
        @foreach($page['toc'] as $item)
          <li><a href="#{{ $item['id'] }}">{{ $item['text'] }}</a></li>
        @endforeach
      </ul>
    </nav>
  @endif
</div>
@endsection
