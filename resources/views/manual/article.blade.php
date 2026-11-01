{{-- An article of the manual, or a section's overview with the section's articles as cards ($children). --}}
@extends('manual.layout')
@use('App\Support\Manual\Icons')
@use('App\Support\Manual\Manual')

@php
    $isOverview = $article['slug'] === Manual::OVERVIEW;
    $toc = $page['toc'];
    if ($children !== []) {
        $toc[] = ['id' => 'artiklar', 'text' => 'Artiklar i avsnittet'];
    }
@endphp

@section('content')
<div class="mn-with-toc">
  <article class="mn-prose">
    <nav class="mn-crumbs" aria-label="Brödsmulor">
      <ol>
        <li><a href="{{ route('manual.home') }}">Manual</a></li>
        @if($isOverview)
          <li><span aria-current="page">{{ $section['title'] }}</span></li>
        @else
          <li><a href="{{ Manual::url($section['key']) }}">{{ $section['title'] }}</a></li>
          <li><span aria-current="page">{{ $article['title'] }}</span></li>
        @endif
      </ol>
    </nav>

    <p class="mn-eyebrow">{!! Icons::svg($section['icon'], 18) !!}<span>{{ $isOverview ? 'Avsnitt' : $section['title'] }}</span></p>
    <h1>{{ $page['title'] !== '' ? $page['title'] : $article['title'] }}</h1>
    @if($article['description'] !== '')
      <p class="mn-lead">{{ $article['description'] }}</p>
    @endif

    <ul class="mn-meta" aria-label="Om artikeln">
      <li>{!! Icons::svg('clock', 16) !!}<span>{{ $page['minutes'] }} min läsning</span></li>
      @if($roles !== [])
        <li>{!! Icons::svg('users', 16) !!}<span>Gäller för: {{ implode(', ', $roles) }}</span></li>
      @endif
      @if($moduleName)
        <li class="mn-meta-module">{!! Icons::svg('module', 16) !!}<span>Kräver modulen {{ $moduleName }}</span></li>
      @endif
    </ul>

    <div class="mn-body">
      {!! $page['html'] !!}

      @if($children !== [])
        <h2 id="artiklar"><a class="mn-anchor" href="#artiklar">Artiklar i avsnittet</a></h2>
        <ul class="mn-cards">
          @foreach($children as $child)
            <li>
              <a class="mn-card" href="{{ Manual::url($child['section'], $child['slug']) }}">
                <strong>{{ $child['title'] }}</strong>
                @if($child['description'] !== '')
                  <span class="mn-card-text">{{ $child['description'] }}</span>
                @endif
                <span class="mn-card-foot"><span>{{ $child['minutes'] }} min</span>@if($child['module'] && $child['module'] !== $section['module'] && Manual::moduleName($child['module']))<span class="mn-card-badge">{{ Manual::moduleName($child['module']) }}</span>@endif</span>
              </a>
            </li>
          @endforeach
        </ul>
      @endif
    </div>

    @if($previous || $next)
      <nav class="mn-pager" aria-label="Föregående och nästa artikel">
        @if($previous)
          <a href="{{ $previous['url'] }}" rel="prev"><span>← Föregående</span>{{ $previous['title'] }}</a>
        @endif
        @if($next)
          <a href="{{ $next['url'] }}" rel="next" class="mn-next"><span>Nästa →</span>{{ $next['title'] }}</a>
        @endif
      </nav>
    @endif

    <p class="mn-meta-links">
      <a href="{{ App\Support\Landing\LandingPage::links()['github'] }}/blob/main/{{ $article['source'] }}">Föreslå en ändring på GitHub</a>
      <a href="{{ route('manual.home') }}">Till manualens start</a>
    </p>
  </article>

  @if(count($toc) > 1)
    <nav class="mn-toc" aria-label="På den här sidan">
      <h2>På den här sidan</h2>
      <ul>
        @foreach($toc as $item)
          <li><a href="#{{ $item['id'] }}">{{ $item['text'] }}</a></li>
        @endforeach
      </ul>
    </nav>
  @endif
</div>
@endsection
