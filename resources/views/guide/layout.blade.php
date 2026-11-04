{{--
    The frame of Företagsguiden (/foretagsguiden, GuideController, ADR-051):
    the manual's design language (manual.partials.styles) with the guide's
    own header, sidebar and footer. Everything is rendered on the server;
    resources/js/manual.js only adds the menu on narrow screens. The start
    page has no sidebar ($wide).
--}}
@use('App\Support\Manual\Icons')
@use('App\Support\Guide\Guide')
@php
    $wide = $wide ?? false;
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
@include('manual.partials.styles')
@include('guide.partials.styles')
@vite('resources/js/manual.js')
</head>
<body>
<div class="mn">
<a class="mn-skip" href="#main">Hoppa till innehållet</a>
@include('start.partials.beta-banner')
<header class="mn-header">
  <div class="mn-header-inner">
    <a href="{{ url('/') }}" class="mn-brand" aria-label="NONOX, till startsidan">
      <svg height="22" viewBox="150 195 2295 505" role="img" aria-hidden="true" style="display:block;width:auto;height:22px"><path fill="#FF5A36" d="M653.28 428 A250 250 0 1 0 653.28 466 L569.92 466 A167 167 0 1 1 569.92 428 Z"></path><g fill="currentColor"><path d="M755 292H833L961 463V292H1040V602H961L833 431V602H755Z"></path><path d="M1399.89 441 A163 163 0 1 0 1399.89 453 L1334.82 453 A98 98 0 1 1 1334.82 441 Z"></path><path d="M1442 292H1520L1648 463V292H1727V602H1648L1520 431V602H1442Z"></path><path d="M2086.89 441 A163 163 0 1 0 2086.89 453 L2021.82 453 A98 98 0 1 1 2021.82 441 Z"></path><path d="M2129 292H2222L2284 383L2346 292H2439L2332 447L2439 602H2346L2284 511L2222 602H2129L2236 447Z"></path></g></svg>
    </a>
    <a href="{{ route('guide.home') }}" class="mn-brand-tag" @if($currentSection === null) aria-current="page" @endif>Företagsguiden</a>
    <nav class="mn-topnav" aria-label="Företagsguiden">
      @foreach($sections as $key => $navSection)
        <a href="{{ Guide::url($key) }}" @if($currentSection === $key) aria-current="page" @endif>{{ $navSection['title'] }}</a>
      @endforeach
      @if(App\Support\Manual\Manual::enabled())
        <a href="{{ route('manual.home') }}">Manual</a>
      @endif
    </nav>
    <div class="mn-header-end">
      @auth
        <a class="mn-login" href="{{ route('guide.plan') }}">Min startplan</a>
      @else
        <a class="mn-login" href="{{ route('login') }}">Logga in</a>
      @endauth
      @unless($wide)
        <button type="button" class="mn-menu-button" data-mn-menu aria-expanded="false" aria-controls="mn-side">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
          <span class="mn-menu-label">Innehåll</span>
        </button>
      @endunless
    </div>
  </div>
</header>

<div @class(['mn-shell', 'mn-shell-wide' => $wide])>
  @unless($wide)
    <aside class="mn-side" id="mn-side" aria-label="Företagsguidens innehåll">
      <nav class="mn-side-nav" aria-label="Avsnitt">
        <ul>
          <li><a class="mn-side-home" href="{{ route('guide.home') }}">{!! Icons::svg('book', 18) !!} Företagsguidens start</a></li>
          @foreach($sections as $key => $navSection)
            <li>
              <details @if($currentSection === $key) open @endif>
                <summary>
                  <span class="mn-side-icon">{!! Icons::svg($navSection['icon'], 18) !!}</span>
                  <span>{{ $navSection['title'] }}</span>
                  <svg class="mn-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"></path></svg>
                </summary>
                <ul>
                  @foreach($navSection['articles'] as $articleKey)
                    <li><a href="{{ $articles[$articleKey]->url() }}" @if($currentArticle === $articleKey) aria-current="page"@endif>{{ $articles[$articleKey]->isOverview() ? 'Översikt' : $articles[$articleKey]->title }}</a></li>
                  @endforeach
                </ul>
              </details>
            </li>
          @endforeach
        </ul>
      </nav>
    </aside>
  @endunless

  <main id="main" class="mn-main" tabindex="-1" @if($wide) style="padding:0" @endif>
    @yield('content')
  </main>
</div>

<footer class="mn-footer">
  <div class="mn-footer-inner">
    <a href="{{ url('/') }}">Till nonox.app</a>
    <a href="{{ route('guide.home') }}">Företagsguiden</a>
    @if(App\Support\Manual\Manual::enabled())
      <a href="{{ route('manual.home') }}">Manual</a>
    @endif
    <a href="{{ route('security') }}">Säkerhet</a>
    <a href="{{ route('legal.privacy') }}">Integritetspolicy</a>
    <small>Företagsguiden förklarar reglerna med egna ord och hänvisar till källan. Den ersätter inte råd från Skatteverket, Bolagsverket eller en redovisningskonsult.</small>
  </div>
</footer>
</div>
</body>
</html>
