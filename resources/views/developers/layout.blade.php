{{--
    The frame of the developer site (/utvecklare, DeveloperPortalController):
    header, sidebar with search and menu, content and footer. Everything is
    rendered on the server; resources/js/developer-portal.js adds search,
    tabs, copy buttons and the menu on narrow screens.
--}}
@use('App\Support\Developer\DeveloperPortal', 'Dev')
@php
    $t = fn (string $key): string => Dev::t($key, $lang);
    $otherLang = $lang === 'en' ? 'sv' : 'en';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
@include('start.partials.public-head')
@include('developers.partials.styles')
@vite('resources/js/developer-portal.js')
</head>
<body>
<div class="dv">
<a class="dv-skip" href="#main">{{ $t('skip') }}</a>
<header class="dv-header">
  <div class="dv-header-inner">
    <a href="{{ url('/') }}" class="dv-brand" aria-label="NONOX, {{ $lang === 'en' ? 'home' : 'till startsidan' }}">
      <svg height="22" viewBox="150 195 2295 505" role="img" aria-hidden="true" style="display:block;width:auto;height:22px"><path fill="#FF5A36" d="M653.28 428 A250 250 0 1 0 653.28 466 L569.92 466 A167 167 0 1 1 569.92 428 Z"></path><g fill="currentColor"><path d="M755 292H833L961 463V292H1040V602H961L833 431V602H755Z"></path><path d="M1399.89 441 A163 163 0 1 0 1399.89 453 L1334.82 453 A98 98 0 1 1 1334.82 441 Z"></path><path d="M1442 292H1520L1648 463V292H1727V602H1648L1520 431V602H1442Z"></path><path d="M2086.89 441 A163 163 0 1 0 2086.89 453 L2021.82 453 A98 98 0 1 1 2021.82 441 Z"></path><path d="M2129 292H2222L2284 383L2346 292H2439L2332 447L2439 602H2346L2284 511L2222 602H2129L2236 447Z"></path></g></svg>
    </a>
    <a href="{{ Dev::url('developers.home', [], $lang) }}" class="dv-brand-tag" @if($currentRoute === 'developers.home') aria-current="page" @endif>{{ $t('developers') }}</a>
    <nav class="dv-topnav" aria-label="{{ $t('developers') }}">
      <a href="{{ Dev::url('developers.guide', ['guide' => 'kom-igang'], $lang) }}" @if($currentRoute === 'developers.guide') aria-current="page" @endif>{{ $t('guides') }}</a>
      <a href="{{ Dev::url('developers.reference', [], $lang) }}" @if(in_array($currentRoute, ['developers.reference', 'developers.tag'], true)) aria-current="page" @endif>{{ $t('reference') }}</a>
      <a href="{{ Dev::url('developers.changelog', [], $lang) }}" @if($currentRoute === 'developers.changelog') aria-current="page" @endif>{{ $t('changelog') }}</a>
    </nav>
    <div class="dv-header-end">
      <a class="dv-header-link" href="{{ $alternates[$otherLang] }}" hreflang="{{ $otherLang }}" lang="{{ $otherLang }}" aria-label="{{ $t('other_language_label') }}">{{ $t('other_language') }}</a>
      <a class="dv-header-link dv-hide-narrow" href="{{ App\Support\Landing\LandingPage::links()['github'] }}">GitHub</a>
      <button type="button" class="dv-menu-button" data-dv-menu aria-expanded="false" aria-controls="dv-side">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
        <span class="dv-menu-label">{{ $t('menu') }}</span>
      </button>
    </div>
  </div>
</header>

