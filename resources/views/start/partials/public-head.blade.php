{{-- Meta tags and styles shared by the public pages. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $pageTitle ?? 'Gratis alternativ till Fortnox – NONOX, bokföring med öppen källkod' }}</title>
<meta name="description" content="{{ $pageDescription ?? 'NONOX är ett gratis alternativ till Fortnox, Visma och Bokio: ett komplett svenskt bokföringsprogram med öppen källkod för bokföring, fakturering, lön och bokslut. Kör själv gratis, eller låt oss sköta driften för 995 kr om året.' }}">
<meta property="og:title" content="{{ $pageTitle ?? 'Gratis alternativ till Fortnox – NONOX, bokföring med öppen källkod' }}">
<meta property="og:description" content="{{ $pageDescription ?? 'Bokföring som ägs av företagaren. Gratis att köra själv, från 995 kr om året med drift.' }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="NONOX">
<meta property="og:locale" content="sv_SE">
<meta name="theme-color" content="#15171C">
<link rel="canonical" href="{{ url()->current() }}">
<meta name="robots" content="index,follow">
@php
    $structured = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'NONOX',
            'alternateName' => 'NONOX Ekonomisystem',
            'applicationCategory' => 'BusinessApplication',
            'applicationSubCategory' => 'Bokföringsprogram',
            'operatingSystem' => 'Webb',
            'inLanguage' => 'sv-SE',
            'url' => url('/'),
            'description' => 'Komplett svenskt bokföringsprogram med öppen källkod: bokföring, fakturering, lön, moms, bokslut och årsredovisning. Ett gratis alternativ till Fortnox, Visma och Bokio.',
            'license' => 'https://www.gnu.org/licenses/agpl-3.0.html',
            'isAccessibleForFree' => true,
            'offers' => [
                ['@type' => 'Offer', 'name' => 'Kör själv', 'price' => '0', 'priceCurrency' => 'SEK'],
                // The hosted plans with a price, from LandingPage::PLANS.
                ...array_map(fn (array $plan): array => [
                    '@type' => 'Offer',
                    'name' => $plan['employees'] === '0–10' ? "Vi sköter driften, upp till {$plan['max']} anställda" : "Vi sköter driften, {$plan['employees']} anställda",
                    'price' => (string) $plan['price'],
                    'priceCurrency' => 'SEK',
                    'priceSpecification' => ['@type' => 'UnitPriceSpecification', 'price' => (string) $plan['price'], 'priceCurrency' => 'SEK', 'unitText' => 'år', 'valueAddedTaxIncluded' => false],
                ], array_values(array_filter(App\Support\Landing\LandingPage::PLANS, fn (array $plan): bool => $plan['price'] !== null))),
            ],
        ],
    ];
    if (! empty($breadcrumbs ?? [])) {
        $structured[] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $crumb, int $index): array => ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $crumb['name'], 'item' => $crumb['url']], $breadcrumbs, array_keys($breadcrumbs)),
        ];
    }
    if (! empty($faqSchema ?? $faqs ?? [])) {
        $structured[] = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq): array => ['@type' => 'Question', 'name' => $faq['q'] ?? $faq[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['a'] ?? $faq[1]]], $faqSchema ?? $faqs),
        ];
    }
