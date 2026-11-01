{{-- A 1200 × 630 share image (ShareCards), rendered to PNG by `php artisan og:images`. --}}
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<style>
@font-face{font-family:Inter;font-weight:100 900;src:url('{{ $fonts }}/InterVariable.woff2') format('woff2')}
@font-face{font-family:InterDisplay;font-weight:800;src:url('{{ $fonts }}/InterDisplay-ExtraBold.woff2') format('woff2')}
*{box-sizing:border-box}
html,body{margin:0;width:1200px;height:630px;overflow:hidden}
body{position:relative;background:#15171C;color:#F7F5F0;font-family:Inter,sans-serif;-webkit-font-smoothing:antialiased}
.ring{position:absolute;right:-170px;top:50%;transform:translateY(-50%);width:640px;height:640px}
.glow{position:absolute;right:-120px;top:50%;transform:translateY(-50%);width:760px;height:760px;border-radius:50%;background:radial-gradient(circle,rgba(255,90,54,.16),rgba(255,90,54,0) 62%)}
.content{position:absolute;left:76px;top:66px;bottom:62px;width:760px;display:flex;flex-direction:column}
.logo{height:56px;width:auto;align-self:flex-start}
.main{margin-top:auto}
.eyebrow{display:inline-block;font-size:21px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#FF7A5C;margin:0 0 20px}
h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:{{ $size }}px;line-height:1.02;letter-spacing:-.025em}
h1 span{color:#FF5A36;display:block}
p{margin:26px 0 0;font-size:25px;line-height:1.4;color:#B5B8C0;max-width:740px}
.foot{position:absolute;left:76px;right:76px;bottom:44px;display:flex;align-items:center;justify-content:space-between;font-size:20px;color:#8D919B}
.foot b{color:#F7F5F0;font-weight:700;letter-spacing:.01em}
.chips{display:flex;gap:10px}
.chip{border:1px solid rgba(255,255,255,.16);border-radius:999px;padding:7px 15px;font-size:17px;color:#C9CCD3}
.content{bottom:120px}
</style>
</head>
<body>
<div class="glow"></div>
<svg class="ring" viewBox="0 0 512 512" aria-hidden="true"><path fill="#FF5A36" fill-opacity=".9" d="M 415.2953232207399 271.0 A 160 160 0 1 1 415.2953232207399 241.0 L 361.9433811051922 241.0 A 107 107 0 1 0 361.9433811051922 271.0 Z"/></svg>
<div class="content">
  <img class="logo" src="{{ $logo }}" alt="NONOX">
  <div class="main">
    <div class="eyebrow">{{ $card['eyebrow'] }}</div>
    <h1>{{ $card['title'] }} <span>{{ $card['accent'] }}</span></h1>
    <p>{{ $card['text'] }}</p>
  </div>
</div>
<div class="foot">
  <b>nonox.app</b>
  <div class="chips"><span class="chip">Öppen källkod</span><span class="chip">Byggd efter bokföringslagen</span><span class="chip">Data i Sverige</span></div>
</div>
</body>
</html>
