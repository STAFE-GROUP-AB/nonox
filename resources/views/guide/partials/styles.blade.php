{{-- Styles of Företagsguiden (/foretagsguiden) on top of the manual's (manual.partials.styles). Colour tokens from start.partials.public-head. --}}
<style>
:root{--gd-ok:#1E7B45;--gd-okl:#E5F4EA;--gd-glow:rgba(255,90,54,.16)}
@media (prefers-color-scheme:dark){:root{--gd-ok:#4CBF7D;--gd-okl:#14281C;--gd-glow:rgba(255,106,72,.14)}}
[data-theme=light]{--gd-ok:#1E7B45;--gd-okl:#E5F4EA;--gd-glow:rgba(255,90,54,.16)}
[data-theme=dark]{--gd-ok:#4CBF7D;--gd-okl:#14281C;--gd-glow:rgba(255,106,72,.14)}

.gd-wrap{max-width:1240px;margin:0 auto;padding:0 clamp(16px,4vw,40px)}
.gd-kicker{margin:0 0 10px;font-size:12.5px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--acct)}
.gd-h2{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(28px,3.6vw,42px);line-height:1.08;letter-spacing:-0.03em;text-wrap:balance}
.gd-sub{margin:12px 0 0;max-width:640px;font-size:17px;line-height:1.55;color:var(--muted);text-wrap:pretty}
.gd-band{padding:clamp(56px,8vw,104px) 0}
.gd-band+.gd-band{padding-top:0}

/* Hero */
.gd-hero{position:relative;overflow:hidden;padding:clamp(56px,9vw,120px) 0 clamp(120px,14vw,168px);background:radial-gradient(900px 480px at 85% -10%,var(--gd-glow),transparent 70%),radial-gradient(700px 420px at 0% 0%,var(--accl),transparent 65%)}
.gd-hero-inner{max-width:880px;min-width:0}
.gd-hero .mn-eyebrow{margin-bottom:18px}
.gd-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;background:var(--ink);color:var(--bg);font-size:11.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.gd-hero h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(40px,7vw,84px);line-height:.98;letter-spacing:-0.045em;text-wrap:balance}
.gd-hero h1 em{font-style:normal;color:var(--acct)}
.gd-hero .mn-lead{max-width:680px;margin-top:22px}
.gd-search{display:flex;gap:8px;margin-top:32px;max-width:640px;padding:6px;border-radius:18px;border:1px solid var(--line);background:var(--card);box-shadow:0 30px 60px -36px rgba(21,23,28,.45)}
.gd-search label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.gd-search-field{position:relative;flex:1;display:flex;align-items:center;min-width:0}
.gd-search-field svg{position:absolute;left:14px;color:var(--muted);pointer-events:none}
.gd-search input{width:100%;min-height:52px;padding:0 12px 0 46px;border:0;border-radius:13px;background:transparent;color:var(--ink);font:17px Inter,system-ui,sans-serif;-webkit-appearance:none;appearance:none}
.gd-search input::placeholder{color:var(--muted);opacity:1}
.gd-search input:focus{outline:3px solid #FF5A36;outline-offset:0}
.gd-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 20px;border-radius:13px;border:0;background:var(--ink);color:var(--bg);font:700 15px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;white-space:nowrap}
.gd-btn:hover{background:#FF5A36;color:#15171C}
.gd-btn:focus-visible,.gd-chip:focus-visible,.gd-card:focus-visible,.gd-q:focus-visible,.gd-link:focus-visible{outline:3px solid #FF5A36;outline-offset:2px}
.gd-btn-acc{background:#FF5A36;color:#15171C}
.gd-btn-acc:hover{background:var(--bg);color:var(--ink)}
.gd-btn-ghost{background:transparent;color:inherit;box-shadow:inset 0 0 0 1.5px currentColor}
.gd-btn-ghost:hover{background:rgba(255,255,255,.1);color:inherit}
.gd-sources-line{display:flex;flex-wrap:wrap;gap:6px 16px;margin:20px 0 0;padding:0;list-style:none;font-size:13.5px;color:var(--muted)}
.gd-sources-line li{display:inline-flex;align-items:center;gap:6px}
.gd-sources-line svg{color:var(--gd-ok)}
.gd-hero .gd-beta{max-width:640px}

.gd-hero-grid{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:clamp(32px,5vw,72px);align-items:center}
@media (max-width:1099px){.gd-hero-grid{grid-template-columns:minmax(0,1fr)}.gd-preview{display:none}}
.gd-preview{position:relative;padding:24px;border-radius:26px;border:1px solid var(--line);background:var(--card);box-shadow:16px 18px 0 -2px var(--accl),0 48px 96px -48px rgba(21,23,28,.5),0 16px 32px -24px rgba(21,23,28,.25);transform:rotate(1.2deg)}
.gd-preview-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px}
.gd-preview-title{margin:0;font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.gd-preview-count{font-size:13px;font-weight:700;color:var(--acct);font-variant-numeric:tabular-nums}
.gd-preview h2{margin:6px 0 0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:24px;letter-spacing:-0.02em}
.gd-preview-bar{height:8px;margin:16px 0 6px;border-radius:999px;background:var(--soft);overflow:hidden}
.gd-preview-bar i{display:block;width:12%;height:100%;border-radius:999px;background:#FF5A36}
.gd-preview ol{margin:14px 0 0;padding:0;list-style:none;display:grid;gap:2px}
.gd-preview li{display:flex;align-items:center;gap:12px;padding:9px 6px;border-radius:12px;font-size:15px;font-weight:600;line-height:1.3}
.gd-preview li b{flex:none;display:grid;place-items:center;width:28px;height:28px;border-radius:50%;border:1.5px solid var(--line);font-size:13px;font-variant-numeric:tabular-nums}
.gd-preview li.is-done{color:var(--muted)}
.gd-preview li.is-done b{background:var(--gd-ok);border-color:var(--gd-ok);color:#fff}
.gd-preview li.is-done span{text-decoration:line-through;text-decoration-color:var(--line)}
.gd-preview-more{margin:10px 6px 0;font-size:14px;color:var(--muted)}
@media (prefers-reduced-motion:reduce){.gd-preview{transform:none}}

/* Picker */
.gd-picker{position:relative;z-index:2;margin-top:calc(-1 * clamp(72px,9vw,104px));padding:clamp(22px,3vw,34px);border-radius:28px;border:1px solid var(--line);background:var(--card);box-shadow:0 40px 80px -48px rgba(21,23,28,.5)}
.gd-picker-head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px 20px}
.gd-picker h2{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(22px,2.6vw,30px);letter-spacing:-0.025em}
.gd-picker-hint{margin:6px 0 0;font-size:15px;color:var(--muted)}
.gd-reset{font-size:14px;font-weight:600;color:var(--muted)}
.gd-groups{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:22px 36px;margin-top:22px}
@media (max-width:899px){.gd-groups{grid-template-columns:minmax(0,1fr)}}
.gd-group-label{margin:0 0 10px;font-size:12.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.gd-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0;padding:0;list-style:none}
.gd-chip{display:inline-flex;align-items:center;gap:7px;min-height:44px;padding:0 16px;border-radius:999px;border:1.5px solid var(--line);background:var(--bg);color:var(--ink);font-size:15px;font-weight:600;text-decoration:none;transition:border-color .15s,background .15s}
.gd-chip:hover{border-color:var(--ink);color:var(--ink)}
.gd-chip[aria-current=true]{border-color:var(--ink);background:var(--ink);color:var(--bg)}
.gd-chip[aria-current=true]::before{content:"";width:8px;height:8px;border-radius:50%;background:#FF5A36}
@media (prefers-reduced-motion:reduce){.gd-chip{transition:none}}

/* Search results */
.gd-results{margin-top:28px;padding:22px 24px;border-radius:22px;border:1px solid var(--line);background:var(--card)}
.gd-results h2{margin:0;font-size:19px;font-weight:800}
.gd-results ul{margin:14px 0 0;padding:0;list-style:none;display:grid;gap:4px}
.gd-results a{display:block;padding:12px 14px;border-radius:12px;text-decoration:none;color:var(--ink)}
.gd-results a:hover{background:var(--soft)}
.gd-results strong{display:block;font-size:16px}
.gd-results span{display:block;margin-top:2px;font-size:14.5px;color:var(--muted)}

/* Din väg */
.gd-path{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:clamp(28px,4vw,56px);align-items:start;margin-top:36px}
@media (max-width:999px){.gd-path{grid-template-columns:minmax(0,1fr)}}
.gd-phase{margin:0 0 6px}
.gd-phase+.gd-phase{margin-top:30px}
.gd-phase-title{display:flex;align-items:center;gap:10px;margin:0 0 6px;font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--acct)}
.gd-phase-title::after{content:"";flex:1;height:1px;background:var(--line)}
.gd-steps{margin:0;padding:0;list-style:none}
.gd-step{position:relative;display:grid;grid-template-columns:44px minmax(0,1fr);gap:16px;padding:14px 0}
.gd-step::before{content:"";position:absolute;left:21px;top:62px;bottom:-14px;width:2px;background:var(--line)}
.gd-step:last-child::before{display:none}
.gd-num-badge{display:grid;place-items:center;width:44px;height:44px;border-radius:50%;background:var(--card);border:1.5px solid var(--line);font-weight:800;font-size:16px;font-variant-numeric:tabular-nums;color:var(--ink)}
.gd-num-badge.is-first{background:#FF5A36;border-color:#FF5A36;color:#15171C}
.gd-step h4{margin:9px 0 0;font-size:18px;line-height:1.3;letter-spacing:-0.01em}
.gd-step p{margin:6px 0 0;font-size:15.5px;line-height:1.6;color:var(--muted);text-wrap:pretty}
.gd-step-foot{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px;margin-top:10px}
.gd-tag{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;background:var(--soft);color:var(--ink);font-size:12.5px;font-weight:700}
.gd-link{display:inline-flex;align-items:center;gap:6px;font-size:14.5px;font-weight:700;color:var(--acct);text-decoration:none;border-radius:6px}
.gd-link:hover{text-decoration:underline;text-underline-offset:3px}
.gd-aside{position:sticky;top:88px;display:grid;gap:16px}
@media (max-width:999px){.gd-aside{position:static}}
.gd-cta{padding:28px;border-radius:24px;border:1px solid rgba(255,255,255,.1);background:var(--dark);color:#F3F1EC;box-shadow:0 40px 80px -48px rgba(21,23,28,.7)}
.gd-cta h3{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:25px;line-height:1.12;letter-spacing:-0.025em;color:#fff}
.gd-cta p{margin:12px 0 0;font-size:15px;line-height:1.55;color:#C9CCD3}
.gd-cta ul{margin:16px 0 0;padding:0;list-style:none;display:grid;gap:8px;font-size:14.5px;color:#E7E5E0}
.gd-cta li{display:flex;gap:9px;align-items:flex-start}
.gd-cta li svg{flex:none;margin-top:2px;color:#FF8A6D}
.gd-cta-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}
.gd-cta .gd-btn{color:#15171C}
.gd-cta .gd-btn-ghost{color:#F3F1EC}
.gd-panel{padding:22px 24px;border-radius:22px;border:1px solid var(--line);background:var(--card)}
.gd-panel h3{margin:0;font-size:17px;line-height:1.3}
.gd-panel p{margin:8px 0 0;font-size:14.5px;line-height:1.55;color:var(--muted)}
.gd-panel .gd-link{margin-top:12px}

/* Questions */
.gd-qs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:32px 0 0;padding:0;list-style:none}
@media (max-width:1099px){.gd-qs{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:559px){.gd-qs{grid-template-columns:minmax(0,1fr)}}
.gd-qs>li{display:flex}
.gd-q{flex:1;display:flex;flex-direction:column;gap:14px;min-height:172px;padding:22px;border-radius:22px;border:1px solid var(--line);background:var(--card);color:var(--ink);text-decoration:none;transition:transform .15s,box-shadow .15s,border-color .15s}
.gd-q:hover{transform:translateY(-2px);border-color:var(--ink);box-shadow:0 22px 44px -28px rgba(21,23,28,.4);color:var(--ink)}
.gd-q strong{font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:20px;line-height:1.2;letter-spacing:-0.02em;text-wrap:balance}
.gd-q span{margin-top:auto;display:flex;align-items:center;gap:6px;font-size:13.5px;font-weight:600;color:var(--muted)}
.gd-q span svg{color:var(--acct)}
@media (prefers-reduced-motion:reduce){.gd-q,.gd-q:hover,.gd-card,.gd-card:hover{transition:none;transform:none}}

/* Sections */
.gd-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:32px 0 0;padding:0;list-style:none}
@media (max-width:999px){.gd-cards{grid-template-columns:minmax(0,1fr)}}
.gd-cards>li{display:flex}
.gd-card{flex:1;display:flex;flex-direction:column;padding:26px;border-radius:24px;border:1px solid var(--line);background:var(--card);color:var(--ink);text-decoration:none;transition:transform .15s,box-shadow .15s,border-color .15s}
.gd-card:hover{transform:translateY(-2px);border-color:var(--ink);box-shadow:0 22px 44px -28px rgba(21,23,28,.4);color:var(--ink)}
.gd-card-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:14px;background:var(--accl);color:var(--acct)}
.gd-card strong{margin-top:18px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:23px;letter-spacing:-0.02em}
.gd-card>span.gd-card-text{margin-top:8px;font-size:15px;line-height:1.55;color:var(--muted)}
.gd-card ul{margin:18px 0 0;padding:16px 0 0;list-style:none;display:grid;gap:9px;border-top:1px solid var(--line);font-size:14.5px}
.gd-card li{display:flex;gap:9px;align-items:flex-start;line-height:1.4}
.gd-card li svg{flex:none;margin-top:2px;color:var(--acct)}
.gd-card-foot{margin-top:auto;padding-top:18px;display:flex;align-items:center;justify-content:space-between;font-size:14px;font-weight:700;color:var(--acct)}

/* Coming */
.gd-dark{background:var(--dark);color:#F3F1EC}
.gd-dark .gd-h2{color:#fff}
.gd-dark .gd-sub{color:#C9CCD3}
.gd-dark .gd-kicker{color:#FF8A6D}
.gd-soon{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:32px 0 0;padding:0;list-style:none}
@media (max-width:1099px){.gd-soon{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:559px){.gd-soon{grid-template-columns:minmax(0,1fr)}}
.gd-soon li{padding:24px;border-radius:22px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04)}
.gd-soon-badge{display:inline-flex;padding:3px 10px;border-radius:999px;background:rgba(255,138,109,.16);color:#FFB39F;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.gd-soon h3{margin:16px 0 0;font-size:19px;line-height:1.25;color:#fff}
.gd-soon li.gd-live{border-color:rgba(255,138,109,.5);background:rgba(255,138,109,.08)}
.gd-live-badge{background:#FF5A36;color:#15171C}
.gd-dark .gd-live-link{display:inline-flex;margin-top:14px;color:#FFB39F}
.gd-soon p{margin:8px 0 0;font-size:14.5px;line-height:1.55;color:#C9CCD3}

/* Beta notice, sources, numbers (also on the article pages) */
.gd-beta{display:flex;gap:10px;align-items:flex-start;margin:20px 0 0;padding:12px 16px;border-radius:14px;background:var(--soft);color:var(--ink);font-size:14.5px;line-height:1.5}
.gd-beta svg{flex:none;margin-top:2px;color:var(--acct)}
.gd-sources{margin-top:36px;padding-top:20px;border-top:1px solid var(--line)}
.gd-sources ul{padding-left:20px}
.gd-checked{color:var(--muted);font-size:14px}
.gd-plan{display:inline-flex;align-items:center;gap:8px;margin-top:22px;padding:11px 18px;border-radius:999px;background:var(--ink);color:var(--bg);font-weight:600;text-decoration:none}
.gd-plan:hover{color:var(--bg);opacity:.92}
.mn-body td.gd-num,.mn-body th.gd-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
</style>
