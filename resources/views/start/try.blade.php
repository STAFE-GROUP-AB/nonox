{{-- "Prova utan att registrera" (/prova, ADR-077): a demo company of one's own for two hours, with no account. --}}
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
    <h1 id="tr-rubrik">Prova NONOX på ett påhittat företag.</h1>
    <p style="margin-top:22px">Välj en bransch så får du ett eget demoföretag med ett års bokföring, fakturor, leverantörsfakturor, moms och lön. Du behöver varken konto eller e-postadress. <strong>Du kan klicka runt, bokföra och fakturera som du vill.</strong></p>
    <ul class="tr-facts">
      <li>Demon är <strong>din egen</strong>. Ingen annan ser vad du gör, och du ser ingen annans.</li>
      <li>Den varar i <strong>{{ $hours }} {{ $hours === 1 ? 'timme' : 'timmar' }}</strong>. Sedan tas den bort med allt du lagt in.</li>
      <li>Inget skickas till någon: inga mejl, inga e-fakturor, inga bankfiler och inget till Skatteverket.</li>
      <li>Vi sparar inget om dig. Ingen IP-adress läggs i databasen, den används bara för att begränsa hur ofta en dator kan starta en demo.</li>
      <li>När du vill börja på riktigt skapar du ett konto. Det börjar från början: <strong>demons uppgifter följer inte med</strong>. Flyttar du från ett annat program läser du in bokföringen med en SIE-fil, se <a href="{{ route('migrationGuide') }}">flyttguiden</a>.</li>
    </ul>
  </section>

  <section aria-label="Starta demon" class="tr-card">
    <h2 class="tr-h2">Starta din demo</h2>
    @if($errors->any())
      <p class="tr-alert" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="POST" action="{{ route('demo.start') }}" data-demo-form>
      @csrf
      <input type="hidden" name="started" value="{{ $started }}">
      <div class="tr-hp" aria-hidden="true">
        <label for="tr-website">Lämna det här fältet tomt</label>
        <input id="tr-website" type="text" name="website" value="" tabindex="-1" autocomplete="off">
      </div>
      <div class="tr-field" style="margin-top:22px">
        <label for="tr-industry">Vilken sorts företag vill du prova?</label>
        <select id="tr-industry" name="industry" required>
          @foreach($industries as $slug => $name)
            <option value="{{ $slug }}" @selected(old('industry', $industries ? array_key_first($industries) : '') === $slug)>{{ $name }}</option>
          @endforeach
        </select>
        @error('industry')<p class="tr-error">{{ $message }}</p>@enderror
      </div>
      <button type="submit" class="tr-button" data-demo-submit>Starta demon</button>
      <p class="tr-small" style="margin-top:14px">Det tar några sekunder att bygga ett helt års bokföring. Genom att starta demon godkänner du inte något avtal och lämnar inga uppgifter. Demon använder en sessionscookie som behövs för att hålla dig inloggad, se <a href="{{ route('cookies') }}">cookies</a>.</p>
    </form>
    <script>
      document.querySelector('[data-demo-form]')?.addEventListener('submit', function (event) {
        var button = event.currentTarget.querySelector('[data-demo-submit]');
        if (button) { button.disabled = true; button.textContent = 'Startar …'; }
      });
    </script>
  </section>
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
</body>
</html>
