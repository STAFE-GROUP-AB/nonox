{{-- The wait while a demo is built (/prova/bygger, ADR-077): the page asks again every two seconds, with no script. --}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
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
    <p style="margin-top:22px" id="tr-status" data-status-url="{{ route('demo.status', $token) }}" role="status" aria-live="polite">Ett helt års bokföring, fakturor och lön läggs in. Det tar upp till en minut. Sidan öppnas av sig själv när det är klart.</p>
    <p class="tr-small" style="margin-top:18px">
      <a href="{{ route('demo.building', $token) }}" class="tr-button" style="max-width:260px;text-decoration:none" data-demo-check>Kontrollera igen</a>
    </p>
    <noscript><p class="tr-small">Utan JavaScript öppnas inte sidan av sig själv: tryck på Kontrollera igen med jämna mellanrum.</p></noscript>
  </section>
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
<script>
(function () {
  var status = document.getElementById('tr-status');
  var check = document.querySelector('[data-demo-check]');
  var url = status.getAttribute('data-status-url');
  if (check) { check.setAttribute('hidden', 'hidden'); }
  var tries = 0;
  function poll() {
    tries++;
    fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.status === 'ready') { status.textContent = 'Klart. Öppnar demon …'; window.location.href = d.url; return; }
        if (d.status === 'failed') { window.location.href = d.url; return; }
        if (tries === 15) { status.textContent = 'Det tar lite längre än vanligt. Vi väntar fortfarande …'; }
        setTimeout(poll, 2000);
      })
      .catch(function () {
        if (check) { check.removeAttribute('hidden'); }
        status.textContent = 'Kunde inte kontrollera just nu. Tryck på Kontrollera igen.';
        setTimeout(poll, 5000);
      });
  }
  setTimeout(poll, 2000);
})();
</script>
</body>
</html>
