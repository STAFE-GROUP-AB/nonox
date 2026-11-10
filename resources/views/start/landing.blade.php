{{--
    The public landing page, shown at "/" to visitors who are not signed in
    (StartController). Its content comes from App\Support\Landing\LandingPage.
    The small script at the end runs the menu, the copy button, the price
    calculator and the scroll effects; the page reads fine without it.
--}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">

@include('start.partials.public-header')

<main id="main">

<section aria-labelledby="hero-rubrik" style="padding:clamp(40px,6vw,72px) clamp(20px,4vw,40px) clamp(48px,7vw,96px)">
  <div style="max-width:1240px;margin:0 auto">
   <div class="lp-hero">
    <div data-reveal="">
      <p style="margin:0 0 28px;display:inline-flex;flex-wrap:wrap;align-items:center;gap:8px;padding:7px 14px 7px 10px;border-radius:999px;background:var(--accl);color:var(--ink);font-size:13.5px;font-weight:600">
        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="#FF5A36" d="M23.47 11.13 A11.5 11.5 0 1 0 23.47 12.87 L19.63 12.87 A7.68 7.68 0 1 1 19.63 11.13 Z"></path></svg>
        <span><strong style="font-weight:800">{{ App\Support\Build\BuildInfo::stage() ?: 'Version' }}</strong> · Version {{ App\Support\Build\BuildInfo::version() }} · Öppen källkod · Byggd i Sverige</span>
      </p>
      <h1 id="hero-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,6.6vw,92px);line-height:0.95;letter-spacing:-0.045em;text-wrap:balance"><span class="lp-line">Ditt företag.</span> <span class="lp-line">Din data.</span> <span class="lp-line" style="color:var(--accb)">Ditt system.</span></h1>
      <p style="margin:28px 0 0;max-width:720px;font-size:clamp(18px,2vw,21px);line-height:1.5;color:var(--muted);text-wrap:pretty">Bokföring, fakturering, lön, lager och mycket mer i ett svenskt ekonomisystem. Ladda ner det gratis och kör det på ditt webbhotell eller din egen server, eller låt oss sköta driften från 995 kr om året.</p>
      <p style="margin:16px 0 0;max-width:720px;font-size:clamp(17px,1.8vw,19px);line-height:1.5;font-weight:600;color:var(--ink);text-wrap:pretty">Ett alternativ till de stora molntjänsterna, med bokföring, lön och bokslut i ett, utan licensavgifter och utan inlåsning.</p>
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:36px">
        <a class="lp9" href="{{ auth()->check() ? route('dashboard') : App\Support\Landing\SignupPath::url() }}" style="display:inline-flex;align-items:center;gap:10px;min-height:54px;padding:0 26px;border-radius:999px;background:#FF5A36;color:#15171C;text-decoration:none;font-size:16.5px;font-weight:700">{{ auth()->check() ? 'Till översikten' : App\Support\Landing\SignupPath::label() }}</a>
        <a class="lp10" href="#kor-sjalv" style="display:inline-flex;align-items:center;gap:10px;min-height:54px;padding:0 26px;border-radius:999px;border:1.5px solid var(--ink);color:var(--ink);text-decoration:none;font-size:16.5px;font-weight:600">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"></path></svg>Ladda ner NONOX gratis</a>
        @if(! auth()->check() && App\Support\Demo\DemoMode::enabled())
          <a class="lp10" href="{{ route('demo.show') }}" data-try-link style="display:inline-flex;align-items:center;gap:10px;min-height:54px;padding:0 26px;border-radius:999px;border:1.5px solid var(--ink);color:var(--ink);text-decoration:none;font-size:16.5px;font-weight:600">Prova utan att registrera</a>
        @endif
      </div>
      <p style="margin:18px 0 0;font-size:14px;color:var(--muted)">Ingen bindningstid. Exportera all data när du vill.</p>
      <p id="oppen-beta" style="margin:24px 0 0;max-width:680px;padding:16px 18px;border-radius:16px;border:1px solid var(--line);background:var(--card);font-size:15.5px;line-height:1.55;color:var(--ink);text-wrap:pretty"><strong>NONOX är i öppen beta.</strong> @if(App\Support\Instance\InstanceSettings::current()->selfRegistrationOpen())Du kan skapa ett konto direkt med din e-post.@elseif(App\Support\Instance\InstanceSettings::current()->waitlistOpen())Ställ dig i kö så släpper vi in dig i den takt vi hinner hjälpa alla.@else Kön är stängd just nu, så att vi hinner hjälpa dem som redan står i den.@endif Skarp lansering av version 1.0 sker den 1 februari 2027. Det du lägger in under betan ligger kvar, vi rensar ingenting när betan är över.</p>
    </div>
    <aside data-reveal="" aria-labelledby="lp-incl-rubrik">
      <div class="lp-incl">
        <p id="lp-incl-rubrik" class="lp-incl-title">Allt det här ingår</p>
        <ul role="list">
          @foreach($included as $item)
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>{{ $item['label'] }}</li>
          @endforeach
        </ul>
        <p class="lp-incl-note">Inga tilläggsmoduler att köpa, vare sig du kör själv eller låter oss sköta driften. NONOX Connect (Peppol, Skatteverket, bank) är ett tillägg som kostar extra: leverantörens pris + 10 %, från 2027-01-11. NONOX AI använder företagets egen nyckel hos en AI-leverantör, och lönens regler bekräftar du mot Skatteverkets källor före första lönekörningen. Kör du själv betalar du tjänster från andra, som bankkoppling, direkt till dem.</p>
        <a href="#funktioner">Se hur det ser ut →</a>
      </div>
    </aside>
   </div>

    <div id="lp-mock" data-reveal="" aria-hidden="true" style="position:relative;margin-top:clamp(48px,7vw,88px);height:650px">
      <div style="position:absolute;left:0;top:0;width:1000px;height:640px;transform-origin:0 0;transform:scale(1)" id="lp-mock-inner">
        <div style="position:absolute;inset:-40px -60px -40px 120px;background:radial-gradient(60% 60% at 60% 50%,rgba(255,90,54,.16),transparent 70%);pointer-events:none"></div>
        <div style="position:absolute;left:0;top:0;width:900px;height:580px;background:#FFFFFF;border-radius:16px;border:1px solid rgba(21,23,28,.12);box-shadow:0 40px 80px -30px rgba(21,23,28,.35),0 2px 6px rgba(21,23,28,.06);overflow:hidden;color:#15171C;font-family:Inter,sans-serif;display:flex;flex-direction:column">
          <div style="height:40px;flex:none;display:flex;align-items:center;gap:8px;padding:0 16px;border-bottom:1px solid #ECE9E2;background:#FAF9F6">
            <span style="width:11px;height:11px;border-radius:50%;background:#E4E1DA"></span><span style="width:11px;height:11px;border-radius:50%;background:#E4E1DA"></span><span style="width:11px;height:11px;border-radius:50%;background:#E4E1DA"></span>
            <span style="margin:0 auto;padding:4px 14px;border-radius:7px;background:#F0EEE8;font-size:11.5px;color:#6A6E77">nonox.app/bokforing</span>
          </div>
          <div style="flex:1;display:flex;min-height:0">
            <div style="width:186px;flex:none;background:#15171C;color:#F3F1EC;padding:16px 12px;display:flex;flex-direction:column;gap:2px;font-size:12.5px">
              <div style="display:flex;align-items:center;gap:8px;padding:8px;margin-bottom:12px;border-radius:9px;background:rgba(255,255,255,.06)">
                <span style="width:24px;height:24px;border-radius:6px;background:#FF5A36;color:#15171C;display:grid;place-items:center;font-weight:800;font-size:11px">E</span>
                <span style="line-height:1.2"><span style="display:block;font-weight:600">Exempelbolaget AB</span><span style="font-size:10.5px;color:#9EA2AB">556677-8899</span></span>
              </div>
              <span style="padding:7px 10px;color:#B5B9C1">Översikt</span>
              <span style="padding:7px 10px;border-radius:7px;background:rgba(255,90,54,.18);color:#FFFFFF;font-weight:600;box-shadow:inset 2px 0 0 #FF5A36">Bokföring</span>
              <span style="padding:7px 10px;color:#B5B9C1">Kundfakturor</span>
              <span style="padding:7px 10px;color:#B5B9C1">Leverantörsfakturor</span>
              <span style="padding:7px 10px;color:#B5B9C1">Moms</span>
              <span style="padding:7px 10px;color:#B5B9C1">Bokslut</span>
              <span style="padding:7px 10px;color:#B5B9C1">Lön</span>
              <span style="padding:7px 10px;color:#B5B9C1">Rapporter</span>
              <span style="margin-top:auto;padding:7px 10px;color:#7E828B;font-size:11px">Räkenskapsår 2026</span>
            </div>
            <div style="flex:1;padding:22px 24px;display:flex;flex-direction:column;gap:16px;min-width:0">
              <div style="display:flex;align-items:center;gap:12px">
                <div>
                  <div style="font-size:11px;color:#6A6E77;font-weight:500">Verifikationer / Serie A</div>
                  <div style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:22px;letter-spacing:-0.02em">Verifikation A 47</div>
                </div>
                <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:5px 10px 5px 7px;border-radius:999px;background:#F2F0EB;font-size:11.5px;font-weight:600">
                  <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#1F9D55"></circle><path d="M7 12.5l3.2 3L17 9" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>Balanserar</span>
                <span style="padding:8px 14px;border-radius:8px;background:#FF5A36;color:#15171C;font-size:12px;font-weight:700">Bokför</span>
              </div>
              <div style="display:grid;grid-template-columns:130px 1fr;gap:10px">
                <div style="border:1px solid #E6E3DC;border-radius:8px;padding:8px 10px"><div style="font-size:10px;color:#6A6E77">Datum</div><div style="font-size:13px;font-weight:600;font-variant-numeric:tabular-nums">2026-10-14</div></div>
                <div style="border:1px solid #E6E3DC;border-radius:8px;padding:8px 10px"><div style="font-size:10px;color:#6A6E77">Beskrivning</div><div style="font-size:13px;font-weight:600">Kontorsmaterial, Papperslådan AB</div></div>
              </div>
              <div style="border:1px solid #E6E3DC;border-radius:10px;overflow:hidden;font-size:12.5px;font-variant-numeric:tabular-nums">
                <div style="display:grid;grid-template-columns:64px 1fr 96px 96px;gap:8px;padding:9px 12px;background:#FAF9F6;color:#6A6E77;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em"><span>Konto</span><span>Benämning</span><span style="text-align:right">Debet</span><span style="text-align:right">Kredit</span></div>
                <div style="display:grid;grid-template-columns:64px 1fr 96px 96px;gap:8px;padding:10px 12px;border-top:1px solid #EFECE6"><span style="font-weight:600">6110</span><span>Kontorsmateriel</span><span style="text-align:right">1 600,00</span><span style="text-align:right;color:#B0B3BA">–</span></div>
                <div style="display:grid;grid-template-columns:64px 1fr 96px 96px;gap:8px;padding:10px 12px;border-top:1px solid #EFECE6"><span style="font-weight:600">2641</span><span>Debiterad ingående moms</span><span style="text-align:right">400,00</span><span style="text-align:right;color:#B0B3BA">–</span></div>
                <div style="display:grid;grid-template-columns:64px 1fr 96px 96px;gap:8px;padding:10px 12px;border-top:1px solid #EFECE6;background:#FFF6F3"><span style="font-weight:600">1930</span><span>Företagskonto</span><span style="text-align:right;color:#B0B3BA">–</span><span style="text-align:right">2 000,00</span></div>
                <div style="display:grid;grid-template-columns:64px 1fr 96px 96px;gap:8px;padding:10px 12px;border-top:1px solid #EFECE6;color:#B0B3BA"><span>Konto</span><span>Lägg till rad …</span><span></span><span></span></div>
                <div style="display:grid;grid-template-columns:64px 1fr 96px 96px;gap:8px;padding:10px 12px;border-top:1.5px solid #15171C;font-weight:700"><span></span><span>Summa</span><span style="text-align:right">2 000,00</span><span style="text-align:right">2 000,00</span></div>
              </div>
              <div style="display:flex;gap:18px;font-size:11px;color:#6A6E77"><span>Skapad av Anna Exempel</span><span>Ändringslogg: 2 händelser</span><span style="margin-left:auto">Momsperiod 2026-10</span></div>
            </div>
            <div style="width:220px;flex:none;border-left:1px solid #ECE9E2;background:#FAF9F6;padding:18px 16px;display:flex;flex-direction:column;gap:10px">
              <div style="display:flex;align-items:center;justify-content:space-between;font-size:11.5px;font-weight:600"><span>Underlag</span><span style="color:#6A6E77;font-weight:500">1 bilaga</span></div>
              <div style="background:#FFFFFF;border:1px solid #E6E3DC;border-radius:4px;padding:16px 14px;box-shadow:0 6px 14px -8px rgba(21,23,28,.25);font-family:ui-monospace,Menlo,monospace;font-size:9.5px;line-height:1.55;color:#3B3E45;font-variant-numeric:tabular-nums;transform:rotate(-1deg)">
                <div style="text-align:center;font-weight:700;font-size:11px;font-family:Inter,sans-serif;color:#15171C">PAPPERSLÅDAN AB</div>
                <div style="text-align:center;color:#8A8D94">Storgatan 1, Exempelstad</div>
                <div style="border-top:1px dashed #CFCBC2;margin:8px 0"></div>
                <div style="display:flex;justify-content:space-between"><span>Kopieringspapper</span><span>720,00</span></div>
                <div style="display:flex;justify-content:space-between"><span>Pärmar 10 st</span><span>480,00</span></div>
                <div style="display:flex;justify-content:space-between"><span>Pennor, mix</span><span>400,00</span></div>
                <div style="border-top:1px dashed #CFCBC2;margin:8px 0"></div>
                <div style="display:flex;justify-content:space-between"><span>Netto</span><span>1 600,00</span></div>
                <div style="display:flex;justify-content:space-between"><span>Moms 25 %</span><span>400,00</span></div>
                <div style="display:flex;justify-content:space-between;font-weight:700;color:#15171C"><span>TOTALT</span><span>2 000,00</span></div>
                <div style="border-top:1px dashed #CFCBC2;margin:8px 0"></div>
                <div style="text-align:center;color:#8A8D94">2026-10-14 · Kort ****4417</div>
              </div>
              <div style="font-size:10.5px;color:#6A6E77;display:flex;justify-content:space-between"><span>kvitto_1014.pdf</span><span>84 kB</span></div>
              <div style="margin-top:auto;padding:10px;border-radius:8px;background:#FFE6DF;font-size:10.5px;line-height:1.45;color:#15171C">Belopp och moms tolkade från kvittot.</div>
            </div>
          </div>
        </div>
        <div style="position:absolute;left:730px;top:160px;width:250px;height:480px;border-radius:38px;background:#15171C;padding:9px;box-shadow:0 40px 70px -24px rgba(21,23,28,.55);transform:rotate(4deg)">
          <div style="width:100%;height:100%;border-radius:30px;background:#F7F5F0;overflow:hidden;display:flex;flex-direction:column;color:#15171C;font-family:Inter,sans-serif">
            <div style="height:30px;flex:none;display:flex;align-items:center;justify-content:space-between;padding:0 20px;font-size:10.5px;font-weight:600"><span>09:41</span><span style="width:70px;height:18px;border-radius:999px;background:#15171C"></span><span>100 %</span></div>
            <div style="padding:10px 16px 0;font-size:10.5px;color:#6A6E77">Exempelbolaget AB</div>
            <div style="padding:2px 16px 12px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:19px;letter-spacing:-0.02em">Översikt</div>
            <div style="margin:0 12px;padding:14px;border-radius:16px;background:#15171C;color:#F7F5F0">
              <div style="font-size:10px;color:#B5B9C1">Företagskonto 1930</div>
              <div style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:22px;letter-spacing:-0.02em;font-variant-numeric:tabular-nums;margin-top:2px">184 320 kr</div>
              <div style="display:flex;gap:6px;margin-top:10px"><span style="padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.1);font-size:9.5px">Moms okt: 12 450 kr</span></div>
            </div>
            <div style="padding:16px 16px 6px;font-size:11px;font-weight:700">Senaste verifikationer</div>
            <div style="margin:0 12px;background:#FFFFFF;border-radius:14px;font-size:11px;font-variant-numeric:tabular-nums">
              <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid #EFECE6"><span style="width:26px;height:26px;border-radius:8px;background:#FFE6DF;display:grid;place-items:center;font-size:9px;font-weight:700">A47</span><span style="flex:1;line-height:1.25"><span style="display:block;font-weight:600">Kontorsmaterial</span><span style="color:#6A6E77;font-size:9.5px">14 okt</span></span><span style="font-weight:600">−2 000 kr</span></div>
              <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid #EFECE6"><span style="width:26px;height:26px;border-radius:8px;background:#F0EEE8;display:grid;place-items:center;font-size:9px;font-weight:700">A46</span><span style="flex:1;line-height:1.25"><span style="display:block;font-weight:600">Betalning faktura 1032</span><span style="color:#6A6E77;font-size:9.5px">13 okt</span></span><span style="font-weight:600">+12 500 kr</span></div>
              <div style="display:flex;align-items:center;gap:8px;padding:10px 12px"><span style="width:26px;height:26px;border-radius:8px;background:#F0EEE8;display:grid;place-items:center;font-size:9px;font-weight:700">A45</span><span style="flex:1;line-height:1.25"><span style="display:block;font-weight:600">Lokalhyra november</span><span style="color:#6A6E77;font-size:9.5px">10 okt</span></span><span style="font-weight:600">−8 750 kr</span></div>
            </div>
            <div style="margin:auto 12px 14px;height:42px;border-radius:999px;background:#FF5A36;display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:700">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"></path><circle cx="12" cy="13" r="3.5"></circle></svg>Fota kvitto</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section aria-label="Regelverk och standarder" style="border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:var(--card)">
  <ul style="max-width:1240px;margin:0 auto;padding:22px clamp(20px,4vw,40px);list-style:none;display:flex;flex-wrap:wrap;justify-content:center;gap:14px 34px;font-size:14.5px;font-weight:600">
    @foreach($trust as $t)
      <li style="display:flex;align-items:center;gap:9px"><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="var(--accl)"></circle><path d="M7.5 12.5l3 3 6-6.5" fill="none" stroke="var(--acct)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path></svg>{{ $t }}</li>
    @endforeach
  </ul>
</section>

<section id="kor-sjalv" aria-labelledby="kor-rubrik" style="scroll-margin-top:68px;padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px)">
  <div style="max-width:1240px;margin:0 auto">
    <div data-reveal="" style="max-width:820px">
      <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Kör själv</p>
      <h2 id="kor-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5.6vw,72px);line-height:1;letter-spacing:-0.04em;text-wrap:balance">Ditt system. Där du vill ha det.</h2>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:20px;margin-top:clamp(40px,5vw,64px)">
      <article class="lp11" data-reveal="" style="background:var(--card);border:1px solid var(--line);border-radius:24px;padding:32px;display:flex;flex-direction:column;gap:18px;transition:transform .25s ease,box-shadow .25s ease">
        <div style="display:flex;align-items:center;justify-content:space-between"><span style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:15px;color:var(--muted);font-variant-numeric:tabular-nums">01</span><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="var(--accb)" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"></path></svg></div>
        <h3 style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:30px;letter-spacing:-0.03em">Webbhotell.</h3>
        <p style="margin:0;font-size:16.5px;line-height:1.55;color:var(--muted);text-wrap:pretty">Installera på ett webbhotell med PHP och PostgreSQL. Ladda upp, öppna installationsguiden, klart.</p>
        <ol style="margin:auto 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px">
          <li style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:var(--soft);font-size:15px;font-weight:500"><span style="flex:none;width:26px;height:26px;border-radius:50%;background:var(--ink);color:var(--bg);display:grid;place-items:center;font-size:12.5px;font-weight:700">1</span>Ladda ner zip-filen</li>
          <li style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:var(--soft);font-size:15px;font-weight:500"><span style="flex:none;width:26px;height:26px;border-radius:50%;background:var(--ink);color:var(--bg);display:grid;place-items:center;font-size:12.5px;font-weight:700">2</span>Ladda upp till ditt webbhotell</li>
          <li style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:var(--soft);font-size:15px;font-weight:500"><span style="flex:none;width:26px;height:26px;border-radius:50%;background:var(--ink);color:var(--bg);display:grid;place-items:center;font-size:12.5px;font-weight:700">3</span>Följ installationsguiden i webbläsaren</li>
        </ol>
      </article>
      <article class="lp12" data-reveal="" style="background:var(--card);border:1px solid var(--line);border-radius:24px;padding:32px;display:flex;flex-direction:column;gap:18px;transition:transform .25s ease,box-shadow .25s ease">
        <div style="display:flex;align-items:center;justify-content:space-between"><span style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:15px;color:var(--muted);font-variant-numeric:tabular-nums">02</span><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="var(--accb)" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h18v7H3zM3 13h18v7H3zM7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6" stroke-linecap="round"></path></svg></div>
        <h3 style="margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:30px;letter-spacing:-0.03em">Egen server.<span style="padding:4px 11px;border-radius:999px;background:var(--accl);color:var(--acct);font-family:Inter,sans-serif;font-size:12.5px;font-weight:700;letter-spacing:0">Docker kommer snart</span></h3>
        <p style="margin:0;font-size:16.5px;line-height:1.55;color:var(--muted);text-wrap:pretty">Kör NONOX på din egen server eller hos valfri molnleverantör, med PHP och PostgreSQL. Ett färdigt Docker-paket kommer snart.</p>
        <div style="margin-top:auto;border-radius:14px;background:#15171C;color:#F3F1EC;overflow:hidden">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 8px 8px 14px;border-bottom:1px solid rgba(255,255,255,.08)">
            <span style="font-size:12px;color:#A6AAB3;font-family:ui-monospace,Menlo,monospace">terminal</span>
            <button class="lp13" type="button" data-lp-copy="{{ $dockerCommands }}" aria-label="Kopiera kommandon" style="display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:transparent;color:#F3F1EC;font:600 12.5px Inter,sans-serif;cursor:pointer"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"></rect><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"></path></svg><span aria-live="polite" data-lp-copy-label>Kopiera</span></button>
          </div>
          <pre style="margin:0;padding:16px 14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13.5px;line-height:1.75;white-space:pre-wrap;word-break:break-all"><code><span style="color:#FF8A6D">$</span> git clone {{ $ph['github'] }}
<span style="color:#8E929B"># Docker-paket: kommer snart</span></code></pre>
        </div>
      </article>
      <article class="lp14" data-reveal="" style="background:var(--card);border:1px solid var(--line);border-radius:24px;padding:32px;display:flex;flex-direction:column;gap:18px;transition:transform .25s ease,box-shadow .25s ease">
        <div style="display:flex;align-items:center;justify-content:space-between"><span style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:15px;color:var(--muted);font-variant-numeric:tabular-nums">03</span><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="var(--accb)" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><path d="M4 5h16v11H4zM2 19h20M10 16v3M14 16v3"></path></svg></div>
        <h3 style="margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:30px;letter-spacing:-0.03em">Lokalt på datorn.<span style="padding:4px 11px;border-radius:999px;background:var(--accl);color:var(--acct);font-family:Inter,sans-serif;font-size:12.5px;font-weight:700;letter-spacing:0">Kommer snart</span></h3>
        <p style="margin:0;font-size:16.5px;line-height:1.55;color:var(--muted);text-wrap:pretty">Snart kan du installera NONOX som ett program på Windows, Mac och Linux. Din bokföring ligger då på din egen dator.</p>
        <div style="margin-top:auto;display:flex;flex-direction:column;gap:10px">
          <span style="display:flex;align-items:center;justify-content:space-between;min-height:52px;padding:0 18px;border-radius:14px;border:1.5px dashed var(--line);color:var(--muted);font-size:15.5px;font-weight:600">Windows<span style="font-size:12.5px;font-weight:600">Kommer snart</span></span>
          <span style="display:flex;align-items:center;justify-content:space-between;min-height:52px;padding:0 18px;border-radius:14px;border:1.5px dashed var(--line);color:var(--muted);font-size:15.5px;font-weight:600">Mac<span style="font-size:12.5px;font-weight:600">Kommer snart</span></span>
          <span style="display:flex;align-items:center;justify-content:space-between;min-height:52px;padding:0 18px;border-radius:14px;border:1.5px dashed var(--line);color:var(--muted);font-size:15.5px;font-weight:600">Linux<span style="font-size:12.5px;font-weight:600">Kommer snart</span></span>
        </div>
      </article>
    </div>
    <a class="lp17" data-reveal="" href="#priser" style="margin-top:20px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;padding:28px 32px;border-radius:24px;background:var(--accl);color:var(--ink);text-decoration:none;transition:background .2s">
      <span style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(22px,2.6vw,30px);letter-spacing:-0.025em">Vill du slippa allt det här? Låt oss sköta driften →</span>
      <span style="font-size:15px;font-weight:600">Från 995 kr/år</span>
    </a>
  </div>
</section>

<section id="funktioner" aria-labelledby="funk-rubrik" style="scroll-margin-top:68px;padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px);background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div style="max-width:1240px;margin:0 auto">
    <div data-reveal="" style="max-width:900px">
      <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Funktioner</p>
      <h2 id="funk-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5.6vw,72px);line-height:1;letter-spacing:-0.04em;text-wrap:balance">Allt ett svenskt företag behöver. Inget du inte bett om.</h2>
    </div>
    <div class="fs-list">
      @foreach($features as $f)
        @php([$manSection, $manArticle] = explode('/', $f['man']))
        @php($phone = str_starts_with($f['img'], 'personal/'))
        <article class="fs-row{{ $loop->odd ? '' : ' fs-flip' }}" data-fs="">
          <div class="fs-text">
            <span style="width:48px;height:48px;border-radius:14px;background:var(--accl);display:grid;place-items:center;margin-bottom:20px"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $f['d'] }}"></path></svg></span>
            <h3 style="margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(26px,3vw,36px);line-height:1.1;letter-spacing:-0.03em">{{ $f['t'] }}@if($f['mod'] ?? false)<span style="padding:3px 9px;border-radius:999px;border:1px solid var(--line);font-family:Inter,sans-serif;font-size:12px;font-weight:600;letter-spacing:0;color:var(--muted)">Modul</span>@endif@if($f['new'] ?? false)<span style="padding:3px 9px;border-radius:999px;background:#FF5A36;font-family:Inter,sans-serif;font-size:12px;font-weight:700;letter-spacing:0;color:#15171C">Ny</span>@endif@if($f['soon'] ?? false)<span style="padding:3px 9px;border-radius:999px;background:var(--accl);font-family:Inter,sans-serif;font-size:12px;font-weight:700;letter-spacing:0;color:var(--acct)">Kommer snart</span>@endif</h3>
            <p style="margin:16px 0 0;font-size:17px;line-height:1.6;color:var(--muted);text-wrap:pretty;max-width:46ch">{{ $f['b'] }}</p>
            <a href="{{ $manArticle === App\Support\Manual\Manual::OVERVIEW ? route('manual.section', $manSection) : route('manual.article', [$manSection, $manArticle]) }}" class="cmp-link" style="margin-top:22px;font-size:15px;font-weight:600">Läs mer i manualen →</a>
          </div>
          <figure class="fs-shot{{ $phone ? ' fs-phone' : '' }}" style="margin:0">
            <div class="fs-frame">
              <div class="fs-bar" aria-hidden="true"><i></i><i></i><i></i></div>
              <img src="{{ asset('bilder/manual/'.$f['img'].'.webp') }}" width="{{ $phone ? 390 : 1280 }}" height="{{ $phone ? 844 : 800 }}" loading="lazy" decoding="async" alt="Skärmbild från NONOX: {{ $f['t'] }}">
            </div>
          </figure>
        </article>
      @endforeach
    </div>
    <p style="margin:clamp(56px,7vw,96px) 0 0;font-size:14.5px;color:var(--muted)">Skärmbilderna är tagna i NONOX med påhittade demoföretag.</p>
    <p style="margin:8px 0 0;font-size:14.5px;color:var(--muted)">Moduler slås på och av per företag under <strong style="color:var(--ink);font-weight:600">Inställningar → Moduler</strong>.</p>
    <nav aria-label="Branscher" style="margin-top:28px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-size:14.5px;font-weight:600">
      <span style="margin-right:4px;color:var(--muted);font-weight:500">Se hur NONOX passar din bransch:</span>
      @foreach(App\Support\Landing\Industries::all() as $branch)
        @continue($branch['group'] !== 'foretag')
        <a href="{{ route('industry', $branch['slug']) }}" class="cmp-link">{{ $branch['name'] }}</a>
      @endforeach
      <a href="{{ route('industries') }}" class="cmp-link">Alla branscher →</a>
    </nav>
  </div>
