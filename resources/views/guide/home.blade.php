{{--
    Företagsguiden's start page (GuideController::home): the search, the
    "Vad driver du för företag?" picker, the start plan for the chosen legal
    form and industry, the questions founders ask, the sections and what is
    coming. The choice is the query string (?bolagsform=AB&bransch=cafe), so
    the page is rendered on the server, works without scripts and can be shared.
--}}
@extends('guide.layout', ['wide' => true])
@use('App\Support\Manual\Icons')
@use('App\Support\Guide\Guide')
@use('App\Support\Guide\StepGroups')

@php
    $with = fn (array $changes = []): string => route('guide.home', array_filter([...$selection, ...$changes], fn ($value) => $value !== null)).'#vad-driver-du';
    $keep = fn (string $url): string => $selection === [] ? $url : $url.'?'.http_build_query($selection);
    $check = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"></path></svg>';
    $arrow = Icons::svg('arrow', 16);
    $stepCount = array_sum(array_map(fn (array $group): int => count($group['steps']), $path));
    $formLabel = $legalForms[$legalForm] ?? $legalForm;
@endphp

@section('content')
<section class="gd-hero" aria-labelledby="gd-title">
  <div class="gd-wrap gd-hero-grid">
    <div class="gd-hero-inner">
      <p class="mn-eyebrow">{!! Icons::svg('rocket', 18) !!}<span>Företagsguiden</span> <span class="gd-badge">Beta</span></p>
      <h1 id="gd-title">Så startar och driver du <em>ditt företag</em></h1>
      <p class="mn-lead">Vad lagen kräver av dig, hur moms och skatt fungerar och i vilken ordning du gör det, förklarat på vanlig svenska. Varje artikel hänvisar till Skatteverket, Bolagsverket eller lagtexten.</p>

      <form class="gd-search" role="search" method="get" action="{{ route('guide.home') }}#sok">
        <label for="gd-sok">Sök i Företagsguiden</label>
        <div class="gd-search-field">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-4-4"></path></svg>
          <input id="gd-sok" type="search" name="sok" value="{{ $search }}" placeholder="Till exempel moms, F-skatt eller kvitton" autocomplete="off" maxlength="100">
        </div>
        @foreach($selection as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <button type="submit" class="gd-btn">Sök</button>
      </form>

      <ul class="gd-sources-line" aria-label="Källorna i guiden">
        <li>{!! $check !!}Skatteverket</li>
        <li>{!! $check !!}Bolagsverket</li>
        <li>{!! $check !!}verksamt.se</li>
        <li>{!! $check !!}Lagtexten</li>
      </ul>
      @include('guide.partials.beta')
    </div>

    @php $preview = array_merge(...array_map(fn (array $group): array => $group['steps'], $path ?: [['steps' => []]])); @endphp
    @if($preview !== [])
      {{-- A picture of the start plan for the path below, from the same steps: the first two drawn as done. --}}
      <div class="gd-preview" aria-hidden="true">
        <div class="gd-preview-head"><p class="gd-preview-title">Min startplan</p><span class="gd-preview-count">2 av {{ $stepCount }} klara</span></div>
        <h2>{{ $formLabel }}</h2>
        <div class="gd-preview-bar"><i style="width:{{ max(8, (int) round(2 / max(1, $stepCount) * 100)) }}%"></i></div>
        <ol>
          @foreach(array_slice($preview, 0, 5) as $item)
            <li @class(['is-done' => $item['number'] <= 2])><b>{!! $item['number'] <= 2 ? '✓' : $item['number'] !!}</b><span>{{ $item['step']->title }}</span></li>
          @endforeach
        </ol>
        @if($stepCount > 5)
          <p class="gd-preview-more">och {{ $stepCount - 5 }} steg till</p>
        @endif
      </div>
    @endif
  </div>
</section>

<div class="gd-wrap">
  <section class="gd-picker" aria-labelledby="gd-picker-title" id="vad-driver-du">
    <div class="gd-picker-head">
      <div>
        <h2 id="gd-picker-title">Vad driver du för företag?</h2>
        <p class="gd-picker-hint">
          @if($chosenForm === null)
            Välj företagsform och bransch, så visar vi stegen som gäller dig. Tills du har valt visar vi vägen för ett aktiebolag.
          @else
            Vägen nedan gäller {{ mb_strtolower($formLabel) }}@if($industry && $industry !== App\Domain\Company\Industry::Other), bransch {{ mb_strtolower($industry->label()) }}@endif. Dela adressen, så ser mottagaren samma sak.
          @endif
        </p>
      </div>
      @if($selection !== [])
        <a class="gd-reset" href="{{ route('guide.home') }}">Rensa valet</a>
      @endif
    </div>

    <div class="gd-groups">
      <div role="group" aria-labelledby="gd-forms-label">
        <p class="gd-group-label" id="gd-forms-label">Företagsform</p>
        <ul class="gd-chips">
          @foreach($legalForms as $value => $label)
            <li><a class="gd-chip" href="{{ $with(['bolagsform' => $value]) }}" @if($chosenForm === $value) aria-current="true" @endif>{{ $label }}</a></li>
          @endforeach
        </ul>
      </div>
      <div role="group" aria-labelledby="gd-industries-label">
        <p class="gd-group-label" id="gd-industries-label">Bransch</p>
        <ul class="gd-chips">
          @foreach($industries as $option)
            <li><a class="gd-chip" href="{{ $with(['bransch' => $industry === $option ? null : $option->value]) }}" @if($industry === $option) aria-current="true" @endif>{{ $option->label() }}</a></li>
          @endforeach
        </ul>
      </div>
    </div>
  </section>

  @if($search !== '')
    <section class="gd-results" id="sok" aria-labelledby="gd-results-title" aria-live="polite">
      <h2 id="gd-results-title">
        @if($results === [])
          Inga artiklar matchar ”{{ $search }}”
        @else
          {{ count($results) }} {{ count($results) === 1 ? 'artikel' : 'artiklar' }} om ”{{ $search }}”
        @endif
      </h2>
      @if($results === [])
        <p class="gd-picker-hint">Pröva ett annat ord, till exempel moms, skatt, kvitto eller aktiebolag, eller titta bland frågorna och avsnitten nedan.</p>
      @else
        <ul>
          @foreach($results as $hit)
            <li><a href="{{ $keep($hit->url()) }}"><strong>{{ $hit->title }}</strong><span>{{ $hit->description }}</span></a></li>
          @endforeach
        </ul>
      @endif
    </section>
  @endif

  <section class="gd-band" id="din-vag" aria-labelledby="gd-path-title">
    <p class="gd-kicker">Din väg</p>
    <h2 class="gd-h2" id="gd-path-title">{{ $stepCount }} steg för {{ mb_strtolower($formLabel) }}@if($industry && $industry !== App\Domain\Company\Industry::Other)<span class="gd-path-industry"> · {{ $industry->label() }}</span>@endif</h2>
    <p class="gd-sub">I den ordning de flesta gör dem. Steg som bara gäller ibland är märkta, till exempel om du är momsregistrerad eller har anställda.</p>

    <div class="gd-path">
      <div>
        @if($path === [])
          <p class="mn-empty">Stegen för den här företagsformen skrivs just nu.</p>
        @endif
        @foreach($path as $group)
          <section class="gd-phase" aria-labelledby="gd-phase-{{ $group['key'] }}">
            <h3 class="gd-phase-title" id="gd-phase-{{ $group['key'] }}">{{ $group['label'] }}</h3>
            <ol class="gd-steps">
              @foreach($group['steps'] as $item)
                @php $step = $item['step']; $stepUrl = $step->articleUrl(); @endphp
                <li class="gd-step" value="{{ $item['number'] }}">
                  <span @class(['gd-num-badge', 'is-first' => $item['number'] === 1]) aria-hidden="true">{{ $item['number'] }}</span>
                  <div>
                    <h4><span class="mn-sr">Steg {{ $item['number'] }}: </span>{{ $step->title }}</h4>
                    <p>{{ $step->text }}</p>
                    <div class="gd-step-foot">
                      @if(StepGroups::conditionLabel($step->condition))
                        <span class="gd-tag">{{ ucfirst(StepGroups::conditionLabel($step->condition)) }}</span>
                      @endif
                      @if($stepUrl)
                        <a class="gd-link" href="{{ $keep($stepUrl) }}">Läs mer<span class="mn-sr">: {{ $step->title }}</span> {!! $arrow !!}</a>
                      @endif
                    </div>
                  </div>
                </li>
              @endforeach
            </ol>
          </section>
        @endforeach
      </div>

      <aside class="gd-aside" aria-label="Startplanen i NONOX">
        <div class="gd-cta">
          <h3>Få din personliga startplan i NONOX</h3>
          <p>Samma steg, för just ditt företag. Du bockar av dem när de är gjorda och ser hur långt företaget har kommit.</p>
          <ul>
            <li>{!! $check !!}<span>Stegen väljs efter företagsform, bransch och moms</span></li>
            <li>{!! $check !!}<span>Du ser vem som bockade av vad, och när</span></li>
            <li>{!! $check !!}<span>Tips från guiden på sidorna i NONOX där de hör hemma</span></li>
          </ul>
          <div class="gd-cta-actions">
            @auth
              <a class="gd-btn gd-btn-acc" href="{{ route('guide.plan') }}">Till min startplan</a>
            @else
              @if($signupUrl)
                <a class="gd-btn gd-btn-acc" href="{{ $signupUrl }}">{{ $signupLabel }}</a>
              @endif
              <a class="gd-btn gd-btn-ghost" href="{{ route('guide.plan') }}">Logga in</a>
            @endauth
          </div>
        </div>

        @if($industry && $industry !== App\Domain\Company\Industry::Other)
          <div class="gd-panel" data-guide-industry>
            <p class="gd-kicker">{{ $industry->label() }}</p>
            @if($industryPage)
              <h3>NONOX för {{ mb_strtolower($industryPage['name']) }}</h3>
              <p>{{ $industryPage['text'] }}</p>
              <a class="gd-link" href="{{ $industryPage['url'] }}">Så fungerar NONOX för {{ mb_strtolower($industryPage['name']) }} {!! $arrow !!}</a>
            @endif
            @if($industry->guideArticle() && App\Support\Guide\Guide::article('branscher', $industry->guideArticle(), false))
              <p>Stegen ovan har med det som är särskilt för {{ mb_strtolower($industry->label()) }}. Läs mer i branschspåret.</p>
              <a class="gd-link" href="{{ route('guide.article', ['section' => 'branscher', 'article' => $industry->guideArticle()]) }}" data-guide-industry-track>Branschspåret för {{ mb_strtolower($industry->label()) }} {!! $arrow !!}</a>
            @endif
          </div>
        @endif
      </aside>
    </div>
  </section>

  @if($questions !== [])
    <section class="gd-band" aria-labelledby="gd-questions-title">
      <p class="gd-kicker">Vanliga frågor</p>
      <h2 class="gd-h2" id="gd-questions-title">Frågor många har det första året</h2>
      <ul class="gd-qs">
        @foreach($questions as $item)
          <li>
            <a class="gd-q" href="{{ $keep($item['article']->url()) }}">
              <strong>{{ $item['question'] }}</strong>
              <span>{!! Icons::svg('book', 16) !!}{{ $item['article']->title }}</span>
            </a>
          </li>
        @endforeach
      </ul>
    </section>
  @endif

  @if($sections !== [])
    <section class="gd-band" id="avsnitt" aria-labelledby="gd-sections-title">
      <p class="gd-kicker">Hela guiden</p>
      <h2 class="gd-h2" id="gd-sections-title">Läs i lugn och ro</h2>
      <p class="gd-sub">Varje avsnitt börjar med en översikt och går sedan igenom en fråga i taget, med källorna längst ner.</p>
      <ul class="gd-cards">
        @foreach($sections as $card)
          @php $titles = array_slice(array_values(array_filter($card['articles'], fn (string $key): bool => ! $articles[$key]->isOverview())), 0, 3); @endphp
          <li>
            <a class="gd-card" href="{{ $keep(Guide::url($card['key'])) }}">
              <span class="gd-card-icon">{!! Icons::svg($card['icon'], 24) !!}</span>
              <strong>{{ $card['title'] }}</strong>
              <span class="gd-card-text">{{ $card['description'] }}</span>
              @if($titles !== [])
                <ul>
                  @foreach($titles as $key)
                    <li>{!! $check !!}<span>{{ $articles[$key]->title }}</span></li>
                  @endforeach
                </ul>
              @endif
              <span class="gd-card-foot"><span>{{ count($card['articles']) }} {{ count($card['articles']) === 1 ? 'artikel' : 'artiklar' }}</span>{!! $arrow !!}</span>
            </a>
          </li>
        @endforeach
      </ul>
    </section>
  @endif
</div>

<section class="gd-band gd-dark" aria-labelledby="gd-soon-title">
  <div class="gd-wrap">
    <p class="gd-kicker">I guiden och i NONOX</p>
    <h2 class="gd-h2" id="gd-soon-title">Mer än artiklar</h2>
    <p class="gd-sub">Mina datum och Momsbufferten räknas fram ur ditt företag i NONOX. Branschspåren och Att växa finns här i guiden för alla.</p>
    <ul class="gd-soon">
      <li class="gd-live" data-guide-dates-teaser>
        <span class="gd-soon-badge gd-live-badge">Nu i NONOX</span>
        <h3>Mina datum</h3>
        <p>Företagets frister på en sida och i din egen kalender: moms, arbetsgivardeklaration, deklaration, årsstämma och årsredovisning, räknade ur företagets inställningar.</p>
        @auth
          <a class="gd-link gd-live-link" href="{{ route('guide.dates') }}">Till Mina datum {!! $arrow !!}</a>
        @else
          @if($signupUrl)
            <a class="gd-link gd-live-link" href="{{ $signupUrl }}">{{ $signupLabel }} {!! $arrow !!}</a>
          @endif
        @endauth
      </li>
      <li class="gd-live" data-guide-buffer-teaser>
        <span class="gd-soon-badge gd-live-badge">Nu i NONOX</span>
        <h3>Momsbufferten</h3>
        <p>Hur mycket av pengarna på kontot som inte är dina: moms, personalskatt och arbetsgivaravgifter, räknat ur det som är bokfört.</p>
        @auth
          <a class="gd-link gd-live-link" href="{{ route('guide.dates') }}#momsbufferten">Till Momsbufferten {!! $arrow !!}</a>
        @else
          @if($signupUrl)
            <a class="gd-link gd-live-link" href="{{ $signupUrl }}">{{ $signupLabel }} {!! $arrow !!}</a>
          @endif
        @endauth
      </li>
      <li class="gd-live" data-guide-industry-teaser>
        <span class="gd-soon-badge gd-live-badge">Nu i guiden</span>
        <h3>Branschspåren</h3>
        <p>Det som är särskilt för kaféer, restauranger, butiker, frisörer, hantverkare, konsulter och byråer, med egna steg i startplanen.</p>
        <a class="gd-link gd-live-link" href="{{ route('guide.section', 'branscher') }}">Till branschspåren {!! $arrow !!}</a>
      </li>
      <li class="gd-live" data-guide-growth-teaser>
        <span class="gd-soon-badge gd-live-badge">Nu i guiden</span>
        <h3>Att växa</h3>
        <p>Sätta pris, ta betalt i tid, hitta kunder, första anställningen och att byta företagsform.</p>
        <a class="gd-link gd-live-link" href="{{ route('guide.section', 'vaxa') }}">Till Att växa {!! $arrow !!}</a>
      </li>
    </ul>
  </div>
</section>
@endsection