@endphp
@foreach($structured as $item)
<script type="application/ld+json">{!! json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endforeach
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23FF5A36' d='M23.47 11.13 A11.5 11.5 0 1 0 23.47 12.87 L19.63 12.87 A7.68 7.68 0 1 1 19.63 11.13 Z'/%3E%3C/svg%3E">
<link rel="preload" href="/fonts/inter/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/inter/InterDisplay-ExtraBold.woff2" as="font" type="font/woff2" crossorigin>
<style>
/* Inter (SIL Open Font License, public/fonts/inter/LICENSE.txt), served from this site so no visitor data goes to a third party. */
@font-face{font-family:InterVariable;font-style:normal;font-weight:100 900;font-display:swap;src:url('/fonts/inter/InterVariable.woff2') format('woff2')}
@font-face{font-family:Inter;font-style:normal;font-weight:100 900;font-display:swap;src:url('/fonts/inter/InterVariable.woff2') format('woff2')}
@font-face{font-family:InterDisplay;font-style:normal;font-weight:800;font-display:swap;src:url('/fonts/inter/InterDisplay-ExtraBold.woff2') format('woff2')}

:root{--bg:#F7F5F0;--card:#FFFFFF;--ink:#15171C;--muted:#5A5E68;--line:rgba(21,23,28,.10);--soft:#EEEBE4;--acc:#FF5A36;--accl:#FFE6DF;--acct:#B8381A;--accb:#E0461F;--dark:#15171C;--hdr:rgba(247,245,240,.82)}
@media (prefers-color-scheme:dark){:root{--bg:#15171C;--card:#1D2027;--ink:#F3F1EC;--muted:#A6AAB3;--line:rgba(255,255,255,.10);--soft:#23262E;--accl:#3A231D;--acct:#FF8A6D;--accb:#FF6A48;--dark:#0C0D10;--hdr:rgba(21,23,28,.82)}}
[data-theme=light]{--bg:#F7F5F0;--card:#FFFFFF;--ink:#15171C;--muted:#5A5E68;--line:rgba(21,23,28,.10);--soft:#EEEBE4;--accl:#FFE6DF;--acct:#B8381A;--accb:#E0461F;--dark:#15171C;--hdr:rgba(247,245,240,.82)}
[data-theme=dark]{--bg:#15171C;--card:#1D2027;--ink:#F3F1EC;--muted:#A6AAB3;--line:rgba(255,255,255,.10);--soft:#23262E;--accl:#3A231D;--acct:#FF8A6D;--accb:#FF6A48;--dark:#0C0D10;--hdr:rgba(21,23,28,.82)}
html{scroll-behavior:smooth}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,sans-serif;-webkit-font-smoothing:antialiased;font-feature-settings:"cv11","ss01"}
@supports (font-variation-settings:normal){body{font-family:InterVariable,Inter,system-ui,sans-serif}}
*{box-sizing:border-box}
a{color:inherit}
a:hover{color:var(--acct)}
:focus-visible{outline:3px solid #FF5A36;outline-offset:3px;border-radius:6px}
summary{list-style:none}
summary::-webkit-details-marker{display:none}
details[open] .faq-plus{transform:rotate(45deg)}
input[type=range]{accent-color:#FF5A36}

.lp0:focus{left:8px !important}
.lp1:hover{color:var(--ink) !important}
.lp2:hover{color:var(--ink) !important}
.lp3:hover{color:var(--ink) !important}
.lp4:hover{color:var(--ink) !important}
.lp5:hover{color:var(--ink) !important}
.lp6:hover{color:var(--ink) !important}
.lp7:hover{border-color:var(--ink) !important}
.lp8:hover{background:#FF7454 !important;color:#15171C !important}
.lp9:hover{background:#FF7454 !important;color:#15171C !important}
.lp10:hover{background:var(--ink) !important;color:var(--bg) !important}
.lp11:hover{transform:translateY(-4px) !important;box-shadow:0 24px 48px -24px rgba(21,23,28,.25) !important}
.lp12:hover{transform:translateY(-4px) !important;box-shadow:0 24px 48px -24px rgba(21,23,28,.25) !important}
.lp13:hover{background:rgba(255,255,255,.08) !important}
.lp14:hover{transform:translateY(-4px) !important;box-shadow:0 24px 48px -24px rgba(21,23,28,.25) !important}
.lp15:hover{color:var(--bg) !important;background:var(--muted) !important}
.lp16:hover{color:var(--ink) !important;background:var(--soft) !important}
.lp17:hover{background:#FF5A36 !important;color:#15171C !important}
.lp18:hover{background:var(--bg) !important}
.lp19:hover{background:#FF7454 !important;color:#15171C !important}
.lp20:hover{transform:translateY(-4px) !important;box-shadow:0 24px 48px -24px rgba(21,23,28,.25) !important}
.lp21:hover{background:#FF5A36 !important;border-color:#FF5A36 !important;color:#15171C !important}
.lp22:hover{color:var(--bg) !important}
.lp23:hover{background:var(--ink) !important;color:var(--bg) !important}
.lp24:hover{color:var(--bg) !important;opacity:.88 !important}
.lp25:hover{background:var(--ink) !important;color:var(--bg) !important}
.lp26:hover{background:#2A2D35 !important;color:#F7F5F0 !important}
.lp27:hover{background:#15171C !important;color:#F7F5F0 !important}
.lp28:hover{color:#FF8A6D !important}
.lp29:hover{color:#FF8A6D !important}
.lp30:hover{color:#FF8A6D !important}
.lp31:hover{color:#FF8A6D !important}
.lp32:hover{color:#FF8A6D !important}
.lp33:hover{color:#FF8A6D !important}
.lp34:hover{color:#FF8A6D !important}
.lp35:hover{color:#FF8A6D !important}
.lp36:hover{color:#FF8A6D !important}
.lp37:hover{color:#FF8A6D !important}
.lp38:hover{color:#FF8A6D !important}
.lp39:hover{color:#FF8A6D !important}
.mm-nav{display:flex;align-items:center;gap:4px;margin-left:28px}
.mm-trigger{display:inline-flex;align-items:center;gap:6px;height:40px;padding:0 14px;border:0;border-radius:999px;background:transparent;color:var(--muted);font:500 14.5px Inter,system-ui,sans-serif;text-decoration:none;cursor:pointer;transition:background .15s,color .15s}
.mm-trigger:hover,.mm-trigger[aria-expanded=true]{color:var(--ink);background:var(--soft)}
.mm-chevron{transition:transform .2s ease}
[aria-expanded=true] > .mm-chevron,details[open] > summary > .mm-chevron{transform:rotate(180deg)}
.mm-login{display:inline-flex;align-items:center;height:40px;padding:0 14px;border-radius:999px;color:var(--ink);text-decoration:none;font-size:14px;font-weight:600}
.mm-login:hover{background:var(--soft);color:var(--ink)}
.mm-panel{position:absolute;left:0;right:0;top:100%;background:var(--card);border-bottom:1px solid var(--line);box-shadow:0 30px 60px -30px rgba(21,23,28,.35);animation:mm-in .18s ease}
@keyframes mm-in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.mm-panel{animation:none}}
.mm-inner{max-width:1240px;margin:0 auto;padding:22px clamp(20px,4vw,40px) 26px;display:grid;grid-template-columns:1fr 300px;gap:28px}
.mm-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 12px}
.mm-item{display:grid;grid-template-columns:40px 1fr;gap:14px;align-items:start;padding:14px;border-radius:16px;text-decoration:none;color:var(--ink);transition:background .15s}
.mm-item:hover,.mm-item:focus-visible{background:var(--bg);color:var(--ink)}
.mm-icon{width:40px;height:40px;border-radius:12px;background:var(--accl);display:grid;place-items:center}
.mm-title{display:block;font-weight:700;font-size:15px;letter-spacing:-0.01em}
.mm-text{display:block;margin-top:3px;font-size:13.5px;line-height:1.45;color:var(--muted)}
.mm-promo{position:relative;overflow:hidden;display:flex;flex-direction:column;gap:10px;padding:24px;border-radius:20px;background:#15171C;color:#F7F5F0;text-decoration:none}
.mm-promo:hover{color:#F7F5F0}
.mm-eyebrow{font-family:ui-monospace,Menlo,monospace;font-size:12px;color:#FF8A6D}
.mm-promo-title{font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:22px;line-height:1.15;letter-spacing:-0.025em;max-width:220px}
.mm-promo-cta{margin-top:auto;font-size:14px;font-weight:700}
.mm-promo:hover .mm-promo-cta{color:#FF8A6D}
.mm-promo-ring{position:absolute;right:-34px;bottom:-34px;width:120px;height:120px;opacity:.9}
.mm-mobile-group{border-bottom:1px solid var(--line)}
.mm-mobile-group summary,.mm-mobile-link{display:flex;align-items:center;justify-content:space-between;padding:16px 0;font-size:18px;font-weight:600;cursor:pointer;text-decoration:none}
.mm-mobile-link{border-bottom:1px solid var(--line)}
.mm-mobile-group ul{list-style:none;margin:0 0 12px;padding:0;display:flex;flex-direction:column;gap:2px}
.mm-mobile-group li a{display:block;padding:10px 12px;border-radius:12px;text-decoration:none}
.mm-mobile-group li a:hover{background:var(--soft);color:var(--ink)}
@media (max-width:959px){.lp-desktop-block{display:none!important}}
[hidden]{display:none!important}
.lp-mobile{display:none}
.lp-desktop{display:contents}
@media (max-width:959px){.lp-desktop{display:none}.lp-mobile{display:block}}
@media (min-width:960px){#lp-mobilmeny{display:none!important}}
.cmp-wrap{position:relative;overflow-x:auto;border:1px solid var(--line);border-radius:24px;background:var(--card)}
.cmp-table{width:100%;min-width:860px;border-collapse:separate;border-spacing:0;font-size:15px}
.cmp-table th,.cmp-table td{padding:14px 12px;text-align:center;border-bottom:1px solid var(--line);vertical-align:middle}
.cmp-table thead th{position:sticky;top:0;background:var(--card);font-size:13.5px;font-weight:700;line-height:1.25}
.cmp-table tbody tr:last-child th,.cmp-table tbody tr:last-child td{border-bottom:0}
.cmp-feature-col{text-align:left!important;font-weight:600;min-width:220px;position:sticky;left:0;background:var(--card);z-index:1}
.cmp-nonox{background:var(--accl)!important}
.cmp-table thead th.cmp-nonox{color:var(--acct);font-size:15px}
.cmp-cell{display:inline-flex;flex-direction:column;align-items:center;gap:2px}
.cmp-note{display:block;font-size:11px;font-weight:600;color:var(--muted)}
.cmp-pill{display:inline-block;padding:3px 9px;border-radius:999px;border:1px solid var(--line);font-size:12px;font-weight:700;color:var(--muted)}
.cmp-unknown{font-size:12px;color:var(--muted)}
.cmp-price-row th,.cmp-price-row td{background:var(--bg)}
.cmp-price{font-family:InterDisplay,Inter,sans-serif;font-weight:800;font-size:18px;letter-spacing:-0.02em;white-space:nowrap;font-variant-numeric:tabular-nums}
.cmp-nonox .cmp-price{color:var(--acct);font-size:21px}
.cmp-legend{display:flex;flex-wrap:wrap;gap:10px 22px;margin-top:16px;font-size:13.5px;color:var(--muted)}
.cmp-legend > span{display:inline-flex;align-items:center;gap:7px}
.cmp-sources{margin-top:16px;font-size:14px;color:var(--muted)}
.cmp-sources summary{cursor:pointer;font-weight:600;color:var(--ink)}
.cmp-sources li{margin:6px 0;line-height:1.5}
.cmp-sources a{color:var(--acct)}
.cmp-link{display:inline-flex;align-items:center;min-height:40px;padding:0 16px;border-radius:999px;border:1px solid var(--line);background:var(--card);text-decoration:none}
.cmp-link:hover{border-color:var(--ink);color:var(--ink)}
.cmp-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
</style>