</section>

<section id="byraer" aria-labelledby="byra-rubrik" style="scroll-margin-top:68px;padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px)">
  <div style="max-width:1240px;margin:0 auto">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:clamp(32px,5vw,72px);align-items:end">
      <div data-reveal="">
        <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">För byråer, koncerner och dig med flera bolag</p>
        <h2 id="byra-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5.6vw,72px);line-height:1;letter-spacing:-0.04em;text-wrap:balance">Alla dina bolag. En skärm.</h2>
      </div>
      <div data-reveal="">
        <p style="margin:0;font-size:18px;line-height:1.55;color:var(--muted);text-wrap:pretty">Driver du flera bolag, har en koncern eller är redovisningsbyrå: se bokföring, bankavstämning, moms, arbetsgivardeklaration och obetalda fakturor för varje bolag, månad för månad, och byt mellan dem med ett klick.</p>
        <p style="margin:18px 0 0;display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 12px"><span style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(32px,3.6vw,44px);letter-spacing:-0.035em;color:var(--accb);font-variant-numeric:tabular-nums">0 kr</span><span style="font-size:16px;font-weight:600">i egen drift, oavsett hur många bolag du har.</span></p>
      </div>
    </div>
    <div data-reveal="" aria-hidden="true" style="margin-top:clamp(40px,5vw,64px);border-radius:20px;border:1px solid rgba(21,23,28,.12);background:#FFFFFF;color:#15171C;box-shadow:0 40px 80px -40px rgba(21,23,28,.35);overflow:hidden">
      <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;padding:18px 22px;border-bottom:1px solid #ECE9E2;background:#FAF9F6">
        <div>
          <div style="font-size:11px;color:#6A6E77;font-weight:500">Alla bolag · Exempelgruppen AB</div>
          <div style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:20px;letter-spacing:-0.02em">Bolag, oktober 2026</div>
        </div>
        <div style="margin-left:auto;display:flex;gap:6px;font-size:12px;font-weight:600">
          <span style="padding:6px 11px;border-radius:999px;background:#F0EEE8">Aug</span>
          <span style="padding:6px 11px;border-radius:999px;background:#F0EEE8">Sep</span>
          <span style="padding:6px 11px;border-radius:999px;background:#15171C;color:#F7F5F0">Okt</span>
          <span style="padding:6px 11px;border-radius:999px;background:#F0EEE8">Nov</span>
        </div>
      </div>
      <div style="overflow-x:auto">
        <div style="min-width:820px;font-size:13px;font-variant-numeric:tabular-nums">
          <div style="display:grid;grid-template-columns:2fr 1.1fr 1fr 1fr 1fr 1fr;gap:12px;padding:11px 22px;color:#6A6E77;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em"><span>Bolag</span><span>Bokfört t.o.m.</span><span>Bankavstämning</span><span>Moms</span><span>AGI</span><span style="text-align:right">Obetalda kundfakt.</span></div>
          @foreach($clients as $c)
            <div style="display:grid;grid-template-columns:2fr 1.1fr 1fr 1fr 1fr 1fr;gap:12px;align-items:center;padding:13px 22px;border-top:1px solid #EFECE6">
              <span style="display:flex;align-items:center;gap:10px;font-weight:600"><span style="width:28px;height:28px;border-radius:8px;background:#F0EEE8;display:grid;place-items:center;font-size:11px;font-weight:800">{{ $c['ini'] }}</span>{{ $c['name'] }}</span>
              <span>{{ $c['booked'] }}</span>
              <span style="justify-self:start;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:600;background:{{ $c['bankBg'] }}">{{ $c['bank'] }}</span>
              <span style="justify-self:start;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:600;background:{{ $c['momsBg'] }}">{{ $c['moms'] }}</span>
              <span style="justify-self:start;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:600;background:{{ $c['agiBg'] }}">{{ $c['agi'] }}</span>
              <span style="text-align:right;font-weight:600">{{ $c['open'] }}</span>
            </div>
          @endforeach
        </div>
      </div>
    </div>
    <ul style="list-style:none;margin:28px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:12px 32px;font-size:16px;line-height:1.5">
      <li data-reveal="" style="display:flex;gap:10px"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:none;margin-top:3px"><path d="M5 12.5l4.5 4.5L19 7"></path></svg>Alla bolag under en inloggning</li>
      <li data-reveal="" style="display:flex;gap:10px"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:none;margin-top:3px"><path d="M5 12.5l4.5 4.5L19 7"></path></svg>Status för varje bolag, månad för månad</li>
      <li data-reveal="" style="display:flex;gap:10px"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:none;margin-top:3px"><path d="M5 12.5l4.5 4.5L19 7"></path></svg>Inga licenskostnader per bolag i egen drift</li>
    </ul>
    <nav aria-label="För byråer och konsulter" style="margin-top:28px;display:flex;flex-wrap:wrap;gap:10px;font-size:14.5px;font-weight:600">
      <a href="{{ route('industry', 'for-redovisningsbyraer') }}" class="cmp-link">För redovisningsbyråer →</a>
      <a href="{{ route('industry', 'for-ekonomikonsulter') }}" class="cmp-link">För ekonomikonsulter →</a>
    </nav>
  </div>
