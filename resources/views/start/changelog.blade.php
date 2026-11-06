{{--
    The public changelog (/andringslogg, ChangelogController::index): every
    shipped change from docs/andringslogg/andringslogg.json, newest first and
    grouped by the project's phases (with month headings only where a phase
    has entries). The filters are a plain GET form and links, so the page
    works without JavaScript. Work outside the code (an entry with a `period`)
    shows its period and a note, never its datum, which is only for ordering.
--}}
@use('App\Support\Landing\Changelog')
@php
    // The page's own address with one filter changed and the others kept.
    $query = function (array $change) use ($type, $area, $search): string {
        $params = array_filter([...['typ' => $type, 'omrade' => $area, 'q' => $search], ...$change], fn ($value): bool => $value !== null && $value !== '');

        return route('changelog').($params === [] ? '' : '?'.http_build_query($params));
    };
    $typeClass = ['Nytt' => 'cl-nytt', 'Förbättrat' => 'cl-forbattrat', 'Rättat' => 'cl-rattat', 'Säkerhet' => 'cl-sakerhet', 'Utredning' => 'cl-utredning'];
    $day = fn (string $date): string => \Carbon\CarbonImmutable::parse($date, Changelog::TIMEZONE)->locale('sv')->isoFormat('D MMMM YYYY');
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<link rel="alternate" type="application/rss+xml" title="NONOX ändringslogg" href="{{ route('changelog.feed') }}">
<style>
:root{--n-bg:#DCF5E6;--n-fg:#0F5132;--f-bg:#E3F1F7;--f-fg:#0B4A63;--r-bg:#FFF1CC;--r-fg:#6B4A00;--s-bg:#FFE1D6;--s-fg:#8A2A0F;--u-bg:#ECE7FA;--u-fg:#46307F}
@media (prefers-color-scheme:dark){:root{--n-bg:#143524;--n-fg:#8FE0B0;--f-bg:#12303D;--f-fg:#8FD0EA;--r-bg:#3A2E0C;--r-fg:#F5D27A;--s-bg:#3A231D;--s-fg:#FF9F85;--u-bg:#2A2342;--u-fg:#CDBDFF}}
[data-theme=light]{--n-bg:#DCF5E6;--n-fg:#0F5132;--f-bg:#E3F1F7;--f-fg:#0B4A63;--r-bg:#FFF1CC;--r-fg:#6B4A00;--s-bg:#FFE1D6;--s-fg:#8A2A0F;--u-bg:#ECE7FA;--u-fg:#46307F}
[data-theme=dark]{--n-bg:#143524;--n-fg:#8FE0B0;--f-bg:#12303D;--f-fg:#8FD0EA;--r-bg:#3A2E0C;--r-fg:#F5D27A;--s-bg:#3A231D;--s-fg:#FF9F85;--u-bg:#2A2342;--u-fg:#CDBDFF}
.cl-wrap{max-width:1040px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
.cl-crumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px;font-size:14px;color:var(--muted)}
.cl-crumbs li+li::before{content:"/";margin-right:6px}
.cl-crumbs a{color:var(--muted)}
.cl-h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,6.4vw,84px);line-height:.96;letter-spacing:-0.045em;text-wrap:balance}
.cl-lead{margin:24px 0 0;max-width:720px;font-size:clamp(17px,1.8vw,20px);line-height:1.55;color:var(--muted);text-wrap:pretty}
.cl-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:28px}
.cl-filters{margin-top:clamp(36px,5vw,56px);padding:clamp(18px,2.4vw,24px);border:1px solid var(--line);border-radius:22px;background:var(--card);display:grid;gap:16px}
.cl-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none}
.cl-chip{display:inline-flex;align-items:center;min-height:40px;padding:0 16px;border-radius:999px;border:1px solid var(--line);background:var(--bg);text-decoration:none;font-size:14.5px;font-weight:600;color:var(--ink)}
.cl-chip:hover{border-color:var(--ink);color:var(--ink)}
.cl-chip[aria-current=true]{background:var(--ink);border-color:var(--ink);color:var(--bg)}
.cl-form{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) auto;gap:12px;align-items:end}
@media (max-width:719px){.cl-form{grid-template-columns:1fr}}
.cl-field{display:flex;flex-direction:column;gap:6px;font-size:14px;font-weight:600}
.cl-field input,.cl-field select{min-height:46px;padding:0 14px;border-radius:12px;border:1px solid rgba(21,23,28,.35);background:var(--bg);color:var(--ink);font:500 16px Inter,system-ui,sans-serif}
@media (prefers-color-scheme:dark){.cl-field input,.cl-field select{border-color:rgba(255,255,255,.35)}}
[data-theme=dark] .cl-field input,[data-theme=dark] .cl-field select{border-color:rgba(255,255,255,.35)}
.cl-btn{min-height:46px;padding:0 22px;border:0;border-radius:999px;background:#FF5A36;color:#15171C;font:700 15px Inter,system-ui,sans-serif;cursor:pointer}
.cl-btn:hover{background:#FF7454}
.cl-count{margin:0;font-size:14.5px;color:var(--muted)}
.cl-month{position:sticky;top:68px;z-index:2;margin:0;padding:18px 0 12px;background:var(--bg);border-bottom:1px solid var(--line);font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(22px,2.6vw,30px);letter-spacing:-0.025em}
.cl-month span{font-family:Inter,system-ui,sans-serif;font-weight:500;font-size:15px;letter-spacing:0;color:var(--muted)}
.cl-list{list-style:none;margin:0;padding:0}
.cl-entry{display:grid;grid-template-columns:170px minmax(0,1fr);gap:8px 28px;padding:24px 0;border-bottom:1px solid var(--line);scroll-margin-top:140px}
@media (max-width:719px){.cl-entry{grid-template-columns:1fr}}
.cl-entry:target{background:var(--accl);border-radius:16px;padding-left:14px;padding-right:14px}
.cl-time{font-size:14px;line-height:1.5;color:var(--muted);font-variant-numeric:tabular-nums}
.cl-time strong{display:block;color:var(--ink);font-weight:700}
.cl-outside{display:inline-block;margin-top:6px;padding:2px 9px;border:1px solid var(--line);border-radius:999px;font-size:12.5px;font-weight:600;color:var(--muted)}
.cl-tags{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:8px}
.cl-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:12.5px;font-weight:700}
.cl-nytt{background:var(--n-bg);color:var(--n-fg)}
.cl-forbattrat{background:var(--f-bg);color:var(--f-fg)}
.cl-rattat{background:var(--r-bg);color:var(--r-fg)}
.cl-sakerhet{background:var(--s-bg);color:var(--s-fg)}
.cl-utredning{background:var(--u-bg);color:var(--u-fg)}
.cl-background{margin-top:clamp(28px,4vw,40px);padding:clamp(20px,2.6vw,28px);border-left:4px solid var(--accb);border-radius:0 18px 18px 0;background:var(--card);max-width:820px}
.cl-background h2{margin:0;font-size:18px;font-weight:700;letter-spacing:-0.01em}
.cl-background p{margin:8px 0 0;font-size:16px;line-height:1.6;color:var(--muted)}
.cl-phase{margin-top:clamp(40px,5vw,64px)}
.cl-phase-head{padding-bottom:16px;border-bottom:2px solid var(--ink)}
.cl-phase-kicker{margin:0;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--acct)}
.cl-phase-name{margin:6px 0 0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(28px,3.6vw,44px);letter-spacing:-0.035em;line-height:1.05}
.cl-phase-head p{margin:10px 0 0;max-width:720px;font-size:16px;line-height:1.6;color:var(--muted)}
.cl-goal{padding:clamp(20px,2.6vw,28px);border:1px dashed var(--line);border-radius:22px}
.cl-goal .cl-phase-head{border-bottom:0;padding-bottom:0}
.cl-area{font-size:13px;font-weight:600;color:var(--muted);text-decoration:underline;text-underline-offset:3px;text-decoration-color:var(--line)}
.cl-area:hover{color:var(--acct)}
.cl-entry h4{margin:0;font-size:19px;font-weight:700;letter-spacing:-0.015em;line-height:1.3}
.cl-entry p{margin:6px 0 0;font-size:16px;line-height:1.6;color:var(--muted);max-width:680px}
.cl-ver{display:inline-block;margin-top:10px;font-family:ui-monospace,Menlo,monospace;font-size:12px;color:var(--muted)}
.cl-empty{margin-top:32px;padding:32px;border:1px dashed var(--line);border-radius:22px;text-align:center;color:var(--muted)}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">

