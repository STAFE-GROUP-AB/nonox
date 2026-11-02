{{--
    The public security and compliance page (/sakerhet, SecurityPageController):
    the latest review, how it was done, and a grade per area with what is
    done and what is still open. The facts come from docs/sakerhet/granskning.json.
--}}
@php
    $grade = [
        'A' => ['bg' => '#DCF5E6', 'fg' => '#0F5132'],
        'B' => ['bg' => '#E3F1F7', 'fg' => '#0B4A63'],
        'C' => ['bg' => '#FFF1CC', 'fg' => '#6B4A00'],
        'D' => ['bg' => '#FFE1D6', 'fg' => '#8A2A0F'],
        'E' => ['bg' => '#ECEAE5', 'fg' => '#3F4148'],
    ];
    $severity = ['high' => 'Höga', 'medium' => 'Medel', 'low' => 'Låga'];
    $reviewed = \Carbon\CarbonImmutable::parse($review['reviewed_at'])->locale('sv')->isoFormat('D MMMM YYYY');
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.sec h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(38px,6vw,72px);line-height:.98;letter-spacing:-0.04em;text-wrap:balance}
.sec h2{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(26px,3.4vw,38px);letter-spacing:-0.03em}
.sec h3{margin:0;font-size:18px;font-weight:700;letter-spacing:-0.01em}
.sec p{line-height:1.6}
.sec-lead{font-size:clamp(17px,2vw,20px);color:var(--muted);max-width:760px}
.sec-card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:clamp(20px,3vw,28px)}
.sec-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr));gap:18px;margin-top:22px}
.sec-grade{flex:none;display:grid;place-items:center;width:52px;height:52px;border-radius:14px;font:800 26px InterDisplay,Inter,sans-serif}
.sec-list{list-style:none;margin:14px 0 0;padding:0;display:grid;gap:8px;font-size:15px;line-height:1.5}
.sec-list li{display:grid;grid-template-columns:20px 1fr;gap:8px;color:var(--muted)}
.sec-law{margin-top:14px;font-size:13px;color:var(--muted)}
.sec-stat{font:800 clamp(34px,5vw,52px)/1 InterDisplay,Inter,sans-serif;letter-spacing:-0.03em}
.sec-note{border-left:4px solid var(--acc);background:var(--accl);border-radius:12px;padding:16px 18px;color:var(--ink)}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')
<main id="main" class="sec" style="max-width:1240px;margin:0 auto;padding:clamp(48px,8vw,96px) clamp(20px,4vw,40px)">

  <p style="margin:0 0 14px;font-family:ui-monospace,Menlo,monospace;font-size:13px;color:var(--acct)">Senast granskat {{ $reviewed }} · version {{ $review['version'] }}</p>
  <h1>Säkerhet och regelverk, öppet redovisat.</h1>
  <p class="sec-lead" style="margin:22px 0 0">Öppen källkod får inte betyda osäkert. Här visar vi hur NONOX står sig, område för område, med det som är klart och det som återstår. Betygen ändras när koden ändras, och historiken finns i koden.</p>

  <div class="sec-note" style="margin-top:28px;max-width:860px">
    <strong>{{ $review['kind'] }}, inte ett oberoende intyg.</strong>
    {{ $review['independent_note'] }}
  </div>
  <p style="margin:16px 0 0;max-width:860px;color:var(--muted);font-size:15px">Granskningen gäller programmets källkod och, för området Drift och säkerhetskopior, den driftade tjänsten på nonox.app. Kör du NONOX själv ansvarar du för driften, se <a href="{{ route('legal.selfHosting') }}">NONOX i egen drift</a>.</p>

  <section aria-labelledby="sec-resultat" style="margin-top:clamp(48px,7vw,80px)">
    <h2 id="sec-resultat">Fynd och rättelser</h2>
    <p style="color:var(--muted);max-width:760px">{{ $review['method'] }}</p>
    <div class="sec-grid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr))">
      <div class="sec-card">
        <div class="sec-stat">{{ $totals['fixed'] }} av {{ $totals['found'] }}</div>
        <p style="margin:8px 0 0;color:var(--muted)">fynd rättade</p>
      </div>
      @foreach($severity as $key => $label)
        @php($f = $review['findings'][$key] ?? ['found' => 0, 'fixed' => 0])
        <div class="sec-card">
          <div class="sec-stat">{{ $f['fixed'] }} / {{ $f['found'] }}</div>
          <p style="margin:8px 0 0;color:var(--muted)">{{ $label }} fynd rättade</p>
        </div>
      @endforeach
    </div>
  </section>

  @foreach([['id' => 'sec-sakerhet', 'title' => 'Säkerhet', 'areas' => $security], ['id' => 'sec-regelverk', 'title' => 'Lagar och regelverk', 'areas' => $regulations]] as $part)
    <section aria-labelledby="{{ $part['id'] }}" style="margin-top:clamp(48px,7vw,80px)">
      <h2 id="{{ $part['id'] }}">{{ $part['title'] }}</h2>
      <div class="sec-grid">
        @foreach($part['areas'] as $area)
          <article class="sec-card">
            <div style="display:flex;gap:14px;align-items:flex-start">
              <span class="sec-grade" style="background:{{ $grade[$area['grade']]['bg'] }};color:{{ $grade[$area['grade']]['fg'] }}" aria-label="Betyg {{ $area['grade'] }}: {{ $review['scale'][$area['grade']] }}">{{ $area['grade'] }}</span>
              <div>
                <h3>{{ $area['name'] }}</h3>
                <p style="margin:6px 0 0;font-size:15px;color:var(--muted)">{{ $area['summary'] }}</p>
              </div>
            </div>
            @if($area['done'] !== [])
              <ul class="sec-list" aria-label="Klart">
                @foreach($area['done'] as $item)
                  <li><span aria-hidden="true" style="color:#0F7A3E;font-weight:700">✓</span><span>{{ $item }}</span></li>
                @endforeach
              </ul>
            @endif
            @if($area['open'] !== [])
              <ul class="sec-list" aria-label="Återstår">
                @foreach($area['open'] as $item)
                  <li><span aria-hidden="true" style="color:var(--acct);font-weight:700">○</span><span><span class="sr-only" style="position:absolute;left:-9999px">Återstår: </span>{{ $item }}</span></li>
                @endforeach
              </ul>
            @endif
            @if($area['law'] !== [])
              <p class="sec-law">{{ implode(' · ', $area['law']) }}</p>
            @endif
          </article>
        @endforeach
      </div>
    </section>
  @endforeach

  <section aria-labelledby="sec-skala" style="margin-top:clamp(48px,7vw,80px)">
    <h2 id="sec-skala">Så sätts betygen</h2>
    <ul class="sec-list" style="max-width:760px">
      @foreach($review['scale'] as $letter => $meaning)
        <li style="grid-template-columns:40px 1fr;align-items:center"><span class="sec-grade" style="width:32px;height:32px;font-size:16px;border-radius:9px;background:{{ $grade[$letter]['bg'] }};color:{{ $grade[$letter]['fg'] }}">{{ $letter }}</span><span>{{ $meaning }}</span></li>
      @endforeach
    </ul>
  </section>

  <section aria-labelledby="sec-rapportera" style="margin-top:clamp(48px,7vw,80px)" class="sec-card">
    <h2 id="sec-rapportera">Hittat en sårbarhet?</h2>
    <p style="color:var(--muted);max-width:760px">Hör av dig innan du publicerar något, så rättar vi det och berättar öppet om det här. Du som granskar i god tro behöver inte vara orolig för rättsliga följder. @if($securityEmail !== '')Skriv till <a href="mailto:{{ $securityEmail }}">{{ $securityEmail }}</a>.@else Rapportera det privat via projektets säkerhetsrapportering i källkodsförrådet.@endif</p>
  </section>
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
</body>
</html>
