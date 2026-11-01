{{--
    One industry page (IndustryController::show), built from its file in
    docs/branscher. Prices come from docs/migration/jamforelse.json and
    LandingPage::PLANS (Industries::pricing); feature names from Features.
--}}
@php
    $kr = fn (int $kronor): string => App\Support\Landing\LandingPage::kronor($kronor);
    $check = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:none;margin-top:3px"><path d="M5 12.5l4.5 4.5L19 7"></path></svg>';
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.in-wrap{max-width:1240px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
.in-eyebrow{margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)}
.in-h2{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(30px,4.4vw,54px);line-height:1.02;letter-spacing:-0.04em;text-wrap:balance}
.in-lead{margin:20px 0 0;max-width:760px;font-size:clamp(16.5px,1.6vw,18.5px);line-height:1.6;color:var(--muted);text-wrap:pretty}
.in-crumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px;font-size:14px;color:var(--muted)}
.in-crumbs li+li::before{content:"/";margin-right:6px;color:var(--muted)}
.in-crumbs a{color:var(--muted)}
.in-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,320px),1fr));gap:14px;margin:clamp(32px,4vw,48px) 0 0;padding:0;list-style:none}
.in-card{border-radius:20px;background:var(--card);border:1px solid var(--line);padding:24px 24px 26px;display:flex;flex-direction:column;gap:10px}
.in-card h3{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:20px;letter-spacing:-0.02em;line-height:1.2}
.in-card p{margin:0;font-size:15.5px;line-height:1.6;color:var(--muted);text-wrap:pretty}
.in-num{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;font-weight:600;color:var(--acct)}
.in-chips{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:6px}
.in-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:var(--accl);font-size:12.5px;font-weight:600;color:var(--ink)}
.in-chip small{font-size:11px;font-weight:600;color:var(--muted)}
.in-price-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:22px;background:var(--card);margin-top:clamp(28px,4vw,40px)}
.in-price{width:100%;min-width:640px;border-collapse:collapse;font-size:15px}
.in-price th,.in-price td{padding:14px 18px;text-align:left;border-bottom:1px solid var(--line);vertical-align:top}
.in-price thead th{font-size:13px;font-weight:700;color:var(--muted)}
.in-price tbody tr:last-child th,.in-price tbody tr:last-child td{border-bottom:0}
.in-price .num{text-align:right;white-space:nowrap;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:18px;letter-spacing:-0.02em;font-variant-numeric:tabular-nums}
.in-price .nonox{background:var(--accl)}
.in-price .nonox .num{color:var(--acct);font-size:21px}
.in-price small{display:block;margin-top:3px;font-size:12.5px;line-height:1.45;color:var(--muted);font-weight:500}
.in-flag{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;border:1px solid var(--line);font-size:11.5px;font-weight:700;color:var(--muted);white-space:nowrap}
.in-sources{margin-top:16px;font-size:14px;line-height:1.55;color:var(--muted)}
.in-sources summary{cursor:pointer;font-weight:600;color:var(--ink)}
.in-sources a{color:var(--acct)}
.in-sources li{margin:6px 0}
.in-link{display:inline-flex;align-items:center;min-height:44px;padding:0 18px;border-radius:999px;border:1px solid var(--line);background:var(--card);text-decoration:none;font-size:14.5px;font-weight:600}
.in-link:hover{border-color:var(--ink);color:var(--ink)}
.in-limits{list-style:none;margin:18px 0 0;padding:0;display:flex;flex-direction:column;gap:10px}
.in-limits li{display:flex;gap:10px;font-size:15.5px;line-height:1.55;color:var(--ink)}
.in-btn{display:inline-flex;align-items:center;min-height:52px;padding:0 24px;border-radius:999px;text-decoration:none;font-size:16px;font-weight:700}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">

@include('start.partials.public-header')

<main id="main">

