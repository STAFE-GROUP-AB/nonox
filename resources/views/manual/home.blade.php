{{-- The manual's start page: search, the way in for companies and for employees, every section and the popular articles. --}}
@extends('manual.layout', ['wide' => true])
@use('App\Support\Manual\Icons')
@use('App\Support\Manual\Manual')

@section('content')
<section class="mn-hero" aria-labelledby="mn-title">
  <div class="mn-hero-inner">
    <p class="mn-eyebrow">{!! Icons::svg('book', 18) !!}<span>Manual för NONOX</span></p>
    <h1 id="mn-title">Hur kan vi hjälpa dig?</h1>
    <p class="mn-lead">Steg för steg genom bokföring, fakturor, moms, bokslut och lön. Skriven på vanlig svenska, för dig som driver ett företag, sköter bokföringen eller är anställd.</p>
    @include('manual.partials.search', ['id' => 'mn-search-hero', 'hero' => true])
  </div>
</section>

@if($sections === [])
  <div class="mn-section" style="padding-bottom:72px">
    <p class="mn-empty">Manualen skrivs just nu. Under tiden finns <a href="{{ route('migrationGuide') }}">flyttguiden</a> och <a href="{{ route('developers.home') }}">dokumentationen för utvecklare</a>.</p>
  </div>
@else
  <section class="mn-section" aria-labelledby="mn-audience">
    <h2 id="mn-audience" class="mn-sr">Välj var du börjar</h2>
    <div class="mn-audience">
      <a href="{{ isset($sections['kom-igang']) ? Manual::url('kom-igang') : '#avsnitt' }}">
        <span class="mn-audience-icon">{!! Icons::svg('building', 28) !!}</span>
        <span>
          <span class="mn-audience-eyebrow">Företag och byråer</span>
          <strong>Driver du ett företag eller sköter bokföringen?</strong>
          <span class="mn-audience-text">Kom igång med ditt första företag, bokför, fakturera och stäng året. Allt för ägare, bokförare och redovisningskonsulter.</span>
          <span class="mn-audience-cta">Börja här {!! Icons::svg('arrow', 16) !!}</span>
        </span>
      </a>
      @if($staffSection)
        <a class="mn-staff" href="{{ Manual::url($staffSection['key']) }}">
          <span class="mn-audience-icon">{!! Icons::svg('person', 28) !!}</span>
          <span>
            <span class="mn-audience-eyebrow">Är du anställd?</span>
            <strong>{{ $staffSection['title'] }}</strong>
            <span class="mn-audience-text">{{ $staffSection['description'] }}</span>
            <span class="mn-audience-cta">Till hjälpen för anställda {!! Icons::svg('arrow', 16) !!}</span>
          </span>
        </a>
      @endif
    </div>
  </section>

  <section class="mn-section" id="avsnitt" aria-labelledby="mn-sections">
    <h2 id="mn-sections">Alla avsnitt</h2>
    <p>Varje avsnitt börjar med en översikt och går sedan igenom en uppgift i taget.</p>
    <ul class="mn-cards">
      @foreach($companySections as $card)
        <li>
          <a class="mn-card" href="{{ Manual::url($card['key']) }}">
            <span class="mn-card-icon">{!! Icons::svg($card['icon'], 22) !!}</span>
            <strong>{{ $card['title'] }}</strong>
            <span class="mn-card-text">{{ $card['description'] }}</span>
            <span class="mn-card-foot">
              <span>{{ count($card['articles']) }} {{ count($card['articles']) === 1 ? 'artikel' : 'artiklar' }}</span>
              @if($card['module'] && Manual::moduleName($card['module']))
                <span class="mn-card-badge">Modulen {{ Manual::moduleName($card['module']) }}</span>
              @endif
            </span>
          </a>
        </li>
      @endforeach
    </ul>
  </section>

  @if($popular !== [])
    <section class="mn-section" aria-labelledby="mn-popular">
      <h2 id="mn-popular">Populära artiklar</h2>
      <ul class="mn-popular">
        @foreach($popular as $item)
          <li>
            <a href="{{ Manual::url($item['section'], $item['slug']) }}">
              <span>{{ $item['title'] }}<small>{{ $sections[$item['section']]['title'] }}</small></span>
              {!! Icons::svg('arrow', 18) !!}
            </a>
          </li>
        @endforeach
      </ul>
    </section>
  @endif
@endif

<section class="mn-section" aria-labelledby="mn-more">
  <h2 id="mn-more">Mer hjälp</h2>
  <div class="mn-more" style="margin-top:20px">
    <a href="{{ route('migrationGuide') }}">
      <strong>Flytta till NONOX</strong>
      <span>Byt från ditt gamla bokföringsprogram steg för steg, utan att något går förlorat.</span>
    </a>
    <a href="{{ route('developers.home') }}">
      <strong>För utvecklare</strong>
      <span>Ett öppet API med guider, en fullständig referens och kodexempel.</span>
    </a>
    <a href="{{ route('security') }}">
      <strong>Säkerhet och regelverk</strong>
      <span>Hur NONOX följer Bokföringslagen och GDPR, och vad som granskats.</span>
    </a>
  </div>
</section>
@endsection
