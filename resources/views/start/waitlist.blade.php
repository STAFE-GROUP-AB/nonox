{{-- "Ställ dig i kö" (/ko, ADR-038): the waiting list of the open beta. The form is the Livewire component waitlist.join-form. --}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.wl-wrap{max-width:1180px;margin:0 auto;padding:clamp(40px,7vw,88px) clamp(20px,4vw,40px) clamp(64px,9vw,120px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:clamp(32px,5vw,72px);align-items:start}
.wl-intro h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,6vw,76px);line-height:.98;letter-spacing:-0.045em;text-wrap:balance}
.wl-intro p{font-size:17.5px;line-height:1.6;color:var(--muted);text-wrap:pretty}
.wl-intro strong{color:var(--ink)}
.wl-facts{list-style:none;margin:28px 0 0;padding:0;display:grid;gap:14px}
.wl-facts li{position:relative;padding-left:21px;font-size:16px;line-height:1.5}
.wl-facts li::before{content:"";position:absolute;left:0;top:8px;width:9px;height:9px;border-radius:50%;background:var(--acc)}
.wl-card{background:var(--card);border:1px solid var(--line);border-radius:28px;padding:clamp(22px,4vw,40px);box-shadow:0 30px 60px -40px rgba(21,23,28,.35)}
.wl-fieldset{border:0;margin:0 0 8px;padding:0;min-width:0}
.wl-legend{padding:0;margin:0 0 14px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:20px;letter-spacing:-0.02em}
.wl-field{margin-bottom:18px}
.wl-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:0 16px}
.wl-field label{display:block;font-weight:650;font-size:15px;margin-bottom:6px}
.wl-field input[type=text],.wl-field input[type=email],.wl-field input[type=tel],.wl-field select,.wl-field textarea{width:100%;min-height:48px;padding:11px 14px;font:inherit;font-size:16px;color:var(--ink);background:var(--bg);border:1.5px solid #8a837d;border-radius:12px}
.wl-field textarea{min-height:96px;resize:vertical}
.wl-field input:focus-visible,.wl-field select:focus-visible,.wl-field textarea:focus-visible,.wl-check input:focus-visible,.wl-button:focus-visible{outline:3px solid var(--acc);outline-offset:2px}
.wl-field [aria-invalid=true]{border-color:var(--acct)}
.wl-optional{font-weight:500;color:var(--muted)}
.wl-hint{margin:6px 0 0;font-size:14px;color:var(--muted)}
.wl-error{margin:6px 0 0;font-size:14.5px;font-weight:600;color:var(--acct)}
.wl-alert{margin:0 0 18px;padding:12px 14px;border-radius:12px;background:var(--accl);color:var(--ink);font-weight:600}
.wl-check{display:flex;gap:12px;align-items:flex-start}
.wl-check input{flex:none;width:22px;height:22px;margin:2px 0 0;accent-color:#B8381A}
.wl-check label{font-weight:500;font-size:15.5px;line-height:1.5;margin:0}
.wl-check a,.wl-small a,.wl-intro a{color:var(--acct);font-weight:600}
.wl-button{display:inline-flex;align-items:center;justify-content:center;width:100%;min-height:54px;padding:0 26px;border:0;border-radius:999px;background:#FF5A36;color:#15171C;font:inherit;font-size:17px;font-weight:750;cursor:pointer}
.wl-button:disabled{opacity:.65;cursor:progress}
.wl-small{font-size:14px;line-height:1.55;color:var(--muted)}
.wl-hp{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden}
.wl-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.wl-eyebrow{margin:0 0 10px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)}
.wl-h2{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(32px,4vw,46px);letter-spacing:-0.035em;outline:none}
.wl-h3{margin:26px 0 10px;font-size:18px;font-weight:750}
.wl-lead{margin:14px 0 0;font-size:17px;line-height:1.55;color:var(--muted)}
.wl-steps{margin:0;padding-left:22px;display:grid;gap:8px;font-size:16px;line-height:1.5}
.wl-note{margin:24px 0 16px;padding:14px 16px;border-radius:14px;background:var(--accl);font-size:15.5px;line-height:1.55}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')
<main id="main" class="wl-wrap">
  <section class="wl-intro" aria-labelledby="wl-rubrik">
    <p class="wl-eyebrow">Öppen beta</p>
    <h1 id="wl-rubrik">{{ $closed ? 'Kön till NONOX.' : 'Ställ dig i kö till NONOX.' }}</h1>
    <p style="margin-top:22px">NONOX är i <strong>öppen beta</strong>. Vi släpper in nya företag i den takt vi hinner hjälpa dem, så att varje start blir bra.@unless($closed) Svara på några frågor så hör vi av oss.@endunless</p>
    <p><strong>Skarp lansering av version 1.0 sker den 1 januari 2027.</strong> Det du lägger in under betan ligger kvar, vi rensar ingenting när betan är över.</p>
    <ul class="wl-facts">
      <li>Du får ett mejl direkt som bekräftar att du står i kö.</li>
      <li>När vi har godkänt dig får du ett nytt mejl, och sedan loggar du in med en kod. Inga lösenord.</li>
      <li>Första gången lägger du upp ditt företag. Byter du system läser du in bokföringen med SIE-filer, se <a href="{{ route('migrationGuide') }}">flyttguiden</a>.</li>
      <li>Vill du hellre köra NONOX själv behöver du inte stå i kö: programmet är öppen källkod och <a href="{{ url('/') }}#kor-sjalv">gratis att ladda ner</a>.</li>
    </ul>
    <p class="wl-small" style="margin-top:28px">Redan godkänd? <a href="{{ route('login') }}">Logga in</a>. Har ett företag bjudit in dig loggar du in med adressen inbjudan gick till, du behöver inte stå i kö.</p>
  </section>

  @if($closed)
  <section aria-labelledby="wl-stangd" class="wl-card" data-waitlist-closed>
    <h2 id="wl-stangd" class="wl-h2">Kön är stängd just nu.</h2>
    <p class="wl-lead">Vi tar inte emot nya anmälningar för tillfället, så att vi hinner hjälpa dem som redan står i kö. Titta in igen lite senare.</p>
    @if($registrationOpen)
      <p class="wl-lead">Du kan skapa ett konto direkt i stället: <a href="{{ route('login') }}" style="color:var(--acct);font-weight:600">kom igång här</a>.</p>
    @endif
    <p class="wl-small" style="margin-top:22px">Står du redan i kö ligger din anmälan kvar, och du får ett mejl när du är godkänd. Vill du hellre köra NONOX själv är programmet öppen källkod och <a href="{{ url('/') }}#kor-sjalv">gratis att ladda ner</a>.</p>
  </section>
  @else
  <section aria-label="Anmälan till kön">
    @if($registrationOpen)
      <p class="wl-note">Du behöver inte stå i kö: du kan också <a href="{{ route('login') }}" style="color:var(--acct);font-weight:600">skapa ett konto direkt</a>.</p>
    @endif
    <livewire:waitlist.join-form />
  </section>
  @endif
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
</body>
</html>