<section aria-labelledby="in-rubrik" style="padding:clamp(28px,4vw,40px) 0 clamp(48px,7vw,88px)">
  <div class="in-wrap">
    <nav aria-label="Brödsmulor" class="in-crumbs">
      <ol>
        @foreach($breadcrumbs as $crumb)
          <li>@if($loop->last)<span aria-current="page">{{ $crumb['name'] }}</span>@else<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@endif</li>
        @endforeach
      </ol>
    </nav>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:clamp(32px,5vw,64px);align-items:center;margin-top:clamp(36px,6vw,72px)">
      <div>
        <p class="in-eyebrow">{{ $industry['eyebrow'] }}</p>
        <h1 id="in-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,6.4vw,84px);line-height:0.96;letter-spacing:-0.045em;text-wrap:balance">{{ $industry['h1'] }} <span style="color:var(--accb)">{{ $industry['h1_accent'] }}</span></h1>
        <p class="in-lead">{{ $industry['lead'] }}</p>
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:30px">
          <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="in-btn lp8" style="background:#FF5A36;color:#15171C">{{ auth()->check() ? 'Till översikten' : 'Kom igång gratis' }}</a>
          <a href="#pris" class="in-btn" style="border:1.5px solid var(--ink);color:var(--ink);font-weight:600">Se vad det kostar</a>
        </div>
        <p style="margin:20px 0 0;font-size:14.5px;color:var(--muted)">0 kr i egen drift · {{ $kr((int) App\Support\Landing\LandingPage::PLANS[0]['price']) }}/år med drift, upp till {{ App\Support\Landing\LandingPage::PLANS[0]['max'] }} anställda · Alla moduler ingår</p>
      </div>
      <div aria-hidden="true" style="position:relative;max-width:440px;width:100%;justify-self:center;aspect-ratio:1/1">
        <svg viewBox="0 0 400 400" style="width:100%;height:auto;display:block">
          <circle cx="200" cy="200" r="190" fill="var(--accl)"></circle>
          <path fill="#FF5A36" opacity=".9" d="M389.1 187 A190 190 0 1 0 389.1 213 L325.4 213 A127 127 0 1 1 325.4 187 Z"></path>
          <circle cx="200" cy="200" r="104" fill="var(--card)"></circle>
          <g transform="translate(140 140) scale(5)" fill="none" stroke="var(--acct)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $industry['icon'] }}"></path></g>
        </svg>
      </div>
    </div>
  </div>
</section>

<section aria-labelledby="in-manad" style="padding:clamp(56px,8vw,104px) 0;background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="in-wrap">
    <p class="in-eyebrow">Vardagen</p>
    <h2 id="in-manad" class="in-h2">{{ $industry['month']['heading'] }}</h2>
    <p class="in-lead">{{ $industry['month']['intro'] }}</p>
    <ol class="in-grid">
      @foreach($industry['month']['items'] as $item)
        <li class="in-card" style="background:var(--bg)">
          <span class="in-num">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
          <h3>{{ $item['title'] }}</h3>
          <p>{{ $item['text'] }}</p>
        </li>
      @endforeach
    </ol>
  </div>
</section>

<section aria-labelledby="in-losning" style="padding:clamp(56px,8vw,104px) 0">
  <div class="in-wrap">
    <p class="in-eyebrow">Så hjälper NONOX</p>
    <h2 id="in-losning" class="in-h2">{{ $industry['solutions']['heading'] }}</h2>
    <p class="in-lead">{{ $industry['solutions']['intro'] }}</p>
    <ul class="in-grid">
      @foreach($industry['solutions']['items'] as $item)
        <li class="in-card">
          <h3>{{ $item['title'] }}</h3>
          <p>{{ $item['text'] }}</p>
          @if(! empty($item['features']))
            <ul class="in-chips" aria-label="Funktioner">
              @foreach($item['features'] as $key)
                <li class="in-chip">{{ $features[$key]['name'] }}@isset($features[$key]['module'])<small>Modul</small>@endisset</li>
              @endforeach
            </ul>
          @endif
        </li>
      @endforeach
    </ul>
    @if($industry['limits'] !== [])
      <div style="margin-top:clamp(28px,4vw,40px);border-radius:20px;border:1px dashed var(--line);background:var(--card);padding:clamp(20px,3vw,28px)">
        <h3 style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:20px">Bra att veta innan du byter</h3>
        <ul class="in-limits">
          @foreach($industry['limits'] as $limit)
            <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" style="flex:none;margin-top:3px"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16.5h.01"></path></svg><span>{{ $limit['text'] }}</span></li>
          @endforeach
        </ul>
      </div>
    @endif
  </div>