</section>

<section aria-labelledby="data-rubrik" style="padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px);background:var(--dark);color:#F7F5F0">
  <div style="max-width:1240px;margin:0 auto">
    <h2 id="data-rubrik" data-reveal="" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(48px,9vw,128px);line-height:0.92;letter-spacing:-0.05em">Din data. <span style="color:#FF5A36">Alltid.</span></h2>
    <ul style="list-style:none;margin:clamp(48px,6vw,80px) 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:clamp(28px,4vw,48px)">
      <li data-reveal="" style="border-top:2px solid #FF5A36;padding-top:22px;font-size:18px;line-height:1.55;color:#D9D7D1;text-wrap:pretty"><span style="display:block;margin-bottom:10px;font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#FF8A6D">Export</span>Exportera allt, när som helst: SIE4, underlag, fakturor och lönehistorik.</li>
      <li data-reveal="" style="border-top:2px solid #FF5A36;padding-top:22px;font-size:18px;line-height:1.55;color:#D9D7D1;text-wrap:pretty"><span style="display:block;margin-bottom:10px;font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#FF8A6D">Oföränderligt</span>Bokförda verifikationer kan aldrig ändras i efterhand. Full behandlingshistorik enligt Bokföringslagen.</li>
      <li data-reveal="" style="border-top:2px solid #FF5A36;padding-top:22px;font-size:18px;line-height:1.55;color:#D9D7D1;text-wrap:pretty"><span style="display:block;margin-bottom:10px;font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#FF8A6D">Granskningsbart</span>Koden är öppen. Vem som helst kan granska exakt hur dina siffror hanteras.</li>
    </ul>
    <blockquote data-reveal="" style="margin:clamp(64px,9vw,120px) 0 0;padding:0;display:grid;grid-template-columns:auto 1fr;gap:clamp(16px,3vw,36px);align-items:start">
      <svg width="64" height="64" viewBox="0 0 24 24" aria-hidden="true" style="width:clamp(40px,6vw,72px);height:auto"><path fill="#FF5A36" d="M23.47 11.13 A11.5 11.5 0 1 0 23.47 12.87 L19.63 12.87 A7.68 7.68 0 1 1 19.63 11.13 Z"></path></svg>
      <p style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(28px,4.4vw,56px);line-height:1.08;letter-spacing:-0.035em;text-wrap:balance">”Kärnan i NONOX är öppen källkod. Det kommer den alltid att vara.”</p>
    </blockquote>
  </div>
