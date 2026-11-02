{{-- Shared footer of the public pages. --}}
@php
    $home = request()->routeIs('start') ? '' : url('/');
@endphp
<footer style="background:var(--dark);color:#F7F5F0;padding:clamp(56px,7vw,88px) clamp(20px,4vw,40px) 32px">
  <div style="max-width:1240px;margin:0 auto">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:40px">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div style="display:flex;align-items:center;gap:10px">
          <svg height="30" viewBox="150 195 2295 505" role="img" aria-label="NONOX" style="display:block;width:auto;height:30px"><path fill="#FF5A36" d="M653.28 428 A250 250 0 1 0 653.28 466 L569.92 466 A167 167 0 1 1 569.92 428 Z"></path><g fill="currentColor"><path d="M755 292H833L961 463V292H1040V602H961L833 431V602H755Z"></path><path d="M1399.89 441 A163 163 0 1 0 1399.89 453 L1334.82 453 A98 98 0 1 1 1334.82 441 Z"></path><path d="M1442 292H1520L1648 463V292H1727V602H1648L1520 431V602H1442Z"></path><path d="M2086.89 441 A163 163 0 1 0 2086.89 453 L2021.82 453 A98 98 0 1 1 2021.82 441 Z"></path><path d="M2129 292H2222L2284 383L2346 292H2439L2332 447L2439 602H2346L2284 511L2222 602H2129L2236 447Z"></path></g></svg>
        </div>
        <span style="font-size:14px;line-height:1.5;color:#A6AAB3;max-width:260px">NONOX är utvecklat av Stafe Development AB och ingår i Stafe Group AB.</span>
      </div>
      <nav aria-label="Produkt" style="display:flex;flex-direction:column;gap:12px;font-size:14.5px">
        <span style="font-size:12.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8E929B">Produkt</span>
        <a class="lp28" href="{{ $home }}#funktioner" style="text-decoration:none;color:#D9D7D1">Funktioner</a>
        <a class="lp29" href="{{ $home }}#priser" style="text-decoration:none;color:#D9D7D1">Priser</a>
        <a class="lp30" href="{{ $ph['flytt'] }}" style="text-decoration:none;color:#D9D7D1">Flyttguide</a>
        <a class="lp31" href="{{ $home }}#kor-sjalv" style="text-decoration:none;color:#D9D7D1">Nedladdning</a>
      </nav>
      <nav aria-label="Resurser" style="display:flex;flex-direction:column;gap:12px;font-size:14.5px">
        <span style="font-size:12.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8E929B">Resurser</span>
        <a class="lp33" href="{{ route('manual.home') }}" style="text-decoration:none;color:#D9D7D1">Manual</a>
        <a class="lp34" href="{{ $ph['api'] }}" style="text-decoration:none;color:#D9D7D1">API för utvecklare</a>
        <a class="lp28" href="{{ route('security') }}" style="text-decoration:none;color:#D9D7D1">Säkerhet och regelverk</a>
        <a class="lp29" href="{{ route('changelog') }}" style="text-decoration:none;color:#D9D7D1">Ändringslogg</a>
        <a class="lp30" href="{{ route('roadmap') }}" style="text-decoration:none;color:#D9D7D1">Färdplan</a>
      </nav>
      <nav aria-label="Öppen källkod" style="display:flex;flex-direction:column;gap:12px;font-size:14.5px">
        <span style="font-size:12.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8E929B">Öppen källkod</span>
        <a class="lp32" href="{{ $ph['github'] }}" style="text-decoration:none;color:#D9D7D1">Källkoden på GitHub</a>
        <a class="lp35" href="https://www.gnu.org/licenses/agpl-3.0.html" style="text-decoration:none;color:#D9D7D1">Licens AGPL-3.0</a>
      </nav>
      <nav aria-label="Branscher i sidfoten" style="display:flex;flex-direction:column;gap:12px;font-size:14.5px">
        <span style="font-size:12.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8E929B">Branscher</span>
        @foreach(App\Support\Landing\Industries::all() as $footerIndustry)
          <a href="{{ route('industry', $footerIndustry['slug']) }}" style="text-decoration:none;color:#D9D7D1">{{ $footerIndustry['name'] }}</a>
        @endforeach
        <a href="{{ route('industries') }}" style="text-decoration:none;color:#D9D7D1">Alla branscher</a>
      </nav>
      <nav aria-label="Alternativ till, i sidfoten" style="display:flex;flex-direction:column;gap:12px;font-size:14.5px">
        <span style="font-size:12.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8E929B">Alternativ till</span>
        @foreach(App\Support\Landing\Comparison::others() as $slug)
          <a href="{{ route('alternative', $slug) }}" style="text-decoration:none;color:#D9D7D1">{{ App\Support\Landing\Comparison::program($slug)['name'] ?? $slug }}</a>
        @endforeach
      </nav>
      <nav aria-label="Företag" style="display:flex;flex-direction:column;gap:12px;font-size:14.5px">
        <span style="font-size:12.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8E929B">Företag</span>
        <a class="lp36" href="https://stafe.se" style="text-decoration:none;color:#D9D7D1">Om Stafe</a>
        <a class="lp37" href="{{ $ph['contact'] }}" style="text-decoration:none;color:#D9D7D1">Kontakt</a>
        @if($ph['terms'])<a class="lp38" href="{{ $ph['terms'] }}" style="text-decoration:none;color:#D9D7D1">Villkor</a>@endif
        @if($ph['privacy'])<a class="lp39" href="{{ $ph['privacy'] }}" style="text-decoration:none;color:#D9D7D1">Integritetspolicy</a>@endif
        <a href="{{ route('legal.dpa') }}" style="text-decoration:none;color:#D9D7D1">Personuppgiftsbiträdesavtal</a>
        <a href="{{ route('legal.subprocessors') }}" style="text-decoration:none;color:#D9D7D1">Underbiträden</a>
        <a href="{{ route('legal.selfHosting') }}" style="text-decoration:none;color:#D9D7D1">Egen drift</a>
        <a href="{{ route('legal.accessibility') }}" style="text-decoration:none;color:#D9D7D1">Tillgänglighet</a>
        <a href="{{ route('cookies') }}" style="text-decoration:none;color:#D9D7D1">Cookies</a>
        <a href="{{ route('cookies') }}" data-cookie-settings style="text-decoration:none;color:#D9D7D1">Cookie-inställningar</a>
      </nav>
    </div>
    <div style="margin-top:56px;padding-top:24px;border-top:1px solid rgba(255,255,255,.1);display:flex;flex-direction:column;gap:8px;font-size:13px;color:#A6AAB3">
      <span>© {{ now()->year }}{{ $ph['company'] ? ' '.$ph['company'] : '' }}{{ $ph['orgnr'] ? ' · Org.nr '.$ph['orgnr'] : '' }} · {{ $ph['host'] }}</span>
      <span style="color:#8E929B">Övriga varumärken som nämns tillhör respektive ägare.</span>
      <span style="color:#8E929B;font-variant-numeric:tabular-nums" title="{{ App\Support\Build\BuildInfo::builtAt() }}">NONOX {{ App\Support\Build\BuildInfo::line() }}</span>
    </div>
  </div>
</footer>
@include('start.partials.cookie-banner')