@include('start.partials.public-header')

<main id="main">
<section aria-labelledby="cl-rubrik" style="padding:clamp(28px,4vw,40px) 0 clamp(64px,9vw,120px)">
  <div class="cl-wrap">
    <nav aria-label="Brödsmulor" class="cl-crumbs">
      <ol>
        @foreach($breadcrumbs as $crumb)
          <li>@if($loop->last)<span aria-current="page">{{ $crumb['name'] }}</span>@else<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@endif</li>
        @endforeach
      </ol>
    </nav>
    <p style="margin:clamp(36px,6vw,72px) 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Ändringslogg</p>
    <h1 id="cl-rubrik" class="cl-h1">Allt vi har byggt, <span style="color:var(--accb)">öppet redovisat.</span></h1>
    <p class="cl-lead">Här står varje ny funktion, förbättring och rättelse i NONOX, och de utredningar och granskningar som ligger bakom, med datum och klockslag. Listan tas fram ur det vi faktiskt levererar: varje ändring som når programmet får en rad här, skriven på vanlig svenska. Du behöver inte kunna läsa kod för att följa med.</p>
    <aside class="cl-background" aria-labelledby="cl-bakgrund">
      <h2 id="cl-bakgrund">Bakgrund</h2>
      <p>NONOX började i januari 2026 med den grundläggande arkitekturen och en funktionsspecifikation. Mellan februari och september 2026 pågick förberedelserna utanför koden: samtal med företag, funktionslistan, arbetssättet och webbplatsens innehåll. Från oktober 2026 har systemet byggts i full skala, med AI-stödd utveckling under mänsklig ledning.</p>
      <p>Specifikationerna skrivs utifrån lagtexten, BAS, SIE och Skatteverkets dokumentation. Varje lagregel finns som data med en källa, och arbetet går igenom juridisk genomgång, oberoende kontroller och tusentals automatiska tester. Loggen nedan visar varje leverans sedan dess.</p>
      <p>NONOX är i öppen beta. Målet är version 1.0 den 1 februari 2027.</p>
    </aside>
    <div class="cl-meta">
      <a href="{{ route('roadmap') }}" class="cmp-link">Se färdplanen</a>
      <a href="{{ route('changelog.feed') }}" class="cmp-link">Följ med RSS</a>
      <a href="{{ route('security') }}" class="cmp-link">Säkerhet och regelverk</a>
    </div>

    <div class="cl-filters">
      <nav aria-label="Typ av ändring">
        <ul class="cl-chips">
          <li><a href="{{ $query(['typ' => null]) }}" class="cl-chip"{!! $type === null ? ' aria-current="true"' : '' !!}>Alla typer</a></li>
          @foreach(Changelog::TYPES as $slug => $label)
            <li><a href="{{ $query(['typ' => $slug]) }}" class="cl-chip"{!! $type === $slug ? ' aria-current="true"' : '' !!}>{{ $label }}</a></li>
          @endforeach
        </ul>
      </nav>
      <form method="get" action="{{ route('changelog') }}" role="search" aria-label="Sök i ändringsloggen" class="cl-form">
        @if($type !== null)<input type="hidden" name="typ" value="{{ $type }}">@endif
        <label class="cl-field">Sök
          <input type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Till exempel moms eller lön" autocomplete="off">
        </label>
        <label class="cl-field">Område
          <select name="omrade">
            <option value="">Alla områden</option>
            @foreach(Changelog::AREAS as $slug => $label)
              <option value="{{ $slug }}" @selected($area === $slug)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
        <button type="submit" class="cl-btn">Visa</button>
      </form>
      <p class="cl-count" role="status">
        @if($filtered)
          Visar {{ $shown }} av {{ $total }} ändringar. <a href="{{ route('changelog') }}">Visa alla</a>
        @else
          {{ $total }} ändringar{{ $first ? ' sedan '.$first->locale('sv')->isoFormat('MMMM YYYY') : '' }}.
        @endif
      </p>
    </div>

    @if($shown === 0)
      <div class="cl-empty">
        <p style="margin:0;font-size:17px;color:var(--ink);font-weight:600">Inga ändringar matchar.</p>
        <p style="margin:8px 0 0">Prova ett annat ord eller ett annat område. <a href="{{ route('changelog') }}">Visa alla ändringar</a></p>
      </div>
    @endif

    @foreach($phases as $group)
      @php($phase = $group['phase'])
      @if($phase['kommande'])
      <section aria-labelledby="fas-{{ $phase['id'] }}" class="cl-phase cl-goal">
        <div class="cl-phase-head">
          <p class="cl-phase-kicker">Mål · {{ $day($phase['fran']) }}</p>
          <h2 id="fas-{{ $phase['id'] }}" class="cl-phase-name">{{ $phase['namn'] }}</h2>
          <p>{{ $phase['beskrivning'] }}</p>
        </div>
      </section>
      @else
      <section aria-labelledby="fas-{{ $phase['id'] }}" class="cl-phase">
        <div class="cl-phase-head">
          <p class="cl-phase-kicker">
            @if($phase['till'] === null)
              Sedan {{ $day($phase['fran']) }}
            @elseif($phase['till'] === $phase['fran'])
              {{ $day($phase['fran']) }}
            @else
              {{ $day($phase['fran']) }} – {{ $day($phase['till']) }}
            @endif
            · {{ $group['count'] }} {{ $group['count'] === 1 ? 'ändring' : 'ändringar' }}
          </p>
          <h2 id="fas-{{ $phase['id'] }}" class="cl-phase-name">{{ $phase['namn'] }}</h2>
          <p>{{ $phase['beskrivning'] }}</p>
        </div>
      @foreach($group['months'] as $key => $month)
      <section aria-labelledby="manad-{{ $key }}" style="margin-top:clamp(20px,3vw,32px)">
        <h3 id="manad-{{ $key }}" class="cl-month">{{ $month['label'] }} <span>· {{ count($month['entries']) }} {{ count($month['entries']) === 1 ? 'ändring' : 'ändringar' }}</span></h3>
        <ol class="cl-list">
          @foreach($month['entries'] as $entry)
            <li id="{{ $entry['id'] }}" class="cl-entry">
              <div class="cl-time">
                @if(Changelog::isPeriod($entry))
                  <strong>{{ ucfirst($entry['period']) }}</strong><span class="cl-outside">Arbete utanför koden</span>
                @else
                  @php($date = Changelog::date($entry))
                  <time datetime="{{ $date->format('c') }}"><strong>{{ $date->locale('sv')->isoFormat('D MMMM YYYY') }}</strong>kl. {{ $date->format('H:i') }}</time>
                @endif
              </div>
              <article aria-labelledby="{{ $entry['id'] }}-rubrik">
                <div class="cl-tags">
                  <span class="cl-badge {{ $typeClass[$entry['typ']] ?? '' }}">{{ $entry['typ'] }}</span>
                  <a href="{{ $query(['omrade' => Changelog::areaSlug($entry['omrade'])]) }}" class="cl-area" aria-label="Visa alla ändringar inom {{ $entry['omrade'] }}">{{ $entry['omrade'] }}</a>
                </div>
                <h4 id="{{ $entry['id'] }}-rubrik">{{ $entry['rubrik'] }}</h4>
                <p>{{ $entry['beskrivning'] }}</p>
                @isset($entry['version'])<span class="cl-ver">Version {{ $entry['version'] }}</span>@endisset
              </article>
            </li>
          @endforeach
        </ol>
      </section>
      @endforeach
      </section>
      @endif
    @endforeach
  </div>
</section>
</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
</body>
</html>
