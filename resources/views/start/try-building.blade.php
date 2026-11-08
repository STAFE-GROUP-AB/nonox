{{-- The wait while a demo is built (/prova/bygger, ADR-077): the page asks again every two seconds, with no script. --}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<meta http-equiv="refresh" content="2">
<meta name="robots" content="noindex">
@include('start.partials.try-style')
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')
<main id="main" class="tr-wrap">
  <section class="tr-intro" aria-labelledby="tr-rubrik">
    <p class="tr-eyebrow">Prova utan att registrera</p>
    <h1 id="tr-rubrik">Vi bygger ditt demoföretag.</h1>
    <p style="margin-top:22px" role="status">Ett helt års bokföring, fakturor och lön läggs in. Det tar några sekunder och sidan öppnas av sig själv när det är klart.</p>
  </section>
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
</body>
</html>
