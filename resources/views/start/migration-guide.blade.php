{{--
    The public moving guide (/flytta-hit, MigrationGuideController): from
    another bookkeeping program to NONOX, step by step. The export hints per
    program come from docs/migration/kallor. Progress through the steps is
    remembered in the visitor's browser only.
--}}
@php
    $steps = [
        [
            'id' => 'flyttdag', 'time' => '15 minuter', 'title' => 'Välj flyttdag',
            'lead' => 'Bestäm en dag då allt gammalt är avslutat, så att varje deklaration hamnar helt i ett system.',
            'do' => [
                'Flytta direkt efter att en momsdeklaration är inlämnad, och om ni har anställda, efter arbetsgivardeklarationen och innan nästa lön räknas.',
                'Mitt i året går bra. Hela årets bokföring följer med, så rapporter och bokslut blir rätt.',
                'Säg inte upp det gamla programmet ännu. Det gör du i sista steget.',
            ],
            'why' => 'En momsperiod som är halvt i två system är det enda som brukar ställa till det. Med rätt dag slipper du det helt.',
            'done' => 'Du har ett datum, och den senaste momsdeklarationen är inlämnad.',
        ],
        [
            'id' => 'exportera', 'time' => '1–2 timmar', 'title' => 'Ta ut allt ur ditt gamla program',
            'lead' => 'Det här är det viktigaste steget. Det du exporterar nu är ditt eget, för alltid.',
            'do' => [
                'Bokföringen som SIE4-fil, en fil för varje räkenskapsår. Ta med alla år som finns, även stängda.',
                'Alla underlag: kvitton, leverantörsfakturor och avtal som är kopplade till verifikationerna.',
                'Alla kundfakturor som PDF, och en lista över obetalda kund- och leverantörsfakturor på flyttdagen.',
                'Kund-, leverantörs- och artikelregistret som fil om det går.',
                'Har ni anställda: personallistan, årets summor per anställd, semestersaldona och lönebeskeden som PDF.',
            ],
            'why' => 'Bokföringslagen kräver att räkenskapsinformationen sparas i sju år. När exporten ligger hos dig är du inte längre beroende av någon annans abonnemang för att uppfylla det.',
            'done' => 'Du har en mapp med SIE-filer per år, underlagen, fakturorna och (om ni har anställda) lönefilerna.',
        ],
        [
            'id' => 'skapa', 'time' => '5 minuter',
            'title' => App\Support\Instance\InstanceSettings::current()->selfRegistrationOpen() ? 'Skapa ett konto och ditt företag' : 'Ställ dig i kö och skapa ditt företag',
            'lead' => App\Support\Instance\InstanceSettings::current()->selfRegistrationOpen()
                ? 'Logga in med din e-post så skapar vi ett konto. Inga lösenord, du får en kod i mejlet.'
                : 'Under den öppna betan ställer du dig först i kö. När du är godkänd loggar du in med din e-post. Inga lösenord, du får en kod i mejlet.',
            'do' => [
                'Fyll i organisationsnummer, företagsform och momsperiod.',
                'Välj samma räkenskapsår och samma bokföringsmetod (faktura- eller kontantmetoden) som i det gamla programmet.',
                'Bjud in redovisningskonsult eller kollegor om ni är fler. Varje person får sin egen roll.',
            ],
            'why' => 'Samma räkenskapsår och metod gör att de inlästa åren och det nya året hänger ihop utan justeringar.',
            'done' => 'Företaget finns i NONOX och du ser översikten.',
        ],
        [
            'id' => 'bokforing', 'time' => '10–30 minuter', 'title' => 'Läs in bokföringen',
            'lead' => 'Under Bokföring → Läs in SIE-fil läser du in en fil i taget: först det år som redan finns i NONOX, oftast det pågående, och sedan bakåt ett år i taget.',
            'do' => [
                'NONOX visar först en avstämning: vilka konton som läggs till, hur verifikationsserierna kopplas och att varje år balanserar. Inget sparas förrän du godkänner.',
                'Verifikationerna behåller sina serier och nummer när det går. Får en verifikation ett nytt nummer sparas originalserien och originalnumret på den, och den blir låst precis som allt annat som är bokfört.',
                'Kontoplan, kostnadsställen och projekt följer med i filen.',
            ],
            'why' => 'SIE4 är det svenska standardformatet för bokföring. Det är därför en flytt inte behöver vara krånglig, och därför du alltid kan flytta härifrån också.',
            'done' => 'Alla år är inlästa och saldobalansen per år visas under Rapporter.',
        ],
        [
            'id' => 'register', 'time' => 'Varierar', 'title' => 'Kunder, leverantörer och öppna fakturor',
            'lead' => 'Registren lägger du in i NONOX. Öppna fakturor från det gamla programmet finns redan med som saldon.',
            'do' => [
                'Lägg in de kunder, leverantörer och artiklar du använder framåt. Börja med dem du fakturerar oftast.',
                'Obetalda kundfakturor från det gamla programmet ligger kvar som saldo på kundfordringar (1510) genom SIE-filen. När kunden betalar bokför du betalningen mot 1510.',
                'Obetalda leverantörsfakturor ligger på leverantörsskulder (2440) och betalas på samma sätt.',
                'Alla nya fakturor skapar du i NONOX.',
            ],
            'why' => 'Att låta gamla fakturor betalas klart mot saldot är enklare och säkrare än att skapa om dem, och bokföringen blir exakt densamma.',
            'done' => 'Registren du behöver finns, och du vet vilka gamla fakturor som är obetalda.',
        ],
        [
            'id' => 'lon', 'time' => '30–60 minuter', 'title' => 'Lönen, om ni har anställda',
            'lead' => 'Under Lön → Flytt av lönedata finns en tom mall för varje del.',
            'do' => [
                'Anställda: namn, personnummer, anställning, lön och skattetabell.',
                'Hittills i år: bruttolön, skatt och arbetsgivaravgifter per anställd, så att resten av året räknas rätt.',
                'Semester: saldot för det semesterår som pågår.',
                'Gamla lönebesked som PDF hamnar i lönearkivet och på den anställdes egen sida.',
                'NONOX stämmer av årets skatt och avgifter mot bokföringen innan första lönen kan räknas.',
            ],
            'why' => 'Med årets summor på plats blir skatt på engångsbelopp, arbetsgivaravgifter och semester rätt även när ni byter mitt i året.',
            'done' => 'Flytten av lönedata är markerad som klar.',
        ],
        [
            'id' => 'stam-av', 'time' => '30 minuter', 'title' => 'Stäm av',
            'lead' => 'Jämför några siffror med det gamla programmet. Stämmer de, stämmer allt.',
            'do' => [
                'Saldobalansen för varje år är densamma som i det gamla programmet.',
                'Antalet verifikationer per serie och år stämmer.',
                'Kundfordringar och leverantörsskulder har samma saldo som listan över obetalda fakturor.',
                'Bankkontot stämmer med kontoutdraget på flyttdagen. Gör en bankavstämning i NONOX.',
            ],
            'why' => 'En avstämning tar en halvtimme och ger dig ett kvitto på att flytten är komplett.',
            'done' => 'Alla fyra kontrollerna stämmer.',
        ],
        [
            'id' => 'klart', 'time' => '5 minuter', 'title' => 'Kör vidare, och spara arkivet',
            'lead' => 'Nu bokför och fakturerar du i NONOX.',
            'do' => [
                'Kontrollera att bankgiro och betalningsuppgifter på fakturorna är rätt, och berätta för kunderna om något har ändrats.',
                'Spara exporten från steg 2 på minst två ställen i sju år.',
                'Säg upp det gamla abonnemanget först när allt är exporterat och avstämt.',
                'Vill du någon gång flytta vidare: NONOX exporterar allt som SIE4 och arkivpaket. Du är aldrig inlåst här heller.',
            ],
            'why' => 'Ditt arkiv är ditt. Det är hela poängen.',
            'done' => 'Det gamla abonnemanget är uppsagt och arkivet är sparat.',
        ],
    ];
    $moves = [
        ['Bokföring, alla år', 'SIE4-fil', true],
        ['Kontoplan, kostnadsställen och projekt', 'Följer med i SIE-filen', true],
        ['Ingående balanser och saldon', 'Följer med i SIE-filen', true],
        ['Obetalda kund- och leverantörsfakturor', 'Som saldo på 1510 och 2440', true],
        ['Lön: anställda, årets summor, semester', 'CSV-mallar i NONOX', true],
        ['Gamla lönebesked', 'PDF till lönearkivet', true],
        ['Kund-, leverantörs- och artikelregister', $directFetch ? 'Hämtas automatiskt från Fortnox; från andra program för hand, import kommer snart' : 'För hand i dag, import kommer snart', $directFetch],
        ['Underlag och kvitton', $directFetch ? 'Hämtas automatiskt från Fortnox och kopplas till verifikationerna; från andra program sparas de i ditt arkiv' : 'Sparas i ditt arkiv, inläsning kommer snart', $directFetch],
        ['Automatisk hämtning direkt från Fortnox', $directFetch ? 'Logga in på Fortnox-kontot, NONOX hämtar resten' : 'Kommer snart', $directFetch],
        ['Automatisk hämtning från andra program', 'Kommer snart', false],
    ];
    $faqs = [
        ['Hur lång tid tar det?', 'För de flesta småföretag en eftermiddag. Själva inläsningen tar minuter; det mesta av tiden går åt till att exportera ur det gamla programmet.'],
        ['Kan vi byta mitt i räkenskapsåret?', 'Ja. Hela årets bokföring läses in, och lönen fortsätter med beloppen hittills i år. Byt helst direkt efter en momsdeklaration.'],
        ['Försvinner något på vägen?', 'Nej. Varje verifikation behåller sitt originalnummer, antingen som nummer eller sparat på verifikationen, och NONOX stoppar inläsningen om ett år inte balanserar. Avstämningen i steg 7 bekräftar resten.'],
        ['Vad händer med underlagen?', 'De är din räkenskapsinformation och sparas i sju år. Spara dem i ditt arkiv från steg 2. Inläsning av underlag direkt till verifikationerna kommer snart.'],
        ['Vad kostar det?', 'Att köra NONOX själv är gratis. Vill du att vi sköter driften kostar det 995 kr om året för upp till 10 anställda och 1 995 kr om året upp till 25.'],
        ['Kan vår redovisningskonsult göra flytten?', 'Ja. Bjud in konsulten till företaget i NONOX, så kan hen läsa in filerna och stämma av.'],
        ['Kan vi flytta tillbaka?', 'Alltid. NONOX exporterar hela bokföringen som SIE4 och ett komplett arkivpaket. Ingen inlåsning, åt något håll.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.fg-wrap{max-width:1240px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
.fg-eyebrow{margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)}
.fg-h2{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(32px,4.6vw,56px);line-height:1;letter-spacing:-0.04em;text-wrap:balance}
.fg-lead{margin:20px 0 0;max-width:680px;font-size:clamp(17px,1.8vw,19px);line-height:1.55;color:var(--muted);text-wrap:pretty}
.fg-compare{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px;margin-top:48px}
.fg-col{border-radius:24px;padding:30px;display:flex;flex-direction:column;gap:14px}
.fg-col h3{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:24px;letter-spacing:-0.02em}
.fg-col ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px;font-size:16px;line-height:1.5}
.fg-col li{display:flex;gap:10px}
.fg-col svg{flex:none;margin-top:3px}
.fg-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-top:36px}
.fg-tab{height:44px;padding:0 20px;border-radius:999px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:600 15px Inter,system-ui,sans-serif;cursor:pointer}
.fg-tab:hover{border-color:var(--ink)}
.fg-tab[aria-selected=true]{background:var(--ink);border-color:var(--ink);color:var(--bg)}
.fg-source{margin-top:20px;border-radius:24px;background:var(--card);border:1px solid var(--line);padding:clamp(24px,4vw,40px)}
.fg-source h2{margin:0 0 14px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:26px;letter-spacing:-0.02em}
.fg-source p,.fg-source li{font-size:16.5px;line-height:1.6;color:var(--muted)}
.fg-source strong{color:var(--ink)}
.fg-source ul{padding-left:20px;display:flex;flex-direction:column;gap:8px}
.fg-progress{position:sticky;top:68px;z-index:10;background:var(--hdr);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.fg-progress-inner{display:flex;align-items:center;gap:16px;height:52px;font-size:14px;font-weight:600}
.fg-bar{flex:1;height:8px;border-radius:999px;background:var(--soft);overflow:hidden}
.fg-bar span{display:block;height:100%;width:0;background:#FF5A36;border-radius:999px;transition:width .35s ease}
.fg-steps{list-style:none;margin:48px 0 0;padding:0;display:flex;flex-direction:column}
.fg-step{display:grid;grid-template-columns:56px 1fr;gap:clamp(16px,3vw,32px);scroll-margin-top:140px}
.fg-rail{display:flex;flex-direction:column;align-items:center}
.fg-num{flex:none;width:56px;height:56px;border-radius:50%;border:2px solid var(--ink);display:grid;place-items:center;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:21px;background:var(--bg);transition:background .2s,border-color .2s}
.fg-step.is-done .fg-num{background:#FF5A36;border-color:#FF5A36;color:#15171C}
.fg-line{flex:1;width:2px;margin:8px 0;background:repeating-linear-gradient(180deg,var(--line) 0 6px,transparent 6px 12px)}
.fg-step:last-child .fg-line{display:none}
.fg-card{margin-bottom:28px;border-radius:24px;background:var(--card);border:1px solid var(--line);padding:clamp(22px,3.5vw,36px)}
.fg-meta{display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-size:13px;font-weight:600;color:var(--muted)}
.fg-chip{padding:4px 10px;border-radius:999px;background:var(--soft)}
.fg-card h3{margin:12px 0 0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(24px,2.8vw,32px);letter-spacing:-0.03em}
.fg-card .fg-step-lead{margin:10px 0 0;font-size:17px;line-height:1.55}
.fg-do{list-style:none;margin:22px 0 0;padding:0;display:flex;flex-direction:column;gap:12px}
.fg-do li{display:grid;grid-template-columns:22px 1fr;gap:10px;font-size:16px;line-height:1.55;color:var(--muted)}
.fg-why{margin-top:22px;padding:16px 18px;border-radius:16px;background:var(--accl);font-size:15px;line-height:1.55}
.fg-why strong{display:block;margin-bottom:2px}
.fg-done{margin-top:20px;display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:14px;border:1.5px dashed var(--line);cursor:pointer;font-size:15px;line-height:1.5}
.fg-done input{width:20px;height:20px;margin:1px 0 0;accent-color:#FF5A36;flex:none;cursor:pointer}
.fg-step.is-done .fg-done{border-style:solid;border-color:#FF5A36}
.fg-table{width:100%;border-collapse:collapse;font-size:15.5px}
.fg-table th{text-align:left;padding:12px 16px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:600}
.fg-table td{padding:16px;border-top:1px solid var(--line);vertical-align:top}
.fg-badge{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;padding:4px 10px;border-radius:999px;font-size:12.5px;font-weight:700}
.fg-yes{background:var(--accl);color:var(--acct)}
.fg-soon{background:var(--soft);color:var(--muted)}
@media (max-width:640px){.fg-step{grid-template-columns:40px 1fr}.fg-num{width:40px;height:40px;font-size:16px}}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">

@include('start.partials.public-header')

<main id="main">

<section aria-labelledby="fg-rubrik" style="padding:clamp(56px,9vw,112px) 0 clamp(40px,6vw,72px)">
  <div class="fg-wrap">
    <p class="fg-eyebrow">Flyttguide</p>
    <h1 id="fg-rubrik" style="margin:0;max-width:980px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(42px,7.4vw,96px);line-height:0.95;letter-spacing:-0.045em;text-wrap:balance">Från inlåst till <span style="color:var(--accb)">öppet.</span></h1>
    <p class="fg-lead">Så flyttar du bokföring, fakturor och lön från ditt gamla bokföringsprogram till NONOX. Åtta steg, en eftermiddag, och inget går förlorat på vägen.</p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:28px;font-size:14px;font-weight:600">
      <span class="fg-chip" style="padding:8px 14px">Tar ungefär en eftermiddag</span>
      <span class="fg-chip" style="padding:8px 14px">Hela historiken följer med</span>
      <span class="fg-chip" style="padding:8px 14px">Gratis att köra själv</span>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:36px">
      <a href="#steg" class="lp8" style="display:inline-flex;align-items:center;min-height:54px;padding:0 26px;border-radius:999px;background:#FF5A36;color:#15171C;text-decoration:none;font-size:16.5px;font-weight:700">Börja med steg 1</a>
      <a href="#ditt-program" style="display:inline-flex;align-items:center;min-height:54px;padding:0 26px;border-radius:999px;border:1.5px solid var(--ink);color:var(--ink);text-decoration:none;font-size:16.5px;font-weight:600">Välj ditt nuvarande program</a>
    </div>

    <div class="fg-compare">
      <div class="fg-col" style="background:var(--card);border:1px solid var(--line)">
        <span class="fg-meta">I dag</span>
        <h3>Inlåst och dyrt</h3>
        <ul style="color:var(--muted)">
          @foreach(['Avgift per modul, per användare och ofta per anställd.', 'Din bokföring ligger i någon annans moln, på deras villkor.', 'Priset kan höjas, och det är krångligt att ta med sig allt därifrån.'] as $item)
            <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>{{ $item }}</li>
          @endforeach
        </ul>
      </div>
      <div class="fg-col" style="background:#15171C;color:#F7F5F0">
        <span class="fg-meta" style="color:#FF8A6D">Med NONOX</span>
        <h3>Öppet och ditt</h3>
        <ul style="color:#D9D7D1">
          @foreach(['Gratis att köra själv, eller 995 kr om året med drift. Alla moduler ingår. NONOX Connect (Peppol, Skatteverket, bank) är ett tillägg som kostar extra: leverantörens pris + 10 %, från 2027-01-11.', 'Öppen källkod. All data kan exporteras, när som helst.', 'Byggt efter Bokföringslagen, med låsta verifikationer och behandlingshistorik.'] as $item)
            <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FF5A36" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"></path></svg>{{ $item }}</li>
          @endforeach
        </ul>
      </div>
    </div>
  </div>
</section>

<section id="ditt-program" aria-labelledby="program-rubrik" style="scroll-margin-top:68px;padding:clamp(56px,8vw,110px) 0;background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="fg-wrap">
    <p class="fg-eyebrow">Innan du börjar</p>
    <h2 id="program-rubrik" class="fg-h2">Vad använder du i dag?</h2>
    <p class="fg-lead">Välj ditt nuvarande program så ser du vad du ska ta ut därifrån. Stegen efter är desamma för alla.</p>
    <div class="fg-tabs" role="tablist" aria-label="Ditt nuvarande program">
      @foreach($sources as $source)
        <button type="button" class="fg-tab" role="tab" id="tab-{{ $source['slug'] }}" aria-controls="kalla-{{ $source['slug'] }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-fg-tab="{{ $source['slug'] }}">{{ $source['name'] }}</button>
      @endforeach
    </div>
    @foreach($sources as $source)
      <div class="fg-source" role="tabpanel" id="kalla-{{ $source['slug'] }}" aria-labelledby="tab-{{ $source['slug'] }}" data-fg-panel="{{ $source['slug'] }}">
        <h2>{{ $source['name'] }}</h2>
        {!! $source['html'] !!}
      </div>
    @endforeach
  </div>
</section>

<div class="fg-progress" aria-hidden="true">
  <div class="fg-wrap fg-progress-inner">
    <span data-fg-count>0 av {{ count($steps) }} steg klara</span>
    <span class="fg-bar"><span data-fg-bar></span></span>
  </div>
</div>

<section id="steg" aria-labelledby="steg-rubrik" style="scroll-margin-top:68px;padding:clamp(56px,8vw,110px) 0">
  <div class="fg-wrap">
    <p class="fg-eyebrow">Steg för steg</p>
    <h2 id="steg-rubrik" class="fg-h2">Åtta steg till ett öppet system.</h2>
    <p class="fg-lead">Bocka av varje steg när det är klart. Din webbläsare kommer ihåg var du är, så du kan ta en paus och fortsätta senare.</p>
    <ol class="fg-steps">
      @foreach($steps as $step)
        <li class="fg-step" id="steg-{{ $step['id'] }}" data-fg-step="{{ $step['id'] }}">
          <div class="fg-rail"><span class="fg-num">{{ $loop->iteration }}</span><span class="fg-line"></span></div>
          <article class="fg-card">
            <div class="fg-meta"><span class="fg-chip">Steg {{ $loop->iteration }} av {{ count($steps) }}</span><span>{{ $step['time'] }}</span></div>
            <h3>{{ $step['title'] }}</h3>
            <p class="fg-step-lead">{{ $step['lead'] }}</p>
            <ul class="fg-do">
              @foreach($step['do'] as $item)
                <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="margin-top:3px"><path d="M9 6l6 6-6 6"></path></svg><span>{{ $item }}</span></li>
              @endforeach
            </ul>
            <div class="fg-why"><strong>Varför?</strong>{{ $step['why'] }}</div>
            <label class="fg-done"><input type="checkbox" data-fg-check="{{ $step['id'] }}"><span><strong>Klart när:</strong> {{ $step['done'] }}</span></label>
          </article>
        </li>
      @endforeach
    </ol>
  </div>
</section>

<section aria-labelledby="flytt-rubrik" style="padding:clamp(56px,8vw,110px) 0;background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div class="fg-wrap">
    <p class="fg-eyebrow">Vad följer med</p>
    <h2 id="flytt-rubrik" class="fg-h2">Så här kommer allt över.</h2>
    <div style="margin-top:40px;overflow-x:auto;border:1px solid var(--line);border-radius:22px;background:var(--bg)">
      <table class="fg-table">
        <thead><tr><th>Det här</th><th>Kommer över som</th><th style="text-align:right">I dag</th></tr></thead>
        <tbody>
          @foreach($moves as [$what, $how, $ready])
            <tr>
              <td style="font-weight:600">{{ $what }}</td>
              <td style="color:var(--muted)">{{ $how }}</td>
              <td style="text-align:right"><span class="fg-badge {{ $ready ? 'fg-yes' : 'fg-soon' }}">{{ $ready ? 'Klart' : 'Kommer snart' }}</span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>

<section aria-labelledby="fg-faq" style="padding:clamp(56px,8vw,110px) 0">
  <div class="fg-wrap" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:clamp(32px,5vw,80px);align-items:start">
    <h2 id="fg-faq" class="fg-h2">Vanliga frågor om flytten</h2>
    <div style="grid-column:span 2;min-width:0;max-width:100%">
      @foreach($faqs as [$question, $answer])
        <details style="border-bottom:1px solid var(--line)">
          <summary style="display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px 0;cursor:pointer;font-size:clamp(17px,1.7vw,20px);font-weight:650">{{ $question }}<span class="faq-plus" aria-hidden="true" style="flex:none;width:34px;height:34px;border-radius:50%;border:1px solid var(--line);display:grid;place-items:center;transition:transform .25s ease"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 4v16M4 12h16"></path></svg></span></summary>
          <p style="margin:0;padding:0 56px 24px 0;font-size:16.5px;line-height:1.6;color:var(--muted)">{{ $answer }}</p>
        </details>
      @endforeach
      <p style="margin:28px 0 0;font-size:16.5px;line-height:1.6;color:var(--muted)">Varje steg i appen, från första SIE-filen till avstämningen, beskrivs i <a href="{{ App\Support\Manual\Manual::section('flytta') ? App\Support\Manual\Manual::url('flytta') : route('manual.home') }}" style="color:var(--acct);font-weight:600">manualen för NONOX</a>.</p>
    </div>
  </div>
</section>

<section aria-labelledby="fg-cta" style="padding:clamp(80px,11vw,140px) clamp(20px,4vw,40px);background:#FF5A36;color:#15171C;text-align:center">
  <h2 id="fg-cta" style="margin:0 auto;max-width:900px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,7vw,96px);line-height:0.95;letter-spacing:-0.05em;text-wrap:balance">Ta tillbaka din bokföring.</h2>
  <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin-top:40px">
    @auth
      <a href="{{ route('accounting.sieImports.index') }}" style="display:inline-flex;align-items:center;min-height:56px;padding:0 28px;border-radius:999px;background:#15171C;color:#F7F5F0;text-decoration:none;font-size:17px;font-weight:700">Läs in din första SIE-fil</a>
    @else
      <a href="{{ App\Support\Landing\SignupPath::url() }}" style="display:inline-flex;align-items:center;min-height:56px;padding:0 28px;border-radius:999px;background:#15171C;color:#F7F5F0;text-decoration:none;font-size:17px;font-weight:700">{{ App\Support\Landing\SignupPath::label() }}</a>
    @endauth
    <a href="{{ $ph['contact'] }}" style="display:inline-flex;align-items:center;min-height:56px;padding:0 28px;border-radius:999px;border:2px solid #15171C;color:#15171C;text-decoration:none;font-size:17px;font-weight:700">Vi hjälper dig flytta</a>
  </div>
</section>

</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
<script>
(() => {
    // Tabs per program: without the script every program is simply shown.
    const tabs = [...document.querySelectorAll('[data-fg-tab]')];
    const select = (slug) => tabs.forEach((tab) => {
        const on = tab.dataset.fgTab === slug;
        tab.setAttribute('aria-selected', String(on));
        tab.tabIndex = on ? 0 : -1;
        document.querySelector('[data-fg-panel="' + tab.dataset.fgTab + '"]').hidden = !on;
    });
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab.dataset.fgTab));
        tab.addEventListener('keydown', (event) => {
            const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
            if (!step) { return; }
            const next = tabs[(index + step + tabs.length) % tabs.length];
            select(next.dataset.fgTab);
            next.focus();
        });
    });
    if (tabs.length) { select(tabs[0].dataset.fgTab); }

    // Progress through the steps, remembered in this browser only.
    const key = 'nonox-flyttguide';
    let done = [];
    try { done = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { done = []; }
    const checks = [...document.querySelectorAll('[data-fg-check]')];
    const render = () => {
        checks.forEach((check) => {
            check.checked = done.includes(check.dataset.fgCheck);
            check.closest('[data-fg-step]').classList.toggle('is-done', check.checked);
        });
        document.querySelector('[data-fg-count]').textContent = done.length + ' av ' + checks.length + ' steg klara';
        document.querySelector('[data-fg-bar]').style.width = (100 * done.length / checks.length) + '%';
    };
    checks.forEach((check) => check.addEventListener('change', () => {
        done = checks.filter((item) => item.checked).map((item) => item.dataset.fgCheck);
        try { localStorage.setItem(key, JSON.stringify(done)); } catch (e) { /* private window: progress is not kept */ }
        render();
    }));
    render();
})();
</script>
</body>
</html>