<div class="dv-shell">
  <aside class="dv-side" id="dv-side" aria-label="{{ $t('menu') }}">
    <div class="dv-search" role="search">
      <label for="dv-search-input">{{ $t('search') }}</label>
      <input id="dv-search-input" type="search" autocomplete="off" spellcheck="false" placeholder="{{ $t('search_placeholder') }}" data-dv-search aria-controls="dv-search-results" aria-describedby="dv-search-hint">
      <p class="dv-search-hint" id="dv-search-hint">{{ $t('search_hint') }}</p>
      <div id="dv-search-results" data-dv-results aria-live="polite" data-empty="{{ $t('no_results') }}"></div>
    </div>
    <nav aria-label="{{ $t('menu') }}">
      <h2>{{ $t('guides') }}</h2>
      <ul>
        <li><a href="{{ Dev::url('developers.home', [], $lang) }}" @if($currentRoute === 'developers.home') aria-current="page" @endif>{{ $lang === 'en' ? 'Overview' : 'Översikt' }}</a></li>
        @foreach($nav['guides'] as $item)
          <li><a href="{{ $item['url'] }}" @if(($currentParameters['guide'] ?? null) === $item['slug']) aria-current="page" @endif>{{ $item['title'] }}</a></li>
        @endforeach
        <li><a href="{{ Dev::url('developers.changelog', [], $lang) }}" @if($currentRoute === 'developers.changelog') aria-current="page" @endif>{{ $t('changelog') }}</a></li>
      </ul>
      <h2><a href="{{ Dev::url('developers.reference', [], $lang) }}" style="padding:0;display:inline;background:none;color:inherit" @if($currentRoute === 'developers.reference') aria-current="page" @endif>{{ $t('reference') }}</a></h2>
      <ul>
        @foreach($nav['tags'] as $tagItem)
          <li>
            <a class="dv-tag-link" href="{{ $tagItem['url'] }}" @if(($currentParameters['tag'] ?? null) === $tagItem['slug']) aria-current="page" @endif>{{ $tagItem['name'] }}</a>
            <ul class="dv-ops">
              @foreach($tagItem['operations'] as $op)
                <li><a href="{{ $op['url'] }}"><span class="dv-method dv-m-{{ strtolower($op['method']) }}">{{ $op['method'] }}</span><span>{{ $op['summary'] }}</span></a></li>
              @endforeach
            </ul>
          </li>
        @endforeach
      </ul>
      <h2>{{ $t('downloads') }}</h2>
      <ul>
        <li><a href="{{ route('developers.spec', ['format' => 'yaml']) }}" download>{{ $t('download_yaml') }}</a></li>
        <li><a href="{{ route('developers.spec', ['format' => 'json']) }}" download>{{ $t('download_json') }}</a></li>
      </ul>
    </nav>
  </aside>

  <main id="main" class="dv-main" tabindex="-1">
    @yield('content')
  </main>
</div>

<footer class="dv-footer">
  <div class="dv-footer-inner">
    <a href="{{ url('/') }}">{{ $t('back_to_site') }}</a>
    <a href="{{ Dev::url('developers.reference', [], $lang) }}">{{ $t('reference') }}</a>
    <a href="{{ route('developers.spec', ['format' => 'yaml']) }}">openapi.yaml</a>
    <a href="{{ App\Support\Landing\LandingPage::links()['github'] }}">GitHub</a>
    <a href="{{ route('security') }}">{{ $lang === 'en' ? 'Security' : 'Säkerhet' }}</a>
    <a href="{{ route('legal.privacy') }}">{{ $lang === 'en' ? 'Privacy' : 'Integritetspolicy' }}</a>
    <a href="https://www.gnu.org/licenses/agpl-3.0.html">AGPL-3.0</a>
    <small>{{ $lang === 'en' ? 'NONOX is open-source bookkeeping, developed by Stafe Development AB.' : 'NONOX är bokföring med öppen källkod, utvecklat av Stafe Development AB.' }} NONOX {{ App\Support\Build\BuildInfo::line() }}</small>
  </div>
</footer>
</div>
<script type="application/json" id="dv-search-index">{!! json_encode($searchIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
</body>
</html>
