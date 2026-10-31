{{-- The cookie page: every cookie and browser storage NONOX uses (CookieInventory). --}}
@php
    $pageTitle = 'Cookies – NONOX';
    $pageDescription = 'Vilka cookies och vilken lokal lagring NONOX använder, varför och hur länge.';
    $categories = App\Support\Cookies\CookieInventory::categories();
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')
<main id="main" style="max-width:900px;margin:0 auto;padding:clamp(48px,8vw,96px) clamp(20px,4vw,40px)">
  <p style="margin:0 0 16px;font-size:13px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)">Cookies</p>
  <h1 style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(36px,5.6vw,64px);line-height:1;letter-spacing:-0.04em">Så använder vi cookies.</h1>
  <p style="margin:24px 0 0;font-size:18px;line-height:1.6;color:var(--muted)">En cookie är en liten textfil som webbplatsen sparar i din webbläsare. Enligt lagen om elektronisk kommunikation får vi bara använda cookies som inte är nödvändiga om du har samtyckt. NONOX använder i dag bara nödvändiga cookies: för att sidan och inloggningen ska fungera och för att skydda formulär. Vi använder ingen spårning, ingen statistik från tredje part och ingen annonsering, och typsnitten laddas från vår egen server.</p>
  <p style="margin:16px 0 0"><a href="#" data-cookie-settings style="display:inline-flex;align-items:center;min-height:46px;padding:0 22px;border-radius:999px;background:var(--ink);color:var(--bg);text-decoration:none;font-weight:700">Ändra cookie-inställningar</a></p>

  @foreach($categories as $category)
    <section style="margin-top:44px">
      <h2 style="margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:26px;letter-spacing:-0.02em">{{ $category['name'] }}@if($category['required']) <span style="font-family:Inter,sans-serif;font-size:13px;font-weight:600;color:var(--muted)">alltid på</span>@endif</h2>
      <p style="margin:8px 0 0;font-size:16px;line-height:1.6;color:var(--muted)">{{ $category['description'] }}</p>
      @if($category['items'] !== [])
        <div style="margin-top:16px;overflow-x:auto;border:1px solid var(--line);border-radius:18px;background:var(--card)">
          <table style="width:100%;min-width:640px;border-collapse:collapse;font-size:14.5px">
            <thead><tr style="text-align:left;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em"><th style="padding:12px 16px">Namn</th><th style="padding:12px 16px">Syfte</th><th style="padding:12px 16px">Hur länge</th><th style="padding:12px 16px">Typ</th></tr></thead>
            <tbody>
              @foreach($category['items'] as $item)
                <tr style="border-top:1px solid var(--line);vertical-align:top"><td style="padding:14px 16px;font-family:ui-monospace,Menlo,monospace;font-size:13px">{{ $item['name'] }}</td><td style="padding:14px 16px">{{ $item['purpose'] }}</td><td style="padding:14px 16px;color:var(--muted)">{{ $item['duration'] }}</td><td style="padding:14px 16px;color:var(--muted)">{{ $item['type'] }}</td></tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>
  @endforeach

  <p style="margin:48px 0 0;font-size:15px;line-height:1.6;color:var(--muted)">Du kan också ta bort cookies i webbläsarens inställningar; då loggas du ut. Frågor om hur vi behandlar personuppgifter besvaras i integritetspolicyn.</p>
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
</body>
</html>
