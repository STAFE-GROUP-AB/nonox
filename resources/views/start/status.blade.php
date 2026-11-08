{{-- The public status page (StatusPageController, ADR-078). Only states, short notes and times; nothing about a company or a person. --}}
@php
    $mark = fn (string $state): string => match ($state) {
        'OK' => '●', 'MAINTENANCE' => '◐', 'DEGRADED' => '▲', 'OUTAGE' => '✕', default => '○',
    };
    $color = fn (string $state): string => match ($state) {
        'OK' => '#1B7F4B', 'MAINTENANCE' => '#8A5A00', 'DEGRADED' => '#B45309', 'OUTAGE' => '#B42318', default => 'var(--muted)',
    };
    $headline = match ($overall->value) {
        'OK', 'UNKNOWN' => 'Allt fungerar',
        'MAINTENANCE' => 'Planerat underhåll pågår',
        'DEGRADED' => 'Vi har en störning',
        'OUTAGE' => 'Vi har ett avbrott',
    };
    $open = array_values(array_filter($snapshot['incidents'], fn (array $incident): bool => $incident['open']));
    $past = array_values(array_filter($snapshot['incidents'], fn (array $incident): bool => ! $incident['open']));
@endphp
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<style>
.status h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(34px,5vw,56px);line-height:1;letter-spacing:-0.04em}
.status h2{margin:44px 0 12px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:24px;letter-spacing:-0.02em}
.status p,.status li,.status dd{font-size:16px;line-height:1.6;color:var(--muted)}
.status a{color:var(--acct)}
.status .banner{margin-top:24px;padding:20px 24px;border:1px solid var(--line);border-radius:16px;font-size:20px;font-weight:700;color:var(--ink)}
.status .row{display:flex;flex-wrap:wrap;gap:4px 16px;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--line)}
.status .row strong{color:var(--ink)}
.status .state{font-weight:700;white-space:nowrap}
.status .incident{padding:18px 0;border-bottom:1px solid var(--line)}
.status .incident h3{margin:0;font-size:18px;color:var(--ink)}
.status time{font-variant-numeric:tabular-nums}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')
<main id="main" class="status" style="max-width:860px;margin:0 auto;padding:clamp(48px,8vw,96px) clamp(20px,4vw,40px)">
<h1>Driftstatus</h1>
<p>Så mår {{ parse_url(url('/'), PHP_URL_HOST) }} just nu. Sidan uppdateras högst en gång i minuten och visar bara tjänstens tillstånd, aldrig något om enskilda företag eller personer.</p>

<div class="banner" role="status" style="border-left:6px solid {{ $color($overall->value) }}">
  <span aria-hidden="true" style="color:{{ $color($overall->value) }}">{{ $mark($overall->value) }}</span> {{ $headline }}
</div>
<p style="font-size:14px">Läget kontrollerades <time>{{ $snapshot['generated'] }}</time>.</p>

@if($open !== [])
<h2>Pågående händelser</h2>
@foreach($open as $incident)
  @include('start.partials.status-incident', ['incident' => $incident])
@endforeach
@endif

<h2>Tjänsten</h2>
<div>
@foreach($snapshot['services'] as $component)
  <div class="row" data-component="{{ $component['key'] }}">
    <div><strong>{{ $component['name'] }}</strong><br>{{ $component['note'] }}@if($component['last']) <span style="font-size:14px">Senast lyckades det <time>{{ $component['last'] }}</time>.</span>@endif</div>
    <div class="state" style="color:{{ $color($component['state']) }}"><span aria-hidden="true">{{ $mark($component['state']) }}</span> {{ App\Domain\Status\ComponentState::from($component['state'])->label() }}</div>
  </div>
@endforeach
</div>

<h2>Externa kopplingar</h2>
<p>Här visas bara om kopplingen är inställd och när den senast lyckades. Inga nycklar eller uppgifter om vilka företag som använder den.</p>
<div>
@foreach($snapshot['external'] as $component)
  <div class="row" data-component="{{ $component['key'] }}">
    <div><strong>{{ $component['name'] }}</strong><br>{{ $component['note'] }}@if($component['last']) <span style="font-size:14px">Senast lyckades det <time>{{ $component['last'] }}</time>.</span>@endif</div>
    <div class="state" style="color:{{ $color($component['state']) }}"><span aria-hidden="true">{{ $mark($component['state']) }}</span> {{ App\Domain\Status\ComponentState::from($component['state'])->label() }}</div>
  </div>
@endforeach
</div>

<h2>Tidigare händelser</h2>
@if($past === [])
<p>Inga störningar eller avbrott de senaste 90 dagarna.</p>
@else
<p>De senaste 90 dagarna.</p>
@foreach($past as $incident)
  @include('start.partials.status-incident', ['incident' => $incident])
@endforeach
@endif

<nav aria-label="Relaterat" style="margin-top:56px;padding-top:24px;border-top:1px solid var(--line);display:flex;flex-wrap:wrap;gap:10px 22px;font-size:14.5px">
  <a href="{{ route('security') }}">Säkerhet och regelverk</a>
  <a href="{{ route('security.policy') }}">Säkerhetspolicy</a>
  <a href="{{ route('changelog') }}">Ändringslogg</a>
</nav>
</main>
@include('start.partials.public-footer')
</div>
@include('start.partials.public-script')
</body>
</html>