</section>

<section id="sakerhet" aria-labelledby="sak-rubrik" style="scroll-margin-top:68px;padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px)">
  <div style="max-width:1240px;margin:0 auto">
    <p style="margin:0 0 14px;font-family:ui-monospace,Menlo,monospace;font-size:13px;color:var(--acct)">Senast granskat {{ \Carbon\CarbonImmutable::parse($securityReview['reviewed_at'])->locale('sv')->isoFormat('D MMMM YYYY') }}</p>
    <h2 id="sak-rubrik" data-reveal="" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,6vw,72px);line-height:1;letter-spacing:-0.045em;text-wrap:balance">Öppen källkod får inte betyda osäkert.</h2>
    <p style="margin:22px 0 0;max-width:760px;font-size:clamp(17px,2vw,20px);line-height:1.6;color:var(--muted)">Vi granskar säkerheten och hur NONOX följer GDPR, Bokföringslagen, AI-förordningen och andra regelverk, och redovisar resultatet öppet: betyg per område, vad som är klart och vad som återstår. {{ $securityTotals['fixed'] }} av {{ $securityTotals['found'] }} fynd i senaste granskningen är rättade.</p>
    <ul style="list-style:none;margin:32px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,250px),1fr));gap:12px">
      @foreach($securityReview['areas'] as $area)
        <li style="display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px"><span style="flex:none;display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:var(--soft);font:800 17px InterDisplay,Inter,sans-serif" aria-label="Betyg {{ $area['grade'] }}">{{ $area['grade'] }}</span><span style="font-size:15px;font-weight:600">{{ $area['name'] }}</span></li>
      @endforeach
    </ul>
    <p style="margin:28px 0 0"><a href="{{ route('security') }}" style="font-weight:700;color:var(--acct)">Läs hela granskningen och vad som återstår →</a></p>
  </div>
