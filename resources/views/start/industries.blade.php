{{-- /branscher: every industry page from docs/branscher (IndustryController::index). --}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.br-wrap{max-width:1240px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
.br-crumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px;font-size:14px;color:var(--muted)}
.br-crumbs li+li::before{content:"/";margin-right:6px}
.br-crumbs a{color:var(--muted)}
.br-grid{list-style:none;margin:24px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:14px}
.br-card{height:100%;display:flex;flex-direction:column;gap:12px;padding:26px;border-radius:22px;background:var(--card);border:1px solid var(--line);text-decoration:none;color:var(--ink);transition:transform .2s,box-shadow .2s}
.br-card:hover{color:var(--ink);transform:translateY(-4px);box-shadow:0 24px 48px -24px rgba(21,23,28,.25)}
.br-card h3{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:22px;letter-spacing:-0.02em}
.br-card p{margin:0;font-size:15.5px;line-height:1.55;color:var(--muted)}
.br-card .br-go{margin-top:auto;font-size:14.5px;font-weight:700;color:var(--acct)}
@media (prefers-reduced-motion:reduce){.br-card{transition:none}.br-card:hover{transform:none}}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">

@include('start.partials.public-header')

<main id="main">
<section aria-labelledby="br-rubrik" style="padding:clamp(28px,4vw,40px) 0 clamp(64px,9vw,120px)">
  <div class="br-wrap">
    <nav aria-label="Brödsmulor" class="br-crumbs">
      <ol>
        @foreach($breadcrumbs as $crumb)
          <li>@if($loop->last)<span aria-current="page">{{ $crumb['name'] }}</span>@else<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@endif</li>
        @endforeach
      </ol>
    </nav>
    <p style="margin:clamp(36px,6vw,72px) 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Branscher</p>
    <h1 id="br-rubrik" style="margin:0;max-width:1000px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,6.6vw,88px);line-height:0.96;letter-spacing:-0.045em;text-wrap:balance">Bokföringsprogram för <span style="color:var(--accb)">din bransch.</span></h1>
    <p style="margin:24px 0 0;max-width:720px;font-size:clamp(17px,1.8vw,20px);line-height:1.55;color:var(--muted);text-wrap:pretty">Samma NONOX för alla, men vardagen ser olika ut. Här beskriver vi hur en månad ser ut i varje bransch, vilka delar av NONOX som löser den och vad det kostar jämfört med andra program, räknat från de listpriser vi hämtat.</p>

    @foreach($groups as $group => $label)
      <h2 style="margin:clamp(48px,6vw,72px) 0 0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(26px,3.2vw,38px);letter-spacing:-0.03em">{{ $label }}</h2>
      <ul class="br-grid">
        @foreach($industries as $industry)
          @continue($industry['group'] !== $group)
          <li>
            <a href="{{ route('industry', $industry['slug']) }}" class="br-card">
              <span class="mm-icon" style="width:48px;height:48px;border-radius:14px"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $industry['icon'] }}"></path></svg></span>
              <h3>{{ $industry['name'] }}</h3>
              <p>{{ $industry['menu_text'] }}</p>
              <span class="br-go">{{ $industry['eyebrow'] }} →</span>
            </a>
          </li>
        @endforeach
      </ul>
    @endforeach

    <div style="margin-top:clamp(48px,6vw,72px);display:flex;flex-wrap:wrap;gap:10px">
      <a href="{{ url('/') }}#funktioner" class="cmp-link">Alla funktioner</a>
      <a href="{{ url('/') }}#jamfor" class="cmp-link">Jämför med andra program</a>
      <a href="{{ route('migrationGuide') }}" class="cmp-link">Flyttguiden</a>
      <a href="{{ route('security') }}" class="cmp-link">Säkerhet och regelverk</a>
    </div>
  </div>
</section>
</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
</body>
</html>
