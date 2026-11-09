{{-- The list of per-program moving guides (/flytta-fran, ADR-076). --}}
<!DOCTYPE html>
<html lang="sv">
<head>
@include('start.partials.public-head')
<meta name="robots" content="noindex">
<style>
.fg-wrap{max-width:860px;margin:0 auto;padding:0 clamp(20px,4vw,40px)}
.fg-crumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px;font-size:14px;color:var(--muted)}
.fg-crumbs li+li::before{content:"/";margin-right:6px}
.fg-crumbs a{color:var(--muted)}
.fg-h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(34px,5vw,60px);line-height:1;letter-spacing:-0.04em;text-wrap:balance}
.fg-lead{margin:20px 0 0;font-size:clamp(17px,1.8vw,20px);line-height:1.55;color:var(--muted)}
.fg-list{list-style:none;margin:28px 0 0;padding:0;display:grid;gap:12px}
.fg-list a{display:block;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 18px;font-weight:700;color:var(--ink)}
</style>
</head>
<body>
<div style="background:var(--bg);color:var(--ink);min-height:100vh;overflow-x:clip">
@include('start.partials.public-header')

<main id="main">
<section style="padding:clamp(28px,4vw,40px) 0 clamp(64px,9vw,120px)">
  <div class="fg-wrap">
    <nav aria-label="Brödsmulor" class="fg-crumbs">
      <ol>
        @foreach($breadcrumbs as $crumb)
          <li>@if($loop->last)<span aria-current="page">{{ $crumb['name'] }}</span>@else<a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>@endif</li>
        @endforeach
      </ol>
    </nav>
    <h1 class="fg-h1" style="margin-top:clamp(28px,5vw,56px)">Flytta från ett annat program</h1>
    <p class="fg-lead">Välj programmet du flyttar från. Vi visar vad programmets egen leverantör beskriver om att ta ut bokföring, register och underlag, och säger rakt ut när det inte är beskrivet.</p>
    <ul class="fg-list">
      @foreach($programs as $program)
        <li><a href="{{ route('migrationFrom.show', $program['slug']) }}">{{ $program['name'] }}</a></li>
      @endforeach
    </ul>
  </div>
</section>
</main>

@include('start.partials.public-footer')
</div>

@include('start.partials.public-script')
</body>
</html>