</section>

<section id="priser" aria-labelledby="pris-rubrik" style="scroll-margin-top:68px;padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px)">
  <div style="max-width:1240px;margin:0 auto">
    <div data-reveal="" style="max-width:900px">
      <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Priser</p>
      <h2 id="pris-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5.6vw,72px);line-height:1;letter-spacing:-0.04em;text-wrap:balance">Gratis att köra själv. Prisvärt att låta oss köra.</h2>
    </div>
    <div data-reveal="" style="margin-top:clamp(40px,5vw,64px);display:flex;flex-wrap:wrap;align-items:center;gap:24px 40px;padding:clamp(28px,4vw,44px);border-radius:28px;background:var(--ink);color:var(--bg)">
      <div style="flex:1 1 320px">
        <div style="display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 18px">
          <h3 style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(28px,3.4vw,40px);letter-spacing:-0.03em">Kör själv</h3>
          <span style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,5vw,64px);letter-spacing:-0.04em;color:#FF5A36;font-variant-numeric:tabular-nums">0 kr</span>
        </div>
        <p style="margin:10px 0 0;font-size:17px;line-height:1.55;opacity:.8;max-width:560px">Ladda ner och installera var du vill. Du ansvarar för drift och backup.</p>
      </div>
      <a class="lp19" href="#kor-sjalv" style="display:inline-flex;align-items:center;gap:10px;min-height:54px;padding:0 28px;border-radius:999px;background:#FF5A36;color:#15171C;text-decoration:none;font-size:16.5px;font-weight:700"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"></path></svg>Ladda ner</a>
    </div>
    <div style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px 24px;margin:56px 0 20px">
      <h3 style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(24px,2.6vw,30px);letter-spacing:-0.025em">Vi sköter driften</h3>
      <p style="margin:0;font-size:14.5px;color:var(--muted)">Pris per företag och år, exkl. moms. Alla moduler ingår. NONOX Connect (Peppol, Skatteverket, bank) är ett tillägg som kostar extra: leverantörens pris + 10 %, från 2027-01-11.</p>
    </div>
    <p style="margin:0 0 20px;max-width:760px;font-size:14.5px;line-height:1.6;color:var(--muted);text-wrap:pretty">Tillägg som kostar extra från 2027-01-11, när Peppol, Skatteverket och bank är påslagna: <strong style="color:var(--ink)">NONOX Connect</strong>, ett separat tillägg för kopplingar som våra leverantörer tar betalt för, som Peppol, Skatteverkets tjänster och bank. Priset är leverantörens pris plus 10 %, och vi visar båda delarna var för sig. Modulerna ingår som förut, och NONOX API (API-nycklar och webhooks) är fortfarande en modul. AI-assistenter via MCP kommer, men är inte tillgängliga ännu.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:14px">
      @foreach($plans as $p)
        <article class="lp20" data-reveal="" style="position:relative;background:var(--card);border:{{ $p['border'] }};border-radius:22px;padding:26px 22px 22px;display:flex;flex-direction:column;gap:6px;transition:transform .25s ease,box-shadow .25s ease">
          @if($p['popular'])<span style="position:absolute;top:-12px;left:20px;padding:4px 11px;border-radius:999px;background:#FF5A36;color:#15171C;font-size:12px;font-weight:700">Populärast</span>@endif
          <h4 style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:22px;letter-spacing:-0.02em">{{ $p['name'] }}</h4>
          <p style="margin:0;font-size:14px;color:var(--muted);font-variant-numeric:tabular-nums">{{ $p['emp'] }}</p>
          <p style="margin:22px 0 0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:{{ $p['priceSize'] }};line-height:1.05;letter-spacing:-0.035em;font-variant-numeric:tabular-nums;min-height:46px">{{ $p['price'] }}<span style="font-family:Inter,sans-serif;font-weight:500;font-size:14px;letter-spacing:0;color:var(--muted)">{{ $p['unit'] }}</span></p>
          <a class="lp21" href="{{ $p['href'] }}" style="margin-top:20px;display:flex;align-items:center;justify-content:center;min-height:46px;border-radius:999px;background:{{ $p['btnBg'] }};color:{{ $p['btnFg'] }};border:1.5px solid var(--ink);text-decoration:none;font-size:15px;font-weight:700">{{ $p['cta'] }}</a>
        </article>
      @endforeach
    </div>
    <div data-reveal="" style="margin-top:14px;padding:22px 24px;border-radius:22px;border:1px solid var(--line);display:flex;flex-wrap:wrap;align-items:center;gap:12px 28px">
      <span style="font-size:14.5px;font-weight:700">Alla driftpaket innehåller</span>
      <ul style="list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:10px 22px;font-size:14.5px;color:var(--muted)">
        @foreach($includes as $i)
          <li style="display:flex;align-items:center;gap:7px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--acct)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"></path></svg>{{ $i }}</li>
        @endforeach
      </ul>
    </div>
    <p style="margin:16px 0 0;font-size:13.5px;color:var(--muted)">Antal anställda avser anställda som får lön under månaden. Ingen bindningstid.</p>
  </div>
