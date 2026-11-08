{{--
    One moving guide per program (/flytta-fran/{program}, ADR-076): generated
    from docs/migration/flyttguider.json. Plain text only (ADR-020): no logos,
    colours or screenshots of other programs, no quoted help texts. Every step
    shows where it was read and when.
--}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<meta name="robots" content="noindex">
<style>
.fg-wrap{max-width:860px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
.fg-crumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px;font-size:14px;color:var(--muted)}
.fg-crumbs li+li::before{content:"/";margin-right:6px}
.fg-crumbs a{color:var(--muted)}
.fg-h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(34px,5vw,60px);line-height:1;letter-spacing:-0.04em;text-wrap:balance}
.fg-lead{margin:20px 0 0;font-size:clamp(17px,1.8vw,20px);line-height:1.55;color:var(--muted);text-wrap:pretty}
.fg-card{margin-top:22px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px 22px}
.fg-card h2{margin:0;font-size:20px;font-weight:700;letter-spacing:-0.01em}
.fg-card p,.fg-card li{font-size:16px;line-height:1.6}
.fg-src{margin:8px 0 0;font-size:13.5px;color:var(--muted)}
.fg-none{color:var(--muted)}
.fg-note{margin:26px 0 0;border-left:4px solid var(--acc);background:var(--accl);border-radius:12px;padding:16px 18px;font-size:16px;line-height:1.55}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')

<main id="main">
<section style="padding:clamp(28px,4vw,40px) 0 clamp(64px,9vw,120px)">
  <div class="fg-wrap">
    <nav aria-label="Brödsmulor" class="fg-crumbs">
      <ol>
        @foreach($breadcrumbs as $crumb)
          <li>@if($loop->last)<span aria-current="page">{{ $crumb['name'] }}</span>@else<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@endif</li>
        @endforeach
      </ol>
    </nav>
    <h1 class="fg-h1" style="margin-top:clamp(28px,5vw,56px)">Flytta från {{ $program['name'] }}</h1>
    <p class="fg-lead">{{ $program['intro'] }}</p>
    <p class="fg-note">Vi återger bara det som programmets egen leverantör beskriver offentligt, med källa och det datum vi läste sidan. Programmen ändras, så kontrollera alltid mot leverantörens egen hjälp. Senast kontrollerat: {{ $program['last_checked'] }}.</p>

    @foreach($program['capabilities'] as $capability)
      <section class="fg-card" aria-labelledby="f-{{ $capability['key'] }}">
        <h2 id="f-{{ $capability['key'] }}">{{ $capability['label'] }}</h2>
        @if($capability['documented'])
          <ol>
            @foreach($capability['steps'] as $step)
              <li>
                {{ $step['text'] }}
                <p class="fg-src">Enligt leverantörens egen hjälpsida, läst {{ $step['read_date'] }}: <a href="{{ $step['source_url'] }}" rel="noopener noreferrer">{{ parse_url($step['source_url'], PHP_URL_HOST) }}</a></p>
              </li>
            @endforeach
          </ol>
        @else
          <p class="fg-none">Dokumenteras inte offentligt av leverantören.</p>
          @if($capability['note'] !== '')
            <p>{{ $capability['note'] }}</p>
          @else
            <p>Fråga leverantörens support hur du tar ut det här. Har du en fil kan du ändå läsa in den i NONOX och koppla kolumnerna själv.</p>
          @endif
        @endif
      </section>
    @endforeach

    <p class="fg-note">Oavsett program: ta ut bokföringen som SIE4 för varje räkenskapsår, ta ut det programmet låter dig ta ut som Excel eller CSV, och läs in filerna i NONOX under Bokföring, Läs in SIE-fil och Läs in register. Kolumnerna kopplar du själv, och inget sparas förrän du har läst rapporten och godkänt. <a href="{{ route('migrationGuide') }}">Hela flyttguiden</a>.</p>
  </div>
</section>
</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
</body>
</html>
