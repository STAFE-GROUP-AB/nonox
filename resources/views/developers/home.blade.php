{{-- The start page of the developer site (/utvecklare). --}}
@extends('developers.layout')
@use('App\Support\Developer\DeveloperPortal', 'Dev')
@use('App\Support\Developer\CodeBlock')
@use('App\Support\Developer\CodeSamples')
@use('App\Support\Developer\Guides')

@php
    $en = $lang === 'en';
    $samples = fn (string $id): string => CodeSamples::for($spec['operations'][$id], $spec['server'])['curl'];
    $keys = Dev::url('developers.guide', ['guide' => 'api-nycklar'], $lang);
    $oauth = Dev::url('developers.guide', ['guide' => 'autentisering'], $lang);
    $what = $en ? [
        ['Bookkeeping', 'Chart of accounts, VAT codes, fiscal years and voucher series: what a program needs to book correctly.'],
        ['Vouchers', 'Create drafts, post them with gapless numbers, correct posted vouchers and attach receipts and invoices.'],
        ['Registers', 'Customers and suppliers, with sync of everything that changed since last time.'],
        ['Standards', 'OpenAPI 3.1, OAuth 2.0 with PKCE, RFC 9457 errors, idempotent writes and rate-limit headers.'],
    ] : [
        ['Bokföring', 'Kontoplan, momskoder, räkenskapsår och verifikationsserier: det ett program behöver för att bokföra rätt.'],
        ['Verifikationer', 'Skapa utkast, bokför dem med nummer utan luckor, rätta bokförda verifikationer och lägg till kvitton och fakturor.'],
        ['Register', 'Kunder och leverantörer, med synk av allt som ändrats sedan förra gången.'],
        ['Standarder', 'OpenAPI 3.1, OAuth 2.0 med PKCE, fel enligt RFC 9457, idempotenta anrop och svarshuvuden för gränser.'],
    ];
    $free = $en ? [
        ['No add-on fee', 'The API is a module in NONOX like every other. There is no price per call, per app or per integration.'],
        ['Free when you run it yourself', 'NONOX is open source (AGPL-3.0). On your own server the API costs nothing, now or later.'],
        ['Included when we run it', 'The hosted service includes every module, the API too. It is not a premium tier.'],
        ['Your data, your way out', 'An open API is part of not being locked in, next to SIE4 and the archive package. Reading the code shows exactly what each call does.'],
    ] : [
        ['Ingen tilläggsavgift', 'API:t är en modul i NONOX som alla andra. Det kostar inget per anrop, per app eller per integration.'],
        ['Gratis när du driftar själv', 'NONOX har öppen källkod (AGPL-3.0). På din egen server kostar API:t ingenting, nu eller senare.'],
        ['Ingår när vi driftar', 'Tjänsten där vi sköter driften har alla moduler med i priset, API:t också. Det är ingen dyrare nivå.'],
        ['Din data, din väg ut', 'Ett öppet API är en del av att aldrig vara inlåst, bredvid SIE4 och arkivpaketet. Koden visar exakt vad varje anrop gör.'],
    ];
    $security = $en ? [
        'Every token and key belongs to exactly one company. Another company\'s ids answer 404, so the API never reveals what exists.',
        'An app gets in only when an owner or administrator approves it on a Swedish consent screen and confirms with a passkey.',
        'Access tokens live at most 15 minutes. Refresh tokens change on every use, and one that comes back a second time revokes the whole connection.',
        'A call may do what its scopes allow and what the person behind it may do in the company right now. A changed role counts on the next call.',
        'API keys always expire, can be limited to IP addresses, are shown once and stored only as a SHA-256 fingerprint.',
        'Every write, approval and revocation is in the company\'s audit trail. Personal numbers are never returned.',
        'A posted voucher can never change. The API can post a draft or correct a voucher, just like the app.',
    ] : [
        'Varje token och nyckel gäller exakt ett företag. Ett annat företags id ger 404, så API:t avslöjar aldrig vad som finns.',
        'En app kommer bara in när en ägare eller administratör godkänner den på en svensk sida och bekräftar med sin passkey.',
        'Access tokens gäller högst 15 minuter. Refresh tokens byts vid varje användning, och en som kommer tillbaka en andra gång återkallar hela anslutningen.',
        'Ett anrop får göra det som behörigheterna tillåter och det som personen bakom får göra i företaget just nu. En ändrad roll gäller från nästa anrop.',
        'API-nycklar har alltid ett slutdatum, kan begränsas till IP-adresser, visas en gång och sparas bara som ett SHA-256-avtryck.',
        'Varje skrivning, godkännande och återkallelse hamnar i företagets behandlingshistorik. Personnummer lämnas aldrig ut.',
        'En bokförd verifikation kan aldrig ändras. API:t kan bokföra ett utkast eller rätta en verifikation, precis som appen.',
    ];