</section>

@isset($industry['pos'])
<section aria-labelledby="in-kassa" style="padding:0 0 clamp(56px,8vw,104px)">
  <div class="in-wrap">
    <p class="in-eyebrow">Kassan</p>
    <h2 id="in-kassa" class="in-h2">{{ $industry['pos']['heading'] }}</h2>
    <p class="in-lead">{{ $industry['pos']['intro'] }}</p>
    <ol class="in-grid">
      @foreach($industry['pos']['items'] as $item)
        <li class="in-card">
          <span class="in-num">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
          <h3>{{ $item['title'] }}</h3>
          <p>{{ $item['text'] }}</p>
          @if(! empty($item['features']))
            <ul class="in-chips" aria-label="Funktioner">
              @foreach($item['features'] as $key)
                <li class="in-chip">{{ $features[$key]['name'] }}@isset($features[$key]['module'])<small>Modul</small>@endisset</li>
              @endforeach
            </ul>
          @endif
        </li>
      @endforeach
    </ol>
    <p class="in-lead" style="margin-top:clamp(20px,3vw,28px)">{{ $industry['pos']['tip'] }}</p>
  </div>
</section>
@endisset

<section id="pris" aria-labelledby="in-pris" style="scroll-margin-top:68px;padding:clamp(56px,8vw,104px) 0;background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="in-wrap">
    <p class="in-eyebrow">Vad det kostar</p>
    <h2 id="in-pris" class="in-h2">{{ $pricing['label'] }}: vad kostar bokföringen?</h2>
    <p class="in-lead">{{ $pricing['text'] }}@if($pricing['saving_from'] !== null && $pricing['saving_to'] > 0) Med NONOX blir det mellan {{ $kr($pricing['saving_from']) }} och {{ $kr($pricing['saving_to']) }} billigare per år än de jämförda programmen med verifierade listpriser.@endif</p>
    <div class="in-price-wrap">
      <table class="in-price">
        <caption class="cmp-sr">Pris per år för {{ mb_strtolower($pricing['label']) }}</caption>
        <thead>
          <tr><th scope="col">Program och paket</th><th scope="col">Så har vi räknat</th><th scope="col" style="text-align:right">Per år</th></tr>
        </thead>
        <tbody>
          <tr class="nonox">
            <th scope="row">NONOX<small>Vi sköter driften. Alla moduler ingår. 0 kr om du kör själv.</small></th>
            <td>Fast pris per företag och år, upp till {{ App\Support\Landing\LandingPage::PLANS[0]['max'] }} anställda.</td>
            <td class="num" data-price="nonox">{{ $pricing['nonox'] !== null ? $kr($pricing['nonox']) : 'Offert' }}</td>
          </tr>
          @foreach($pricing['quotes'] as $quote)
            <tr>
              <th scope="row">{{ $quote['name'] }}@unless($quote['verified'])<span class="in-flag">ej verifierat</span>@endunless @unless($quote['comparable'])<span class="in-flag">inte direkt jämförbart</span>@endunless<small>{{ $quote['plan'] }}</small></th>
              <td>{{ $quote['formula'] }}</td>
              <td class="num" data-price="{{ $quote['slug'] }}">{{ $quote['per_year'] !== null ? $kr($quote['per_year']) : 'Anges ej' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <details class="in-sources">
      <summary>Källor och hur vi räknat</summary>
      <p>Listpriser exklusive moms, hämtade från leverantörernas webbplatser {{ $pricing['fetched_at'] }} (svensk tid), samma källor som i <a href="{{ url('/') }}#jamfor">jämförelsen på startsidan</a>. Vi räknar med grundavgiften och avgiften per anställd som prissidan anger för det jämförda paketet, och samma paket oavsett antal verifikationer, användare och tillval. Priser ändras; kontrollera alltid hos leverantören.</p>
      <ul>
        @foreach($pricing['quotes'] as $quote)
          <li><strong>{{ $quote['name'] }}:</strong> {{ $quote['price_note'] }}@if($quote['source']) <a href="{{ $quote['source'] }}" rel="nofollow noopener" target="_blank">Källa</a>@endif</li>
        @endforeach
      </ul>
    </details>

    @if($comparisonRows !== [])
      <h3 style="margin:clamp(40px,5vw,56px) 0 0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:22px">Det som spelar roll för {{ mb_strtolower($industry['name']) }}</h3>
      <div class="in-price-wrap" style="margin-top:16px">
        <table class="in-price">
          <caption class="cmp-sr">Funktioner som spelar roll för {{ mb_strtolower($industry['name']) }}</caption>
          <thead>
            <tr><th scope="col">Funktion</th>@foreach($comparisonPrograms as $program)<th scope="col" @class(['nonox' => $program['slug'] === 'nonox'])>{{ $program['name'] }}</th>@endforeach</tr>
          </thead>
          <tbody>
            @foreach($comparisonRows as $row)
              <tr>
                <th scope="row">{{ $row['name'] }}</th>
                @foreach($comparisonPrograms as $program)
                  <td @class(['nonox' => $program['slug'] === 'nonox']) style="font-size:13.5px">{{ $comparisonLegend[$row['values'][$program['slug']] ?? 'unknown'] }}</td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p style="margin:12px 0 0;font-size:13.5px;color:var(--muted)">"Anges inte på prissidan" betyder att funktionen inte står på prissidan för det jämförda paketet, inte att den saknas.</p>
    @endif
    <nav aria-label="Jämför med andra program" style="margin-top:24px;display:flex;flex-wrap:wrap;gap:10px">
      <a href="{{ url('/') }}#jamfor" class="in-link">Hela jämförelsen →</a>
      @foreach(App\Support\Landing\Comparison::others() as $slug)
        <a href="{{ route('alternative', $slug) }}" class="in-link">Alternativ till {{ App\Support\Landing\Comparison::program($slug)['name'] ?? $slug }}</a>
      @endforeach
    </nav>
  </div>
</section>

<section aria-labelledby="in-flytt" style="padding:clamp(56px,8vw,104px) 0">
  <div class="in-wrap">
    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:24px">
      <div style="max-width:820px">
        <p class="in-eyebrow">Byt på en eftermiddag</p>
        <h2 id="in-flytt" class="in-h2">Flytta hit utan att tappa något.</h2>
        <p class="in-lead">Hela historiken följer med via SIE4, det svenska standardformatet för bokföring. Kommer du från Fortnox kan NONOX hämta räkenskapsåren, registren och underlagen direkt.</p>
      </div>
      <a href="{{ route('migrationGuide') }}" class="in-btn" style="border:1.5px solid var(--ink);color:var(--ink);font-weight:600">Läs flyttguiden →</a>
    </div>
    <ol style="list-style:none;margin:clamp(32px,4vw,48px) 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:24px 20px">
      @foreach($steps as $s)
        <li style="display:flex;gap:14px;align-items:flex-start">
          <span style="flex:none;width:40px;height:40px;border-radius:50%;border:2px solid var(--ink);background:{{ $s['bg'] }};color:{{ $s['fg'] }};display:grid;place-items:center;font-family:InterDisplay,Inter,sans-serif;font-weight:800">{{ $s['n'] }}</span>
          <p style="margin:0;font-size:16px;line-height:1.55">{{ $s['t'] }}</p>
        </li>
      @endforeach
    </ol>
  </div>
</section>

<section aria-labelledby="in-oppet" style="padding:clamp(56px,8vw,104px) 0;background:var(--dark);color:#F7F5F0">
  <div class="in-wrap" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:clamp(28px,5vw,64px);align-items:start">
    <div>
      <p class="in-eyebrow" style="color:#FF8A6D">Öppet och säkert</p>
      <h2 id="in-oppet" class="in-h2">Du kan läsa varje rad kod.</h2>
    </div>
    <div>
      <ul class="in-limits" style="margin:0">
        <li style="color:#F7F5F0">{!! $check !!}<span>NONOX är öppen källkod under AGPL-3.0. Du kan granska exakt hur siffrorna hanteras.</span></li>
        <li style="color:#F7F5F0">{!! $check !!}<span>En bokförd verifikation kan aldrig ändras, och en kedja av kontrollsummor kontrolleras varje natt.</span></li>
        <li style="color:#F7F5F0">{!! $check !!}<span>Vår senaste egna säkerhetsgranskning hittade {{ $securityTotals['found'] }} brister, varav {{ $securityTotals['fixed'] }} är åtgärdade. Allt redovisas öppet, även det som återstår.</span></li>
        <li style="color:#F7F5F0">{!! $check !!}<span>Kör NONOX på egen server gratis, eller låt oss sköta driften på servrar i Sverige/EU.</span></li>
      </ul>
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:24px">
        <a href="{{ route('security') }}" class="in-btn lp13" style="border:1.5px solid #F7F5F0;color:#F7F5F0;font-weight:600;min-height:46px;font-size:15px">Säkerhet och regelverk</a>
        <a href="{{ route('legal.selfHosting') }}" class="in-btn lp13" style="border:1.5px solid #F7F5F0;color:#F7F5F0;font-weight:600;min-height:46px;font-size:15px">Egen drift</a>
      </div>
    </div>
  </div>
</section>

<section aria-labelledby="in-faq" style="padding:clamp(56px,8vw,104px) 0">
  <div class="in-wrap" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:clamp(28px,5vw,72px);align-items:start">
    <h2 id="in-faq" class="in-h2">Vanliga frågor</h2>
    <div style="grid-column:span 2;min-width:0;max-width:100%">
      @foreach($industry['faqs'] as $faq)
        <details style="border-bottom:1px solid var(--line)">
          <summary style="display:flex;align-items:center;justify-content:space-between;gap:20px;padding:20px 0;cursor:pointer;font-size:clamp(17px,1.7vw,19px);font-weight:650">{{ $faq['q'] }}<span class="faq-plus" aria-hidden="true" style="flex:none;width:34px;height:34px;border-radius:50%;border:1px solid var(--line);display:grid;place-items:center;transition:transform .25s ease"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 4v16M4 12h16"></path></svg></span></summary>
          <p style="margin:0;padding:0 clamp(0px,5vw,56px) 22px 0;font-size:16.5px;line-height:1.6;color:var(--muted)">{{ $faq['a'] }}</p>
        </details>
      @endforeach
    </div>
  </div>
</section>

@if($related !== [])
  <section aria-labelledby="in-fler" style="padding:0 0 clamp(64px,9vw,112px)">
    <div class="in-wrap" style="border-top:1px solid var(--line);padding-top:clamp(48px,7vw,80px)">
      <h2 id="in-fler" class="in-h2" style="font-size:clamp(26px,3.4vw,40px)">Fler branscher</h2>
      <ul class="in-grid" style="margin-top:28px">
        @foreach($related as $other)
          <li>
            <a href="{{ route('industry', $other['slug']) }}" class="in-card lp11" style="text-decoration:none;color:var(--ink);transition:transform .2s,box-shadow .2s;height:100%">
              <span style="display:flex;align-items:center;gap:12px"><span class="mm-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $other['icon'] }}"></path></svg></span><h3>{{ $other['name'] }}</h3></span>
              <p>{{ $other['menu_text'] }}</p>
            </a>
          </li>
        @endforeach
      </ul>
      <a href="{{ route('industries') }}" class="in-link" style="margin-top:20px">Alla branscher →</a>
    </div>
  </section>
@endif

<section aria-labelledby="in-cta" style="padding:clamp(72px,10vw,128px) clamp(20px,4vw,40px);background:#FF5A36;color:#15171C;text-align:center">
  <h2 id="in-cta" style="margin:0 auto;max-width:900px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,6vw,80px);line-height:0.98;letter-spacing:-0.05em;text-wrap:balance">Ta tillbaka din bokföring.</h2>
  <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin-top:36px">
    <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="in-btn" style="background:#15171C;color:#F7F5F0">{{ auth()->check() ? 'Till översikten' : 'Kom igång gratis' }}</a>
    <a href="{{ route('migrationGuide') }}" class="in-btn" style="border:2px solid #15171C;color:#15171C">Flyttguiden</a>
  </div>
</section>

</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
</body>
</html>
