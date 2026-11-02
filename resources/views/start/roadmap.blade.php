{{--
    The public roadmap (/fardplan, RoadmapController): what is being built,
    what is planned and the wishes from docs/fardplan/fardplan.json, and the
    newest shipped features from the changelog under "Klart nyligen".
--}}
@use('App\Support\Landing\Changelog')
@php
    $intros = [
        'Pågår' => 'Det vi arbetar med just nu.',
        'Planerat' => 'Bestämt, men inte påbörjat.',
        'Önskemål' => 'Det kunder och partner har bett om och som vi ännu inte har bestämt.',
    ];
    $ids = ['Pågår' => 'pagar', 'Planerat' => 'planerat', 'Önskemål' => 'onskemal'];
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.rm-wrap{max-width:1240px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
.rm-crumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px;font-size:14px;color:var(--muted)}
.rm-crumbs li+li::before{content:"/";margin-right:6px}
.rm-crumbs a{color:var(--muted)}
.rm-h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,6.4vw,84px);line-height:.96;letter-spacing:-0.045em;text-wrap:balance}
.rm-lead{margin:24px 0 0;max-width:760px;font-size:clamp(17px,1.8vw,20px);line-height:1.55;color:var(--muted);text-wrap:pretty}
.rm-note{margin:22px 0 0;max-width:760px;border-left:4px solid var(--acc);background:var(--accl);border-radius:12px;padding:16px 18px;font-size:16px;line-height:1.55;color:var(--ink)}
.rm-board{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-top:clamp(40px,5vw,64px);align-items:start}
@media (max-width:1099px){.rm-board{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:639px){.rm-board{grid-template-columns:1fr}}
.rm-col{background:var(--soft);border-radius:22px;padding:18px}
.rm-col h2{margin:0;display:flex;align-items:center;gap:10px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:22px;letter-spacing:-0.02em}
.rm-col h2 .rm-n{display:inline-grid;place-items:center;min-width:28px;height:28px;padding:0 8px;border-radius:999px;background:var(--card);font:700 13px Inter,system-ui,sans-serif;color:var(--muted)}
.rm-col > p{margin:6px 0 14px;font-size:14px;line-height:1.5;color:var(--muted)}
.rm-list{list-style:none;margin:0;padding:0;display:grid;gap:12px}
.rm-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 16px 14px}
.rm-card h3{margin:0;font-size:16.5px;font-weight:700;line-height:1.3;letter-spacing:-0.01em}
.rm-card p{margin:6px 0 0;font-size:14.5px;line-height:1.55;color:var(--muted)}
.rm-card .rm-comment{margin-top:10px;padding-top:10px;border-top:1px dashed var(--line);font-size:13.5px;color:var(--ink)}
.rm-foot{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;margin-top:12px;font-size:12.5px;color:var(--muted)}
.rm-tag{display:inline-flex;align-items:center;padding:2px 9px;border-radius:999px;background:var(--accl);color:var(--acct);font-weight:700}
.rm-status{font-weight:600}
.rm-done .rm-status{color:var(--ink)}
.rm-col a.rm-more{display:inline-block;margin-top:14px;font-size:14px;font-weight:700;color:var(--acct)}
.rm-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">

@include('start.partials.public-header')

<main id="main">
<section aria-labelledby="rm-rubrik" style="padding:clamp(28px,4vw,40px) 0 clamp(64px,9vw,120px)">
  <div class="rm-wrap">
    <nav aria-label="Brödsmulor" class="rm-crumbs">
      <ol>
        @foreach($breadcrumbs as $crumb)
          <li>@if($loop->last)<span aria-current="page">{{ $crumb['name'] }}</span>@else<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@endif</li>
        @endforeach
      </ol>
    </nav>
    <p style="margin:clamp(36px,6vw,72px) 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Färdplan</p>
    <h1 id="rm-rubrik" class="rm-h1">Det här bygger vi <span style="color:var(--accb)">härnäst.</span></h1>
    <p class="rm-lead">Färdplanen visar det som pågår, det som är planerat och de önskemål vi har fått. Felrättningar och små justeringar står inte här; de syns i <a href="{{ route('changelog') }}">ändringsloggen</a> när de är gjorda. När något blir klart flyttar det till Klart nyligen.</p>
    <p class="rm-note">Önskemål läggs in av oss när de kommer in från kunder och partner. Saknar du något i NONOX? Säg till via <a href="{{ $ph['contact'] }}">kontaktsidan</a>, så tar vi med det här.</p>

    <div class="rm-board">
      @foreach($columns as $status => $items)
        <section class="rm-col" aria-labelledby="kolumn-{{ $ids[$status] }}">
          <h2 id="kolumn-{{ $ids[$status] }}">{{ $status }} <span class="rm-n"><span class="rm-sr">Antal: </span>{{ count($items) }}</span></h2>
          <p>{{ $intros[$status] }}</p>
          @if($items === [])
            <p style="margin:0">Inget just nu.</p>
          @else
            <ul class="rm-list">
              @foreach($items as $item)
                @php($updated = \Carbon\CarbonImmutable::parse($item['uppdaterad'])->locale('sv'))
                <li id="{{ $item['id'] }}">
                  <article class="rm-card" aria-labelledby="{{ $item['id'] }}-rubrik">
                    <h3 id="{{ $item['id'] }}-rubrik">{{ $item['rubrik'] }}</h3>
                    <p>{{ $item['beskrivning'] }}</p>
                    @isset($item['kommentar'])<p class="rm-comment">{{ $item['kommentar'] }}</p>@endisset
                    <div class="rm-foot">
                      <span class="rm-tag">{{ $item['omrade'] }}</span>
                      <span class="rm-status"><span class="rm-sr">Status: </span>{{ $item['status'] }}</span>
                      <span>Uppdaterad <time datetime="{{ $updated->format('Y-m-d') }}">{{ $updated->isoFormat('D MMM YYYY') }}</time></span>
                    </div>
                  </article>
                </li>
              @endforeach
            </ul>
          @endif
        </section>
      @endforeach

      <section class="rm-col rm-done" aria-labelledby="kolumn-klart">
        <h2 id="kolumn-klart">Klart nyligen <span class="rm-n"><span class="rm-sr">Antal: </span>{{ count($recent) }}</span></h2>
        <p>Det senaste som blivit klart, direkt från ändringsloggen.</p>
        <ul class="rm-list">
          @foreach($recent as $entry)
            @php($date = Changelog::date($entry)->locale('sv'))
            <li>
              <article class="rm-card" aria-labelledby="klart-{{ $entry['id'] }}">
                <h3 id="klart-{{ $entry['id'] }}"><a href="{{ route('changelog') }}#{{ $entry['id'] }}" style="text-decoration:none">{{ $entry['rubrik'] }}</a></h3>
                <p>{{ $entry['beskrivning'] }}</p>
                <div class="rm-foot">
                  <span class="rm-tag">{{ $entry['omrade'] }}</span>
                  <span class="rm-status"><span class="rm-sr">Status: </span>Klart</span>
                  <span><time datetime="{{ $date->format('c') }}">{{ $date->isoFormat('D MMM YYYY') }}</time></span>
                </div>
              </article>
            </li>
          @endforeach
        </ul>
        <a href="{{ route('changelog') }}" class="rm-more">Hela ändringsloggen →</a>
      </section>
    </div>
  </div>
</section>
</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
</body>
</html>
