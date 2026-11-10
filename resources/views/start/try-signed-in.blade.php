{{-- "Prova utan att registrera" for someone who is already signed in (/prova): the demo is a separate guest session. --}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
@include('start.partials.try-style')
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')
<main id="main" class="tr-wrap">
  <section class="tr-intro" aria-labelledby="tr-rubrik">
    <p class="tr-eyebrow">Prova utan att registrera</p>
    <h1 id="tr-rubrik">Demon är en egen gästsession.</h1>
    <p style="margin-top:22px">Du är inloggad med ditt konto. Demon körs som en gäst som är skild från ditt konto och dina företag, så den kan inte startas medan du är inloggad. Ditt konto och dina uppgifter påverkas inte.</p>
    <p>Du har två sätt att prova:</p>
    <ul class="tr-facts">
      <li><strong>Logga ut</strong> nedan och välj <strong>Prova utan att registrera</strong> på startsidan.</li>
      <li>Eller öppna nonox.app i ett <strong>privat fönster</strong> (ett inkognitofönster) i webbläsaren och starta demon där. Då förblir du inloggad i det vanliga fönstret.</li>
    </ul>
  </section>

  <section aria-label="Val" class="tr-card">
    <h2 class="tr-h2">Vad vill du göra?</h2>
    <form method="POST" action="{{ route('logout') }}" style="margin-top:22px">
      @csrf
      <button type="submit" class="tr-button">Logga ut</button>
    </form>
    <p class="tr-small" style="margin-top:18px"><a href="{{ route('dashboard') }}">Till översikten</a></p>
  </section>
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
</body>
</html>