</section>

<section id="kalkylator" aria-labelledby="kalk-rubrik" style="scroll-margin-top:68px;padding:0 clamp(20px,4vw,40px) clamp(80px,11vw,150px)">
  <div style="max-width:1240px;margin:0 auto">
    <div data-reveal="" style="background:var(--card);border:1px solid var(--line);border-radius:32px;padding:clamp(28px,5vw,64px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,400px),1fr));gap:clamp(32px,5vw,64px);align-items:center">
      <div>
        <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Räkna själv</p>
        <h2 id="kalk-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(34px,4.6vw,60px);line-height:1;letter-spacing:-0.04em">Vad skulle du spara?</h2>
        <div style="margin-top:40px">
          <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px">
            <label for="anstallda" style="font-size:15px;font-weight:600">Antal anställda</label>
            <output for="anstallda" data-lp-label style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:40px;letter-spacing:-0.03em;font-variant-numeric:tabular-nums">{{ $calculator['label'] }}</output>
          </div>
          <input id="anstallda" type="range" min="0" max="26" step="1" value="{{ $calculator['employees'] }}" data-lp-employees aria-valuetext="{{ $calculator['aria'] }}" style="width:100%;height:32px;margin-top:10px;cursor:pointer">
          <div style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--muted);font-variant-numeric:tabular-nums"><span>0</span><span>5</span><span>10</span><span>15</span><span>20</span><span>26+</span></div>
        </div>
      </div>
      <div aria-live="polite" style="display:flex;flex-direction:column;gap:14px">
        <div data-lp-ok style="display:flex;flex-direction:column;gap:14px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div style="padding:20px;border-radius:18px;background:var(--soft)">
              <div style="font-size:13.5px;color:var(--muted);font-weight:500">NONOX, <span data-lp-plan>{{ $calculator['plan'] }}</span></div>
              <div style="margin-top:6px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(24px,2.6vw,32px);letter-spacing:-0.03em;font-variant-numeric:tabular-nums"><span data-lp-price>{{ $calculator['price'] }}</span></div>
            </div>
            <div style="padding:20px;border-radius:18px;border:1px solid var(--line)">
              <div style="font-size:13.5px;color:var(--muted);font-weight:500">Den största molntjänsten</div>
              <div style="margin-top:6px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(24px,2.6vw,32px);letter-spacing:-0.03em;font-variant-numeric:tabular-nums;color:var(--muted)"><span data-lp-compare>{{ $calculator['compare'] }}</span></div>
            </div>
          </div>
          <div style="padding:26px 24px;border-radius:22px;background:var(--accl)">
            <div style="font-size:15px;font-weight:600">Beräknad besparing per år</div>
            <div style="margin-top:4px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(52px,7vw,88px);line-height:1;letter-spacing:-0.045em;color:var(--accb);font-variant-numeric:tabular-nums"><span data-lp-saving>{{ $calculator['saving'] }}</span></div>
          </div>
        </div>
        <div data-lp-contact hidden>
          <div style="padding:32px 28px;border-radius:22px;background:var(--accl);display:flex;flex-direction:column;align-items:flex-start;gap:18px">
            <div style="font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(30px,3.6vw,44px);letter-spacing:-0.035em">Kontakta oss för ett pris.</div>
            <a class="lp22" href="{{ $ph['contact'] }}" style="display:inline-flex;align-items:center;min-height:48px;padding:0 22px;border-radius:999px;background:var(--ink);color:var(--bg);text-decoration:none;font-weight:700">Kontakta oss</a>
          </div>
        </div>
        <p style="margin:4px 0 0;font-size:13px;line-height:1.5;color:var(--muted);text-wrap:pretty">Uppskattning baserad på offentliga listpriser för bokföring, fakturering och lön i oktober 2026, exkl. moms, utan tillägg. Ditt faktiska pris kan skilja sig.</p>
      </div>
    </div>
  </div>
