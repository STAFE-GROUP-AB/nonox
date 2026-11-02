{{--
    "Alternativ till …" page (AlternativeController, ADR-020): NONOX next to
    one other program, with the price for five employees, the comparison
    table and how to move from that program.
--}}
@php
    $kr = fn (int $kronor): string => number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    $comparison = App\Support\Landing\Comparison::data();
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.al-wrap{max-width:1240px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
.al-eyebrow{margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)}
.al-h2{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(32px,4.6vw,56px);line-height:1;letter-spacing:-0.04em;text-wrap:balance}
.al-prices{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:14px;margin-top:44px}
.al-price{border-radius:22px;padding:26px;display:flex;flex-direction:column;gap:6px}
.al-price b{font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(32px,4vw,48px);letter-spacing:-0.035em;font-variant-numeric:tabular-nums}
.al-source{border-radius:24px;background:var(--card);border:1px solid var(--line);padding:clamp(24px,4vw,40px)}
.al-source p,.al-source li{font-size:16.5px;line-height:1.6;color:var(--muted)}
.al-source strong{color:var(--ink)}
.al-source ul{padding-left:20px;display:flex;flex-direction:column;gap:8px}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">

@include('start.partials.public-header')

<main id="main">

<section aria-labelledby="al-rubrik" style="padding:clamp(56px,9vw,112px) 0 clamp(40px,6vw,72px)">
  <div class="al-wrap">
    <p class="al-eyebrow">Alternativ till {{ $other['name'] }}</p>
    <h1 id="al-rubrik" style="margin:0;max-width:1000px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,7vw,92px);line-height:0.95;letter-spacing:-0.045em;text-wrap:balance">Ett gratis alternativ till <span style="color:var(--accb)">{{ $other['name'] }}.</span></h1>
    <p style="margin:24px 0 0;max-width:700px;font-size:clamp(17px,1.8vw,20px);line-height:1.55;color:var(--muted);text-wrap:pretty">NONOX är ett komplett svenskt bokföringsprogram med öppen källkod: bokföring, fakturering, lön, moms, bokslut och årsredovisning. Kör det själv helt gratis, eller låt oss sköta driften för 995 kr om året.</p>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:32px">
      <a href="{{ route('migrationGuide') }}" class="lp8" style="display:inline-flex;align-items:center;min-height:54px;padding:0 26px;border-radius:999px;background:#FF5A36;color:#15171C;text-decoration:none;font-size:16.5px;font-weight:700">Flytta från {{ $other['name'] }}</a>
      <a href="#jamfor" style="display:inline-flex;align-items:center;min-height:54px;padding:0 26px;border-radius:999px;border:1.5px solid var(--ink);color:var(--ink);text-decoration:none;font-size:16.5px;font-weight:600">Jämför funktionerna</a>
    </div>

    <div class="al-prices">
      <div class="al-price" style="background:var(--card);border:1px solid var(--line)">
        <span style="font-size:14px;font-weight:600;color:var(--muted)">{{ $other['name'] }}, fem anställda</span>
        <b style="color:var(--muted)">{{ $kr($other['per_year']) }}<span style="font-size:15px;font-weight:600;letter-spacing:0">/år</span></b>
        <span style="font-size:13px;color:var(--muted)">{{ $other['plan'] }}@unless($other['verified']) · ej verifierat hos leverantören @endunless</span>
      </div>
      <div class="al-price" style="background:var(--accl)">
        <span style="font-size:14px;font-weight:600">NONOX, fem anställda</span>
        <b style="color:var(--acct)">{{ $kr($nonox['per_year']) }}<span style="font-size:15px;font-weight:600;letter-spacing:0">/år</span></b>
        <span style="font-size:13px">Alla delar ingår. 0 kr om du kör själv.</span>
      </div>
      @if($saving > 0)
        <div class="al-price" style="background:#15171C;color:#F7F5F0">
          <span style="font-size:14px;font-weight:600;color:#D9D7D1">Du sparar</span>
          <b style="color:#FF5A36">{{ $kr($saving) }}<span style="font-size:15px;font-weight:600;letter-spacing:0">/år</span></b>
          <span style="font-size:13px;color:#D9D7D1">Listpriser hämtade {{ \Illuminate\Support\Str::of($comparison['fetched_at'])->replace(' ', ' kl. ') }}.</span>
        </div>
      @endif
    </div>
  </div>
