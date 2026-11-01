{{-- Styles of the developer site (/utvecklare). The colour tokens come from start.partials.public-head. --}}
<style>
:root{--dv-code-bg:#111318;--dv-code-ink:#E9E6DF;--dv-code-line:rgba(255,255,255,.09);--dv-get-bg:#DCF5E6;--dv-get-fg:#0F5132;--dv-post-bg:#FFE6DF;--dv-post-fg:#9A2E12;--dv-patch-bg:#E3F1F7;--dv-patch-fg:#0B4A63;--dv-delete-bg:#FBE1E1;--dv-delete-fg:#8A1F1F;--dv-side-w:288px}
@media (prefers-color-scheme:dark){:root{--dv-code-bg:#0E1014;--dv-get-bg:#163527;--dv-get-fg:#8FE0B0;--dv-post-bg:#3A231D;--dv-post-fg:#FFA58C;--dv-patch-bg:#14303D;--dv-patch-fg:#8ED1EE;--dv-delete-bg:#3D1C1C;--dv-delete-fg:#FFA3A3}}
[data-theme=light]{--dv-code-bg:#111318;--dv-get-bg:#DCF5E6;--dv-get-fg:#0F5132;--dv-post-bg:#FFE6DF;--dv-post-fg:#9A2E12;--dv-patch-bg:#E3F1F7;--dv-patch-fg:#0B4A63;--dv-delete-bg:#FBE1E1;--dv-delete-fg:#8A1F1F}
[data-theme=dark]{--dv-code-bg:#0E1014;--dv-get-bg:#163527;--dv-get-fg:#8FE0B0;--dv-post-bg:#3A231D;--dv-post-fg:#FFA58C;--dv-patch-bg:#14303D;--dv-patch-fg:#8ED1EE;--dv-delete-bg:#3D1C1C;--dv-delete-fg:#FFA3A3}

.dv{min-height:100vh;display:flex;flex-direction:column;overflow-x:clip}
.dv-skip{position:absolute;left:-9999px;top:8px;background:#FF5A36;color:#15171C;padding:10px 14px;border-radius:8px;z-index:100;font-weight:700}
.dv-skip:focus{left:8px}
.dv-header{position:sticky;top:0;z-index:40;background:var(--hdr);backdrop-filter:saturate(1.4) blur(14px);-webkit-backdrop-filter:saturate(1.4) blur(14px);border-bottom:1px solid var(--line)}
.dv-header-inner{max-width:1480px;margin:0 auto;padding:0 clamp(16px,3vw,32px);height:64px;display:flex;align-items:center;gap:14px}
.dv-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink)}
.dv-brand-tag{text-decoration:none;font:600 13px/1 ui-monospace,SFMono-Regular,Menlo,monospace;padding:6px 9px;border-radius:999px;background:var(--accl);color:var(--acct)}
.dv-topnav{display:flex;align-items:center;gap:2px;margin-left:18px}
.dv-topnav a,.dv-header-link{display:inline-flex;align-items:center;min-height:40px;padding:0 12px;border-radius:999px;color:var(--muted);text-decoration:none;font-size:14.5px;font-weight:500}
.dv-topnav a:hover,.dv-header-link:hover,.dv-topnav a[aria-current=page]{color:var(--ink);background:var(--soft)}
.dv-header-end{margin-left:auto;display:flex;align-items:center;gap:6px}
.dv-menu-button{display:none;align-items:center;gap:8px;min-height:44px;padding:0 14px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:600 14px Inter,system-ui,sans-serif;cursor:pointer}
@media (max-width:519px){.dv-header-inner{gap:8px}.dv-menu-button{padding:0 12px}.dv-menu-label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}.dv-brand-tag{padding:5px 7px}.dv-header-link{padding:0 8px}}
@media (max-width:959px){.dv-topnav{display:none}.dv-menu-button{display:inline-flex}.dv-header-link.dv-hide-narrow{display:none}}

.dv-shell{flex:1;width:100%;max-width:1480px;margin:0 auto;display:grid;grid-template-columns:var(--dv-side-w) minmax(0,1fr);gap:0}
@media (max-width:959px){.dv-shell{grid-template-columns:minmax(0,1fr)}}
.dv-side{position:sticky;top:64px;align-self:start;max-height:calc(100vh - 64px);overflow-y:auto;padding:24px 18px 40px clamp(16px,3vw,32px);border-right:1px solid var(--line);font-size:14px}
@media (max-width:959px){.dv-side{position:fixed;inset:64px 0 0 0;z-index:35;max-height:none;background:var(--bg);border-right:0;padding:18px 20px 48px}.dv-side:not(.is-open){display:none}}
.dv-side h2{margin:22px 0 6px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.dv-side ul{list-style:none;margin:0;padding:0}
.dv-side a{display:flex;align-items:baseline;gap:8px;padding:6px 10px;border-radius:9px;text-decoration:none;color:var(--ink);line-height:1.35}
.dv-side a:hover{background:var(--soft);color:var(--ink)}
.dv-side a[aria-current=page],.dv-side a[aria-current=location]{background:var(--accl);color:var(--acct);font-weight:600}
.dv-side .dv-tag-link{font-weight:600}
.dv-side .dv-ops a{padding-left:14px;font-size:13.5px;color:var(--muted)}
.dv-side .dv-ops a:hover{color:var(--ink)}
.dv-side .dv-ops .dv-method{flex:none;min-width:46px;text-align:center}

.dv-search{position:relative}
.dv-search label{display:block;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.dv-search input{width:100%;min-height:42px;padding:0 12px;border-radius:10px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:15px Inter,system-ui,sans-serif}
.dv-search input:focus{outline:3px solid #FF5A36;outline-offset:1px;border-color:transparent}
.dv-search-hint{margin:6px 2px 0;font-size:12px;color:var(--muted)}
.dv-results{margin-top:8px;border:1px solid var(--line);border-radius:12px;background:var(--card);overflow:hidden}
.dv-results a{display:block;padding:9px 12px;border-radius:0;border-bottom:1px solid var(--line)}
.dv-results li:last-child a{border-bottom:0}
.dv-results a[aria-selected=true],.dv-results a:hover{background:var(--accl);color:var(--ink)}
.dv-results .dv-result-sub{display:block;margin-top:2px;font:12px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted);word-break:break-all}
.dv-results p{margin:0;padding:10px 12px;color:var(--muted)}

.dv-main{min-width:0;padding:clamp(28px,4vw,56px) clamp(16px,4vw,56px) 80px}
.dv-content{max-width:780px}
.dv-wide{max-width:1180px}
.dv-with-toc{display:grid;grid-template-columns:minmax(0,780px) 220px;gap:56px;align-items:start}
@media (max-width:1279px){.dv-with-toc{grid-template-columns:minmax(0,1fr)}.dv-toc{display:none}}
.dv-toc{position:sticky;top:96px;font-size:13.5px}
.dv-toc h2{margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.dv-toc ul{list-style:none;margin:0;padding:0 0 0 12px;border-left:2px solid var(--line);display:grid;gap:8px}
.dv-toc a{color:var(--muted);text-decoration:none}
.dv-toc a:hover{color:var(--ink)}

.dv-eyebrow{margin:0 0 12px;font:600 13px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--acct)}
.dv h1{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(34px,5vw,56px);line-height:1.02;letter-spacing:-0.035em;text-wrap:balance}
.dv-prose h2,.dv h2.dv-h2{margin:48px 0 12px;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(24px,2.6vw,30px);line-height:1.15;letter-spacing:-0.025em}
.dv-prose h3{margin:30px 0 8px;font-size:18px;font-weight:700;letter-spacing:-0.01em}
.dv-prose h2 a.dv-anchor,.dv-prose h3 a.dv-anchor{text-decoration:none;color:inherit}
.dv-prose h2 a.dv-anchor:hover::after,.dv-prose h3 a.dv-anchor:hover::after{content:" #";color:var(--acct)}
.dv-prose p,.dv-prose li{font-size:16px;line-height:1.7}
.dv-prose a{color:var(--acct);text-underline-offset:3px}
.dv-prose a:hover{color:var(--ink)}
.dv-prose ul,.dv-prose ol{padding-left:22px}
.dv-prose li+li{margin-top:6px}
.dv-prose blockquote{margin:22px 0;padding:14px 18px;border-left:4px solid var(--acc);background:var(--accl);border-radius:12px}
.dv-prose blockquote p{margin:0}
.dv-lead{margin:18px 0 0;font-size:clamp(17px,1.8vw,20px);line-height:1.55;color:var(--muted);max-width:720px}
.dv :not(pre)>code{font:0.88em ui-monospace,SFMono-Regular,Menlo,monospace;padding:2px 6px;border-radius:6px;background:var(--soft);color:var(--ink);overflow-wrap:anywhere}

.dv-table{overflow-x:auto;margin:20px 0;border:1px solid var(--line);border-radius:14px;background:var(--card)}
.dv-table table{width:100%;border-collapse:collapse;font-size:14.5px}
.dv-table th,.dv-table td{padding:11px 14px;text-align:left;vertical-align:top;border-bottom:1px solid var(--line);line-height:1.5}
.dv-table thead th{font-size:12.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);background:var(--bg)}
.dv-table tbody tr:last-child td,.dv-table tbody tr:last-child th{border-bottom:0}
.dv-table td code{white-space:nowrap}

.dv-code{margin:18px 0;border-radius:14px;background:var(--dv-code-bg);color:var(--dv-code-ink);border:1px solid var(--dv-code-line);overflow:hidden}
.dv-code-bar{display:flex;align-items:center;gap:10px;min-height:38px;padding:0 6px 0 14px;border-bottom:1px solid var(--dv-code-line);font:600 12.5px Inter,system-ui,sans-serif;color:#B7B4AD}
.dv-code-bar:not(:has(.dv-code-label)):has(.dv-copy[hidden]){display:none}
.dv-code-label{letter-spacing:.02em}
.dv-copy{margin-left:auto;min-height:30px;padding:0 12px;border-radius:8px;border:1px solid rgba(255,255,255,.16);background:transparent;color:#E9E6DF;font:600 12.5px Inter,system-ui,sans-serif;cursor:pointer}
.dv-copy:hover{background:rgba(255,255,255,.08)}
.dv-code pre{margin:0;padding:16px 18px;overflow-x:auto;font:13.5px/1.65 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;tab-size:4}
.dv-code pre:focus-visible{outline:3px solid #FF5A36;outline-offset:-3px}
.dv-code code{font:inherit;background:none;padding:0;color:inherit}
.tk-c{color:#9EA3AD;font-style:italic}.tk-s{color:#B9E2A8}.tk-n{color:#F6C177}.tk-k{color:#FF9B80}.tk-p{color:#9CCFFF}.tk-v{color:#E5B5FF}.tk-f{color:#F6C177}

.dv-tabs{margin:18px 0}
.dv-tablist{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:-6px;position:relative;z-index:1}
.dv-tablist button{min-height:38px;padding:0 14px;border:1px solid var(--line);border-bottom:0;border-radius:10px 10px 0 0;background:var(--soft);color:var(--muted);font:600 13.5px Inter,system-ui,sans-serif;cursor:pointer}
.dv-tablist button[aria-selected=true]{background:var(--dv-code-bg);color:#F3F1EC;border-color:var(--dv-code-bg)}
.dv-tabs.is-ready .dv-code{margin-top:0;border-top-left-radius:0}
.dv-tabs.is-ready .dv-code-label{display:none}

.dv-method{display:inline-block;padding:3px 8px;border-radius:7px;font:700 11.5px/1.3 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.03em}
.dv-m-get{background:var(--dv-get-bg);color:var(--dv-get-fg)}
.dv-m-post{background:var(--dv-post-bg);color:var(--dv-post-fg)}
.dv-m-patch,.dv-m-put{background:var(--dv-patch-bg);color:var(--dv-patch-fg)}
.dv-m-delete{background:var(--dv-delete-bg);color:var(--dv-delete-fg)}
.dv-endpoint{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:14px 0 0;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--card)}
.dv-endpoint .dv-method{font-size:13px}
.dv-endpoint code{background:none!important;padding:0!important;font-size:15px!important;word-break:break-all}

.dv-op{padding:48px 0;border-top:1px solid var(--line);display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:clamp(24px,3vw,48px);scroll-margin-top:80px}
.dv-op:first-of-type{border-top:0}
@media (max-width:1199px){.dv-op{grid-template-columns:minmax(0,1fr)}}
.dv-op h2{margin:0;font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:clamp(22px,2.4vw,28px);letter-spacing:-0.02em}
.dv-op h2 a{text-decoration:none;color:inherit}
.dv-op h3{margin:28px 0 10px;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.dv-op p{line-height:1.65}
.dv-op-code{min-width:0}
@media (min-width:1200px){.dv-op-code{position:sticky;top:80px;align-self:start;max-height:calc(100vh - 96px);overflow-y:auto}}
.dv-op-code h3:first-child{margin-top:0}
.dv-op-id{font:12.5px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted)}
.dv-planned{display:inline-block;padding:3px 9px;border-radius:999px;background:#FFF1CC;color:#6B4A00;font-size:12px;font-weight:700;vertical-align:middle}
.dv-callout{margin:18px 0;padding:14px 16px;border-radius:12px;border:1px solid var(--line);background:var(--card);font-size:14.5px;line-height:1.55}
.dv-callout strong{display:block;margin-bottom:4px}
.dv-callout-warn{border-left:4px solid #E0A100}

.dv-props{list-style:none;margin:0;padding:0;border:1px solid var(--line);border-radius:12px;background:var(--card)}
.dv-props .dv-props{margin:10px 0 2px;background:var(--bg)}
.dv-props>li{padding:12px 14px;border-bottom:1px solid var(--line)}
.dv-props>li:last-child{border-bottom:0}
.dv-prop-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 10px}
.dv-prop-name{font:600 14px ui-monospace,SFMono-Regular,Menlo,monospace!important;background:none!important;padding:0!important;color:var(--ink)}
.dv-type{font:12.5px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted)}
.dv-badge{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11.5px;font-weight:700;border:1px solid var(--line);color:var(--muted)}
.dv-badge-req{border-color:transparent;background:var(--accl);color:var(--acct)}
.dv-prop-desc{margin:5px 0 0;font-size:14.5px;line-height:1.55;color:var(--ink)}
.dv-prop-meta{margin:5px 0 0;font-size:13px;line-height:1.6;color:var(--muted)}
.dv-prop-meta code{font-size:12px!important}
.dv-props details>summary{display:inline-flex;align-items:center;gap:6px;margin-top:8px;min-height:32px;padding:0 10px;border-radius:8px;border:1px solid var(--line);font-size:13px;font-weight:600;cursor:pointer;color:var(--ink)}
.dv-props details>summary:hover{background:var(--soft)}
.dv-props details[open]>summary .dv-chev{transform:rotate(90deg)}
.dv-chev{transition:transform .15s}
.dv-schema-head{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;margin:0 0 10px;font-size:14px;color:var(--muted)}

.dv-response{margin:0 0 12px;border:1px solid var(--line);border-radius:12px;background:var(--card)}
.dv-response>summary{display:flex;align-items:baseline;gap:10px;padding:12px 14px;cursor:pointer;font-size:14.5px;line-height:1.45}
.dv-response>summary:hover{background:var(--soft);border-radius:12px}
.dv-response[open]>summary{border-bottom:1px solid var(--line);border-radius:12px 12px 0 0}
.dv-response-body{padding:12px 14px}
.dv-status{flex:none;font:700 13px ui-monospace,SFMono-Regular,Menlo,monospace;padding:2px 8px;border-radius:7px}
.dv-s2{background:var(--dv-get-bg);color:var(--dv-get-fg)}
.dv-s4,.dv-s5{background:var(--dv-delete-bg);color:var(--dv-delete-fg)}
.dv-example-name{margin:14px 0 -8px;font-size:13px;font-weight:600;color:var(--muted)}

.dv-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));gap:14px;margin-top:22px}
.dv-card{display:flex;flex-direction:column;gap:8px;padding:20px;border-radius:18px;border:1px solid var(--line);background:var(--card);text-decoration:none;color:var(--ink);transition:transform .15s,box-shadow .15s}
.dv-card:hover{transform:translateY(-2px);box-shadow:0 18px 40px -24px rgba(21,23,28,.35);color:var(--ink)}
.dv-card strong{font-size:16.5px;letter-spacing:-0.01em}
.dv-card span{font-size:14.5px;line-height:1.5;color:var(--muted)}
@media (prefers-reduced-motion:reduce){.dv-card,.dv-card:hover{transition:none;transform:none}}
.dv-hero{padding-bottom:12px}
.dv-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:26px}
.dv-btn{display:inline-flex;align-items:center;min-height:46px;padding:0 20px;border-radius:999px;text-decoration:none;font-weight:700;font-size:15px}
.dv-btn-primary{background:#FF5A36;color:#15171C}
.dv-btn-primary:hover{background:#FF7454;color:#15171C}
.dv-btn-ghost{border:1px solid var(--line);background:var(--card);color:var(--ink)}
.dv-btn-ghost:hover{border-color:var(--ink);color:var(--ink)}
.dv-facts{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,220px),1fr));gap:14px;margin-top:22px}
.dv-fact{padding:18px;border-radius:16px;background:var(--card);border:1px solid var(--line)}
.dv-fact strong{display:block;font-size:15.5px;margin-bottom:6px}
.dv-fact span{font-size:14.5px;line-height:1.55;color:var(--muted)}
.dv-steps{counter-reset:step;list-style:none;padding:0;margin:22px 0 0;display:grid;gap:22px}
.dv-steps>li{counter-increment:step;display:grid;grid-template-columns:40px minmax(0,1fr);gap:14px}
.dv-steps>li::before{content:counter(step);display:grid;place-items:center;width:34px;height:34px;border-radius:999px;background:#FF5A36;color:#15171C;font-weight:800}
.dv-steps h3{margin:4px 0 6px;font-size:17px}
.dv-steps p{margin:0;line-height:1.6;color:var(--muted)}
.dv-pager{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:56px}
.dv-pager a{display:flex;flex-direction:column;gap:4px;padding:16px;border-radius:14px;border:1px solid var(--line);background:var(--card);text-decoration:none}
.dv-pager a:hover{border-color:var(--ink);color:var(--ink)}
.dv-pager span{font-size:12.5px;color:var(--muted)}
.dv-pager .dv-next{text-align:right;grid-column:2}
.dv-meta-links{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:40px;padding-top:20px;border-top:1px solid var(--line);font-size:14px}
.dv-meta-links a{color:var(--muted)}

.dv-footer{background:var(--dark);color:#D9D7D1;padding:40px clamp(16px,4vw,40px);font-size:14px}
.dv-footer-inner{max-width:1480px;margin:0 auto;display:flex;flex-wrap:wrap;gap:12px 28px;align-items:center}
.dv-footer a{color:#D9D7D1;text-decoration:none}
.dv-footer a:hover{color:#FF8A6D}
.dv-footer small{flex-basis:100%;color:#A6AAB3;font-size:13px}
</style>
