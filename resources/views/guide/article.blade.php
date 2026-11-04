{{-- An article of Företagsguiden, or a section's overview with the section's articles as cards ($children). --}}
@extends('guide.layout')
@use('App\Support\Manual\Icons')
@use('App\Support\Guide\Guide')

@php
    $isOverview = $article->isOverview();
    $checked = $article->reviewedOn ? Carbon\CarbonImmutable::parse($article->reviewedOn)->locale('sv')->translatedFormat('j F Y') : null;
@endphp

@section('content')
<div class="mn-with-toc">
  <article class="mn-prose">
    <nav class="mn-crumbs" aria-label="Brödsmulor">
      <ol>
        <li><a href="{{ route('guide.home') }}">Företagsguiden</a></li>
        @if($isOverview)
          <li><span aria-current="page">{{ $section['title'] }}</span></li>
        @else
          <li><a href="{{ Guide::url($section['key']) }}">{{ $section['title'] }}</a></li>
          <li><span aria-current="page">{{ $article->title }}</span></li>
        @endif
      </ol>
    </nav>

    <p class="mn-eyebrow">{!! Icons::svg($section['icon'], 18) !!}<span>{{ $isOverview ? 'Avsnitt' : $section['title'] }}</span></p>
    <h1>{{ $article->title }}</h1>
    @if($article->description !== '')
      <p class="mn-lead">{{ $article->description }}</p>
    @endif

    <ul class="mn-meta" aria-label="Om artikeln">
      <li>{!! Icons::svg('clock', 16) !!}<span>{{ $article->minutes }} min läsning</span></li>
      @if($legalForms !== [])
        <li>{!! Icons::svg('building', 16) !!}<span>Gäller för: {{ implode(', ', $legalForms) }}</span></li>
      @endif
    </ul>

    @include('guide.partials.beta')

    <div class="mn-body">
      {!! $article->html !!}

      @if($article->legalVerify !== [])
        <div class="mn-callout mn-callout-note" role="note" data-guide-verify>
          <p class="mn-callout-title">{!! Icons::svg('alert-note', 18) !!}<span>Det här kontrollerar vi just nu</span></p>
          <div class="mn-callout-body">
            <p>Vi har inte fått det här bekräftat ännu. Kontrollera med Skatteverket, Bolagsverket eller din redovisningskonsult innan du går efter det.</p>
            <ul>
              @foreach($article->legalVerify as $question)
                <li>{{ $question }}</li>
              @endforeach
            </ul>
          </div>
        </div>
      @endif

      @if($children !== [])
        <h2 id="artiklar"><a class="mn-anchor" href="#artiklar">Artiklar i avsnittet</a></h2>
        <ul class="mn-cards">
          @foreach($children as $child)
            <li>
              <a class="mn-card" href="{{ $child->url() }}">
                <strong>{{ $child->title }}</strong>
                @if($child->description !== '')
                  <span class="mn-card-text">{{ $child->description }}</span>
                @endif
                <span class="mn-card-foot"><span>{{ $child->minutes }} min</span></span>
              </a>
            </li>
          @endforeach
        </ul>
      @endif

      @if($article->sources !== [])
        <section class="gd-sources" aria-labelledby="kallor">
          <h2 id="kallor"><a class="mn-anchor" href="#kallor">Källor</a></h2>
          <ul>
            @foreach($article->sources as $source)
              <li><a href="{{ $source['url'] }}" target="_blank" rel="noopener">{{ $source['title'] }}</a></li>
            @endforeach
          </ul>
          @if($checked)
            <p class="gd-checked">Senast kontrollerad {{ $checked }}</p>
          @endif
        </section>
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
      @if($manualUrl)
        <a href="{{ $manualUrl }}">Så gör du det i NONOX: läs manualen</a>
      @endif
      @auth
        <a href="{{ route('guide.plan') }}">Till min startplan</a>
      @endauth
      <a href="{{ route('guide.home', $selection) }}">Till Företagsguidens start</a>
    </p>
  </article>
</div>
@endsection