</section>

<section id="jamfor" aria-labelledby="al-jamfor" style="scroll-margin-top:68px;padding:clamp(56px,8vw,110px) 0;background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="al-wrap">
    <p class="al-eyebrow">Jämför</p>
    <h2 id="al-jamfor" class="al-h2">NONOX och {{ $other['name'] }}, funktion för funktion.</h2>
    <div style="margin-top:40px">
      @include('start.partials.comparison', ['highlight' => $other['slug']])
    </div>
  </div>
</section>

<section aria-labelledby="al-flytt" style="padding:clamp(56px,8vw,110px) 0">
  <div class="al-wrap" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:clamp(32px,5vw,72px);align-items:start">
    <div>
      <p class="al-eyebrow">Byt system på en eftermiddag</p>
      <h2 id="al-flytt" class="al-h2">Så flyttar du från {{ $other['name'] }}.</h2>
      <p style="margin:20px 0 0;font-size:18px;line-height:1.55;color:var(--muted)">Hela historiken följer med via SIE4, det svenska standardformatet för bokföring. Flyttguiden tar dig igenom alla åtta steg.</p>
      <a href="{{ route('migrationGuide') }}" style="display:inline-flex;align-items:center;margin-top:28px;min-height:50px;padding:0 22px;border-radius:999px;border:1.5px solid var(--ink);color:var(--ink);text-decoration:none;font-size:15.5px;font-weight:600">Läs hela flyttguiden →</a>
    </div>
    <div class="al-source">
      <h3 style="margin:0 0 12px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:22px">Det här tar du ut från {{ $source['name'] ?? $other['name'] }}</h3>
      {!! $source['html'] ?? '' !!}
    </div>
  </div>
</section>

<section aria-labelledby="al-faq" style="padding:0 0 clamp(64px,9vw,120px)">
  <div class="al-wrap" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:clamp(32px,5vw,80px);align-items:start;border-top:1px solid var(--line);padding-top:clamp(56px,8vw,96px)">
    <h2 id="al-faq" class="al-h2">Vanliga frågor</h2>
    <div style="grid-column:span 2;min-width:0;max-width:100%">
      @foreach($altFaqs as $faq)
        <details style="border-bottom:1px solid var(--line)">
          <summary style="display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px 0;cursor:pointer;font-size:clamp(17px,1.7vw,20px);font-weight:650">{{ $faq['q'] }}<span class="faq-plus" aria-hidden="true" style="flex:none;width:34px;height:34px;border-radius:50%;border:1px solid var(--line);display:grid;place-items:center;transition:transform .25s ease"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 4v16M4 12h16"></path></svg></span></summary>
          <p style="margin:0;padding:0 56px 24px 0;font-size:16.5px;line-height:1.6;color:var(--muted)">{{ $faq['a'] }}</p>
        </details>
      @endforeach
    </div>
  </div>
</section>

<section aria-labelledby="al-cta" style="padding:clamp(80px,11vw,140px) clamp(20px,4vw,40px);background:#FF5A36;color:#15171C;text-align:center">
  <h2 id="al-cta" style="margin:0 auto;max-width:900px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,7vw,96px);line-height:0.95;letter-spacing:-0.05em;text-wrap:balance">Ta tillbaka din bokföring.</h2>
  <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin-top:40px">
    <a href="{{ auth()->check() ? route('dashboard') : route('waitlist') }}" style="display:inline-flex;align-items:center;min-height:56px;padding:0 28px;border-radius:999px;background:#15171C;color:#F7F5F0;text-decoration:none;font-size:17px;font-weight:700">{{ auth()->check() ? 'Till översikten' : 'Ställ dig i kö' }}</a>
    <a href="{{ route('migrationGuide') }}" style="display:inline-flex;align-items:center;min-height:56px;padding:0 28px;border-radius:999px;border:2px solid #15171C;color:#15171C;text-decoration:none;font-size:17px;font-weight:700">Flyttguiden</a>
  </div>
</section>

</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
</body>
</html>