@endphp

@section('content')
<div class="dv-wide">
  <section class="dv-hero" aria-labelledby="dv-title">
    <p class="dv-eyebrow">{{ $spec['info']['title'] }} · v{{ $spec['info']['version'] }}</p>
    <h1 id="dv-title">{{ $en ? 'Build on Swedish bookkeeping that is open for real.' : 'Bygg mot svensk bokföring som är öppen på riktigt.' }}</h1>
    <p class="dv-lead">{{ $en
        ? 'NONOX API is the API of NONOX: read the chart of accounts, create and post vouchers, attach documents and keep customers and suppliers in sync. Free, with no add-on fee, secured with OAuth 2.0 and passkeys, and documented down to every error code.'
        : 'NONOX API är NONOX API: läs kontoplanen, skapa och bokför verifikationer, lägg till underlag och håll kunder och leverantörer i synk. Gratis och utan tilläggsavgift, skyddat med OAuth 2.0 och passkeys, och dokumenterat ner till varje felkod.' }}</p>
    <div class="dv-actions">
      <a class="dv-btn dv-btn-primary" href="#kom-igang">{{ $en ? 'Get started in five minutes' : 'Kom igång på fem minuter' }}</a>
      <a class="dv-btn dv-btn-ghost" href="{{ Dev::url('developers.reference', [], $lang) }}">{{ Dev::t('reference', $lang) }}</a>
      <a class="dv-btn dv-btn-ghost" href="{{ route('developers.spec', ['format' => 'yaml']) }}" download>openapi.yaml</a>
    </div>
    <div class="dv-endpoint" style="max-width:640px;margin-top:26px">
      <span class="dv-type">{{ $en ? 'Base URL' : 'Basadress' }}</span><code>{{ $spec['server'] }}</code>
    </div>
  </section>

  <section aria-labelledby="dv-what" style="margin-top:64px">
    <h2 class="dv-h2" id="dv-what">{{ $en ? 'What the API covers' : 'Vad API:t gör' }}</h2>
    <div class="dv-facts">
      @foreach($what as [$title, $text])
        <div class="dv-fact"><strong>{{ $title }}</strong><span>{{ $text }}</span></div>
      @endforeach
    </div>
    <div class="dv-cards">
      @foreach($spec['tags'] as $tag)
        <a class="dv-card" href="{{ Dev::url('developers.tag', ['tag' => $tag['slug']], $lang) }}">
          <strong>{{ $en ? $tag['name_en'] : $tag['name'] }}</strong>
          <span>{{ $tag['description'][$lang] }}</span>
          <span class="dv-type">{{ count($tag['operations']) }} {{ $en ? 'endpoints' : 'anrop' }}</span>
        </a>
      @endforeach
    </div>
  </section>

  <section aria-labelledby="dv-free" style="margin-top:64px">
    <h2 class="dv-h2" id="dv-free">{{ $en ? 'Free, with no add-on fee' : 'Gratis, utan tilläggsavgift' }}</h2>
    <p class="dv-lead" style="margin-top:0">{{ $en
        ? 'Reaching your own figures from another program should not cost extra. This is how it works in NONOX.'
        : 'Att nå sina egna siffror från ett annat program ska inte kosta extra. Så här fungerar det i NONOX.' }}</p>
    <div class="dv-facts">
      @foreach($free as [$title, $text])
        <div class="dv-fact"><strong>{{ $title }}</strong><span>{{ $text }}</span></div>
      @endforeach
    </div>
  </section>

  <section aria-labelledby="dv-security" style="margin-top:64px" class="dv-prose">
    <h2 class="dv-h2" id="dv-security" style="margin-top:0">{{ $en ? 'Security, in plain words' : 'Säkerheten i klartext' }}</h2>
    <ul style="max-width:820px">
      @foreach($security as $line)
        <li>{{ $line }}</li>
      @endforeach
    </ul>
    <p>{{ $en ? 'The details:' : 'Detaljerna:' }} <a href="{{ $oauth }}">{{ Guides::title('autentisering', $lang) }}</a>, <a href="{{ $keys }}">{{ Guides::title('api-nycklar', $lang) }}</a>, <a href="{{ Dev::url('developers.guide', ['guide' => 'behorigheter'], $lang) }}">{{ Guides::title('behorigheter', $lang) }}</a>{{ $en ? ' and the' : ' och' }} <a href="{{ route('security') }}">{{ $en ? 'security page (Swedish)' : 'säkerhetssidan' }}</a>.</p>
  </section>

  <section id="kom-igang" aria-labelledby="dv-start" style="margin-top:64px;scroll-margin-top:80px;max-width:860px">
    <h2 class="dv-h2" id="dv-start">{{ $en ? 'Get started in five minutes' : 'Kom igång på fem minuter' }}</h2>
    <ol class="dv-steps">
      <li>
        <div>
          <h3>{{ $en ? 'Turn on NONOX API' : 'Slå på NONOX API' }}</h3>
          <p>{{ $en ? 'As owner or administrator of a company, open Inställningar → Moduler and turn on NONOX API. Use a company of your own for testing.' : 'Som ägare eller administratör i ett företag: öppna Inställningar → Moduler och slå på NONOX API. Testa gärna i ett eget företag.' }}</p>
        </div>
      </li>
      <li>
        <div>
          <h3>{{ $en ? 'Create an API key' : 'Skapa en API-nyckel' }}</h3>
          <p>{{ $en ? 'Under Anslutningar, create a key with the scopes vouchers:view, vouchers:create and vouchers:post and an expiry date. Confirm with your passkey and copy the key; it is shown once.' : 'Under Anslutningar skapar du en nyckel med behörigheterna vouchers:view, vouchers:create och vouchers:post och ett slutdatum. Bekräfta med din passkey och kopiera nyckeln; den visas bara en gång.' }}</p>
          {!! CodeBlock::html('export NONOX_TOKEN="nnx_…"', 'bash', $lang) !!}
        </div>
      </li>
      <li>
        <div>
          <h3>{{ $en ? 'Check which company the key belongs to' : 'Se vilket företag nyckeln gäller' }}</h3>
          {!! CodeBlock::html($samples('getCompany'), 'bash', $lang) !!}
        </div>
      </li>
      <li>
        <div>
          <h3>{{ $en ? 'Create a voucher draft' : 'Skapa ett utkast till verifikation' }}</h3>
          <p>{{ $en ? 'Amounts are whole öre: 125000 is SEK 1,250.00. The Idempotency-Key makes a retry safe.' : 'Belopp anges i hela öre: 125000 är 1 250,00 kr. Idempotency-Key gör ett nytt försök ofarligt.' }}</p>
          {!! CodeBlock::html($samples('createVoucher'), 'bash', $lang) !!}
        </div>
      </li>
      <li>
        <div>
          <h3>{{ $en ? 'Post it' : 'Bokför det' }}</h3>
          <p>{{ $en ? 'Use the id from the answer. The voucher gets the next number in its series, with no gaps, and can never change again, only be corrected.' : 'Använd id:t från svaret. Verifikationen får nästa nummer i serien, utan luckor, och kan sedan aldrig ändras, bara rättas.' }}</p>
          {!! CodeBlock::html($samples('postVoucher'), 'bash', $lang) !!}
        </div>
      </li>
    </ol>
    <p class="dv-prose" style="margin-top:22px"><a href="{{ Dev::url('developers.guide', ['guide' => 'kom-igang'], $lang) }}">{{ $en ? 'The full getting-started guide, in PHP, JavaScript and Python too →' : 'Hela guiden, också i PHP, JavaScript och Python →' }}</a></p>
  </section>

  <section aria-labelledby="dv-guides" style="margin-top:64px">
    <h2 class="dv-h2" id="dv-guides">{{ Dev::t('guides', $lang) }}</h2>
    <div class="dv-cards">
      @foreach($nav['guides'] as $item)
        <a class="dv-card" href="{{ $item['url'] }}"><strong>{{ $item['title'] }}</strong><span>{{ Guides::description($item['slug'], $lang) }}</span></a>
      @endforeach
    </div>
  </section>
</div>
@endsection
