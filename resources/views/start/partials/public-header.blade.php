{{-- Shared header of the public pages: skip link, mega menu, mobile menu. --}}
@php
    $home = request()->routeIs('start') ? '' : url('/');
@endphp
<a class="lp0" href="#main" style="position:absolute;left:-9999px;top:8px;background:#FF5A36;color:#15171C;padding:10px 14px;border-radius:8px;z-index:100">Hoppa till innehållet</a>

@php
    $menus = [
        'produkt' => [
            'label' => 'Produkt',
            'items' => [
                ['href' => $home.'#funktioner', 'title' => 'Funktioner', 'text' => 'Bokföring, fakturor, moms, bokslut och lön i ett system.', 'icon' => 'M4 4h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4zM20 4h-6a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h6z'],
                ['href' => $home.'#byraer', 'title' => 'Flera bolag, koncerner och byråer', 'text' => 'Alla bolag under en inloggning, månad för månad.', 'icon' => 'M3 21V9l6-3v15M9 21V3h8v18M17 11h4v10M3 21h18'],
                ['href' => route('migrationGuide'), 'title' => 'Byt system', 'text' => 'Flytta hit med SIE4 och importverktyget utan att tappa något.', 'icon' => 'M4 7h13l-3-3M20 17H7l3 3'],
                ['href' => $home.'#oppen-kallkod', 'title' => 'Öppen källkod', 'text' => 'AGPL-3.0. Läs koden och se exakt hur siffrorna hanteras.', 'icon' => 'M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16'],
                ['href' => $home.'#jamfor', 'title' => 'Jämför', 'text' => 'NONOX mot Fortnox, Visma, Bokio med flera, funktion för funktion.', 'icon' => 'M4 20V10M10 20V4M16 20v-7M22 20H2'],
                ['href' => route('security'), 'title' => 'Säkerhet och regelverk', 'text' => 'Senaste granskningen, betyg per område och det som återstår.', 'icon' => 'M12 3l8 4v5c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V7z'],
                ['href' => $home.'#fragor', 'title' => 'Vanliga frågor', 'text' => 'Ansvar, lagkrav, data och vad det kostar.', 'icon' => 'M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.5V14M12 17.5h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z'],
            ],
            'promo' => ['eyebrow' => 'Din data. Alltid.', 'title' => 'Bokförda verifikationer kan aldrig ändras i efterhand.', 'link' => $ph['github'], 'cta' => 'Se koden på GitHub'],
        ],
        'branscher' => [
            'label' => 'Branscher',
            'items' => array_values(array_map(fn (array $industry): array => [
                'href' => route('industry', $industry['slug']),
                'title' => $industry['name'],
                'text' => $industry['menu_text'],
                'icon' => $industry['icon'],
            ], App\Support\Landing\Industries::all())),
            'promo' => ['eyebrow' => 'Bokföring för din bransch', 'title' => 'Se hur NONOX passar just ditt företag.', 'link' => route('industries'), 'cta' => 'Alla branscher'],
        ],
        'kom-igang' => [
            'label' => 'Kom igång',
            'items' => [
                ['href' => $home.'#kor-sjalv', 'title' => 'Webbhotell', 'text' => 'Ladda upp och följ installationsguiden i webbläsaren.', 'icon' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z'],
                ['href' => $home.'#kor-sjalv', 'title' => 'Egen server', 'text' => 'Med PHP och PostgreSQL. Färdigt Docker-paket kommer snart.', 'icon' => 'M3 4h18v7H3zM3 13h18v7H3zM7 7.5h.01M7 16.5h.01'],
                ['href' => $home.'#kor-sjalv', 'title' => 'Lokalt på datorn', 'text' => 'Program för Windows, Mac och Linux. Kommer snart.', 'icon' => 'M4 5h16v11H4zM2 19h20M10 16v3M14 16v3'],
                ['href' => $home.'#priser', 'title' => 'Vi sköter driften', 'text' => 'Servrar i Sverige/EU, backup och uppdateringar. Från 995 kr/år.', 'icon' => 'M12 3l8 4v5c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V7z'],
            ],
            'promo' => ['eyebrow' => 'Byt system på en eftermiddag', 'title' => 'Flytta hit utan att tappa något.', 'link' => $ph['flytt'], 'cta' => 'Läs flyttguiden'],
        ],
    ];
    $chevron = '<svg class="mm-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg>';
@endphp
<header id="lp-header" style="position:sticky;top:0;z-index:50;background:var(--hdr);backdrop-filter:saturate(1.4) blur(14px);-webkit-backdrop-filter:saturate(1.4) blur(14px);border-bottom:1px solid var(--line)">
  <div style="max-width:1240px;margin:0 auto;padding:0 clamp(20px,4vw,40px);height:68px;display:flex;align-items:center;gap:24px">
    <a href="{{ url('/') }}" aria-label="NONOX, till startsidan" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink)">
      <svg height="26" viewBox="150 195 2295 505" role="img" aria-label="NONOX" style="display:block;width:auto;height:26px"><path data-ring="mark" fill="#FF5A36" d="M653.28 428 A250 250 0 1 0 653.28 466 L569.92 466 A167 167 0 1 1 569.92 428 Z"></path><g fill="currentColor"><path d="M755 292H833L961 463V292H1040V602H961L833 431V602H755Z"></path><path d="M1399.89 441 A163 163 0 1 0 1399.89 453 L1334.82 453 A98 98 0 1 1 1334.82 441 Z"></path><path d="M1442 292H1520L1648 463V292H1727V602H1648L1520 431V602H1442Z"></path><path d="M2086.89 441 A163 163 0 1 0 2086.89 453 L2021.82 453 A98 98 0 1 1 2021.82 441 Z"></path><path d="M2129 292H2222L2284 383L2346 292H2439L2332 447L2439 602H2346L2284 511L2222 602H2129L2236 447Z"></path></g></svg>
    </a>
    <div class="lp-desktop">
      <nav aria-label="Huvudmeny" class="mm-nav">
        @foreach($menus as $key => $menu)
          <button type="button" class="mm-trigger" data-mm="{{ $key }}" aria-expanded="false" aria-controls="mm-{{ $key }}">{{ $menu['label'] }}{!! $chevron !!}</button>
        @endforeach
        <a href="{{ $home }}#priser" class="mm-trigger">Priser</a>
      </nav>
      <div style="display:flex;align-items:center;gap:6px;margin-left:auto">
        @auth
          <a href="{{ route('dashboard') }}" class="mm-login">Till översikten</a>
        @else
          <a href="{{ route('login') }}" class="mm-login">Logga in</a>
        @endauth
        <a class="lp8" href="{{ $home }}#kor-sjalv" style="display:inline-flex;align-items:center;height:40px;padding:0 18px;border-radius:999px;background:#FF5A36;color:#15171C;text-decoration:none;font-size:14px;font-weight:700">Ladda ner gratis</a>
      </div>
    </div>
    <div class="lp-mobile" style="margin-left:auto">
      <button type="button" aria-expanded="false" data-lp-menu aria-controls="mobilmeny" aria-label="Öppna meny" style="width:44px;height:44px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);display:grid;place-items:center;cursor:pointer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
      </button>
    </div>
  </div>

  @foreach($menus as $key => $menu)
    <div id="mm-{{ $key }}" class="mm-panel lp-desktop-block" data-mm-panel="{{ $key }}" hidden>
      <div class="mm-inner">
        <ul class="mm-grid">
          @foreach($menu['items'] as $item)
            <li>
              <a href="{{ $item['href'] }}" class="mm-item" data-mm-close>
                <span class="mm-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}"></path></svg></span>
                <span><span class="mm-title">{{ $item['title'] }}</span><span class="mm-text">{{ $item['text'] }}</span></span>
              </a>
            </li>
          @endforeach
        </ul>
        <a href="{{ $menu['promo']['link'] }}" class="mm-promo" data-mm-close>
          <span class="mm-eyebrow">{{ $menu['promo']['eyebrow'] }}</span>
          <span class="mm-promo-title">{{ $menu['promo']['title'] }}</span>
          <span class="mm-promo-cta">{{ $menu['promo']['cta'] }} →</span>
          <svg viewBox="0 0 24 24" aria-hidden="true" class="mm-promo-ring"><path fill="#FF5A36" d="M23.47 11.13 A11.5 11.5 0 1 0 23.47 12.87 L19.63 12.87 A7.68 7.68 0 1 1 19.63 11.13 Z"></path></svg>
        </a>
      </div>
    </div>
  @endforeach

  <div id="lp-mobilmeny" class="lp-mobile" hidden>
    <nav id="mobilmeny" aria-label="Huvudmeny" style="border-top:1px solid var(--line);padding:8px clamp(20px,4vw,40px) 24px;display:flex;flex-direction:column;background:var(--bg);max-height:calc(100vh - 68px);overflow-y:auto">
      @foreach($menus as $key => $menu)
        <details class="mm-mobile-group">
          <summary>{{ $menu['label'] }}{!! $chevron !!}</summary>
          <ul>
            @foreach($menu['items'] as $item)
              <li><a href="{{ $item['href'] }}" data-lp-close><span class="mm-title">{{ $item['title'] }}</span><span class="mm-text">{{ $item['text'] }}</span></a></li>
            @endforeach
          </ul>
        </details>
      @endforeach
      <a href="{{ $home }}#priser" data-lp-close class="mm-mobile-link">Priser</a>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px">
        @auth
          <a href="{{ route('dashboard') }}" style="display:flex;align-items:center;justify-content:center;height:48px;border-radius:999px;border:1px solid var(--line);background:var(--card);text-decoration:none;font-weight:600">Till översikten</a>
        @else
          <a href="{{ route('login') }}" style="display:flex;align-items:center;justify-content:center;height:48px;border-radius:999px;border:1px solid var(--line);background:var(--card);text-decoration:none;font-weight:600">Logga in</a>
        @endauth
        <a href="{{ $home }}#kor-sjalv" data-lp-close style="display:flex;align-items:center;justify-content:center;height:48px;border-radius:999px;background:#FF5A36;color:#15171C;text-decoration:none;font-weight:700">Ladda ner gratis</a>
      </div>
    </nav>
  </div>
</header>
