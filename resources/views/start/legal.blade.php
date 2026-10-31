{{-- A legal document of the hosted service (LegalPageController), rendered from docs/juridik. --}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.legal h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(34px,5vw,56px);line-height:1;letter-spacing:-0.04em}
.legal h2{margin:44px 0 12px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:24px;letter-spacing:-0.02em}
.legal p,.legal li{font-size:16.5px;line-height:1.65;color:var(--muted)}
.legal strong{color:var(--ink)}
.legal a{color:var(--acct)}
.legal table{width:100%;border-collapse:collapse;margin:12px 0;font-size:14.5px;display:block;overflow-x:auto}
.legal th,.legal td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.legal th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')
<main id="main" class="legal" style="max-width:860px;margin:0 auto;padding:clamp(48px,8vw,96px) clamp(20px,4vw,40px)">
{!! $html !!}
<nav aria-label="Juridiska dokument" style="margin-top:56px;padding-top:24px;border-top:1px solid var(--line);display:flex;flex-wrap:wrap;gap:10px 22px;font-size:14.5px">
  <a href="{{ route('legal.privacy') }}">Integritetspolicy</a>
  <a href="{{ route('legal.terms') }}">Villkor</a>
  <a href="{{ route('legal.dpa') }}">Personuppgiftsbiträdesavtal</a>
  <a href="{{ route('legal.subprocessors') }}">Underbiträden</a>
  <a href="{{ route('cookies') }}">Cookies</a>
</nav>
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
</body>
</html>
