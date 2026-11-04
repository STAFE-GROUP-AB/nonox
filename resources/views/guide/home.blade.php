{{-- Företagsguiden's start page: what it is, the Beta notice, every section with its articles. --}}
@extends('guide.layout', ['wide' => true])
@use('App\Support\Manual\Icons')
@use('App\Support\Guide\Guide')

@section('content')
<section class="mn-hero" aria-labelledby="gd-title">
  <div class="mn-hero-inner">
    <p class="mn-eyebrow">{!! Icons::svg('rocket', 18) !!}<span>Företagsguiden</span></p>
    <h1 id="gd-title">Starta och driv ditt företag rätt från början</h1>
    <p class="mn-lead">Vad lagen kräver av dig, hur moms och skatt fungerar och vilka datum som gäller, förklarat på vanlig svenska. Varje artikel hänvisar till Skatteverket, Bolagsverket eller lagtexten.</p>
    @include('guide.partials.beta')
    @auth
      <a class="gd-plan" href="{{ route('guide.plan') }}">Till min startplan {!! Icons::svg('arrow', 16) !!}</a>
    @endauth
  </div>
</section>

@if($sections === [])
  <div class="mn-section" style="padding-bottom:72px">
    <p class="mn-empty">Företagsguiden skrivs just nu.</p>
  </div>
@else
  <section class="mn-section" id="avsnitt" aria-labelledby="gd-sections" style="padding-bottom:72px">
    <h2 id="gd-sections">Alla avsnitt</h2>
    <p>Varje avsnitt börjar med en översikt och går sedan igenom en fråga i taget.</p>
    <ul class="mn-cards">
      @foreach($sections as $card)
        <li>
          <a class="mn-card" href="{{ Guide::url($card['key']) }}">
            <span class="mn-card-icon">{!! Icons::svg($card['icon'], 22) !!}</span>
            <strong>{{ $card['title'] }}</strong>
            <span class="mn-card-text">{{ $card['description'] }}</span>
            <span class="mn-card-foot"><span>{{ count($card['articles']) }} {{ count($card['articles']) === 1 ? 'artikel' : 'artiklar' }}</span></span>
          </a>
        </li>
      @endforeach
    </ul>
  </section>
@endif
@endsection