</section>

<section id="jamfor" aria-labelledby="jamfor-rubrik" style="scroll-margin-top:68px;padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px)">
  <div style="max-width:1240px;margin:0 auto">
    <div data-reveal="" style="max-width:900px">
      <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Jämför</p>
      <h2 id="jamfor-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5.6vw,72px);line-height:1;letter-spacing:-0.04em;text-wrap:balance">Ett gratis alternativ till Fortnox, Visma och Bokio.</h2>
      <p style="margin:24px 0 0;max-width:720px;font-size:18px;line-height:1.55;color:var(--muted);text-wrap:pretty">Samma grund som de stora ekonomisystemen: bokföring, fakturering, lön, moms och bokslut. Skillnaden är att NONOX är öppen källkod, att alla moduler ingår (kopplingarna i tillägget NONOX Connect kostar extra från 2027-01-11) och att du kan köra det själv utan kostnad.</p>
    </div>
    <div data-reveal="" style="margin-top:clamp(36px,5vw,56px)">
      @include('start.partials.comparison', ['highlight' => null])
    </div>
    <nav aria-label="Alternativ till" style="margin-top:28px;display:flex;flex-wrap:wrap;gap:10px;font-size:14.5px;font-weight:600">
      @foreach(App\Support\Landing\Comparison::others() as $slug)
        <a href="{{ route('alternative', $slug) }}" class="cmp-link">Alternativ till {{ App\Support\Landing\Comparison::program($slug)['name'] ?? $slug }} →</a>
      @endforeach
    </nav>
  </div>
</section>

<section id="byt-system" aria-labelledby="byt-rubrik" style="scroll-margin-top:68px;padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px);background:var(--card);border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
  <div style="max-width:1240px;margin:0 auto">
    <div data-reveal="" style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:24px">
      <div style="max-width:820px">
        <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Byt system på en eftermiddag</p>
        <h2 id="byt-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5.6vw,72px);line-height:1;letter-spacing:-0.04em;text-wrap:balance">Flytta hit utan att tappa något.</h2>
      </div>
      <a class="lp23" href="{{ $ph['flytt'] }}" style="display:inline-flex;align-items:center;gap:8px;min-height:50px;padding:0 22px;border-radius:999px;border:1.5px solid var(--ink);color:var(--ink);text-decoration:none;font-size:15.5px;font-weight:600">Läs hela flyttguiden →</a>
    </div>
    <ol style="list-style:none;margin:clamp(48px,6vw,80px) 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:32px 20px">
      @foreach($steps as $s)
        <li data-reveal="" style="display:flex;flex-direction:column;gap:20px">
          <div style="display:flex;align-items:center;gap:14px">
            <span style="flex:none;width:52px;height:52px;border-radius:50%;background:{{ $s['bg'] }};color:{{ $s['fg'] }};border:2px solid var(--ink);display:grid;place-items:center;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:20px">{{ $s['n'] }}</span>
            <span style="flex:1;height:2px;background:repeating-linear-gradient(90deg,var(--ink) 0 6px,transparent 6px 12px);opacity:{{ $s['lineOp'] }}"></span>
          </div>
          <p style="margin:0;font-size:17px;line-height:1.55;text-wrap:pretty">{{ $s['t'] }}</p>
        </li>
      @endforeach
    </ol>
  </div>
</section>

<section id="oppen-kallkod" aria-labelledby="os-rubrik" style="scroll-margin-top:68px;padding:clamp(80px,11vw,150px) clamp(20px,4vw,40px)">
  <div style="max-width:1240px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:clamp(40px,6vw,80px);align-items:center">
    <div data-reveal="">
      <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Öppen källkod · AGPL-3.0</p>
      <h2 id="os-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5.6vw,72px);line-height:1;letter-spacing:-0.04em">Byggd i det öppna.</h2>
      <p style="margin:24px 0 0;max-width:520px;font-size:18px;line-height:1.55;color:var(--muted);text-wrap:pretty">NONOX är licensierat under AGPL-3.0. Läs koden, rapportera buggar, föreslå förbättringar eller bygg egna moduler.</p>
      <p style="margin:14px 0 0;max-width:520px;font-size:18px;line-height:1.55;color:var(--muted);text-wrap:pretty">Läser du hellre än kodar? I <a href="{{ route('changelog') }}">ändringsloggen</a> står allt vi har levererat, med datum och klockslag, och på <a href="{{ route('roadmap') }}">färdplanen</a> det vi bygger härnäst.</p>
      <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:32px">
        <a class="lp24" href="{{ $ph['github'] }}" style="display:inline-flex;align-items:center;gap:9px;min-height:52px;padding:0 24px;border-radius:999px;background:var(--ink);color:var(--bg);text-decoration:none;font-size:15.5px;font-weight:700"><svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5a11.5 11.5 0 0 0-3.64 22.41c.58.1.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.53-1.33-1.28-1.69-1.28-1.69-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.68 0-1.26.45-2.28 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.77 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.83 1.19 3.09 0 4.41-2.69 5.39-5.26 5.67.41.36.78 1.06.78 2.14v3.17c0 .31.21.67.8.56A11.5 11.5 0 0 0 12 .5z"></path></svg>Se koden på GitHub</a>
        <a class="lp25" href="{{ $ph['docs'] }}" style="display:inline-flex;align-items:center;min-height:52px;padding:0 24px;border-radius:999px;border:1.5px solid var(--ink);color:var(--ink);text-decoration:none;font-size:15.5px;font-weight:600">Läs dokumentationen</a>
      </div>
    </div>
    <div data-reveal="" aria-hidden="true" style="border-radius:22px;background:#15171C;color:#E9E7E2;box-shadow:0 40px 80px -36px rgba(21,23,28,.5);overflow:hidden;transform:rotate(-1deg)">
      <div style="display:flex;align-items:center;gap:7px;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08)">
        <span style="width:10px;height:10px;border-radius:50%;background:#3A3D45"></span><span style="width:10px;height:10px;border-radius:50%;background:#3A3D45"></span><span style="width:10px;height:10px;border-radius:50%;background:#3A3D45"></span>
        <span style="margin-left:10px;font-family:ui-monospace,Menlo,monospace;font-size:12px;color:#8E929B">nonox — zsh</span>
      </div>
      <pre style="margin:0;padding:22px 20px 26px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:clamp(12px,1.3vw,14px);line-height:1.85;white-space:pre-wrap;word-break:break-word"><code><span style="color:#FF8A6D">~ $</span> git clone {{ $ph['github'] }} nonox
<span style="color:#FF8A6D">~ $</span> cd nonox &amp;&amp; ls app/Domain
Accounting  Accruals     AnnualReport  Assets
Banking     Closing      Company       IncomeTax
Insights    Invoicing    Payables      Vat
<span style="color:#FF8A6D">~/nonox $</span> cat LICENSE | head -1
<span style="color:#8E929B">GNU AFFERO GENERAL PUBLIC LICENSE</span>
<span style="color:#FF8A6D">~/nonox $</span> <span style="display:inline-block;width:9px;height:1.1em;vertical-align:-3px;background:#FF5A36"></span></code></pre>
    </div>
  </div>
</section>

<section id="fragor" aria-labelledby="faq-rubrik" style="scroll-margin-top:68px;padding:0 clamp(20px,4vw,40px) clamp(80px,11vw,150px)">
  <div style="max-width:1240px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:clamp(32px,5vw,80px);align-items:start;border-top:1px solid var(--line);padding-top:clamp(64px,8vw,110px)">
    <h2 id="faq-rubrik" data-reveal="" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5vw,64px);line-height:1;letter-spacing:-0.04em">Vanliga frågor</h2>
    <div style="grid-column:span 2;min-width:0;max-width:100%">
      @foreach($faqs as $q)
        <details style="border-bottom:1px solid var(--line)">
          <summary style="display:flex;align-items:center;justify-content:space-between;gap:20px;padding:24px 0;cursor:pointer;font-size:clamp(18px,1.8vw,21px);font-weight:650;letter-spacing:-0.01em">{{ $q['q'] }}<span class="faq-plus" aria-hidden="true" style="flex:none;width:36px;height:36px;border-radius:50%;border:1px solid var(--line);display:grid;place-items:center;transition:transform .25s ease"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 4v16M4 12h16"></path></svg></span></summary>
          <p style="margin:0;padding:0 56px 26px 0;font-size:16.5px;line-height:1.6;color:var(--muted);text-wrap:pretty">{{ $q['a'] }}</p>
        </details>
      @endforeach
    </div>
  </div>
</section>

<section aria-labelledby="cta-rubrik" style="padding:clamp(88px,12vw,160px) clamp(20px,4vw,40px);background:#FF5A36;color:#15171C;text-align:center;position:relative;overflow:hidden">
  <svg viewBox="0 0 24 24" aria-hidden="true" style="position:absolute;right:-12vw;top:50%;width:min(60vw,720px);height:auto;transform:translateY(-50%);opacity:.12"><path fill="#15171C" d="M23.47 11.13 A11.5 11.5 0 1 0 23.47 12.87 L19.63 12.87 A7.68 7.68 0 1 1 19.63 11.13 Z"></path></svg>
  <div data-reveal="" style="position:relative;max-width:980px;margin:0 auto">
    <h2 id="cta-rubrik" style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(44px,8vw,112px);line-height:0.95;letter-spacing:-0.05em;text-wrap:balance">Ta tillbaka din bokföring.</h2>
    <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin-top:44px">
      <a class="lp26" href="{{ auth()->check() ? route('dashboard') : App\Support\Landing\SignupPath::url() }}" style="display:inline-flex;align-items:center;gap:10px;min-height:56px;padding:0 28px;border-radius:999px;background:#15171C;color:#F7F5F0;text-decoration:none;font-size:17px;font-weight:700">{{ auth()->check() ? 'Till översikten' : App\Support\Landing\SignupPath::label() }}</a>
      <a class="lp27" href="#kor-sjalv" style="display:inline-flex;align-items:center;gap:10px;min-height:56px;padding:0 28px;border-radius:999px;border:2px solid #15171C;color:#15171C;text-decoration:none;font-size:17px;font-weight:700"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"></path></svg>Ladda ner gratis</a>
    </div>
  </div>
</section>

</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
</body>
</html>
