@props(['title' => null])
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title." – NONOX" : 'NONOX Ekonomisystem' }}</title>
    @include('partials.favicon')
    <style>
        /*
         * Self-contained on purpose: the start pages must work even before the
         * frontend has been built (npm run build).
         */
        :root {
            --paper: #f7f5f0;
            --card: #ffffff;
            --ink: #17171a;
            --muted: #625d55;
            --line: #e4dfd5;
            --accent: #d4330b;
            --accent-ink: #ffffff;
            --accent-soft: #ffe5db;
            --good: #2f6b3a;
            --good-soft: #e5f0e6;
            --field: #ffffff;
            --code: #f1eee7;
            color-scheme: light;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #17171a;
                --card: #202024;
                --ink: #f2efe9;
                --muted: #b2aba0;
                --line: #36322c;
                --accent: #ff6a42;
                --accent-ink: #1a0f0c;
                --accent-soft: #3d1a10;
                --good: #8fc79a;
                --good-soft: #1f2e22;
                --field: #1a1816;
                --code: #2b2824;
                color-scheme: dark;
            }
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--paper);
            color: var(--ink);
            font: 16px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-variant-numeric: tabular-nums;
            -webkit-font-smoothing: antialiased;
        }
        .shell { max-width: 36rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
        .brand { display: flex; align-items: center; gap: .75rem; margin-bottom: 2.5rem; }
        .brand img { height: 2.25rem; width: auto; display: block; }
        h1 { letter-spacing: -0.02em; }
        .brand span { color: var(--muted); font-size: .95rem; padding-left: .75rem; border-left: 1px solid var(--line); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 28px; padding: 2.25rem; }
        h1 { font-size: 1.75rem; line-height: 1.25; margin: 0 0 .5rem; letter-spacing: -.01em; }
        h2 { font-size: 1.15rem; margin: 0 0 .25rem; }
        p { margin: 0 0 1rem; }
        .lead { color: var(--muted); font-size: 1.05rem; margin-bottom: 1.75rem; }
        .muted { color: var(--muted); }
        .small { font-size: .875rem; }
        .step { color: var(--muted); font-size: .85rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; margin-bottom: .5rem; }
        .progress { display: flex; gap: .35rem; margin-bottom: 1.75rem; }
        .progress i { flex: 1; height: 4px; border-radius: 4px; background: var(--line); }
        .progress i.on { background: var(--accent); }
        .field { margin-bottom: 1.25rem; }
        label, legend { display: block; font-weight: 600; margin-bottom: .35rem; padding: 0; }
        .hint { display: block; color: var(--muted); font-size: .875rem; margin-top: .35rem; }
        input[type=text], input[type=email], select {
            width: 100%; padding: .7rem .8rem; font: inherit; color: var(--ink);
            /* The field's edge stands out 3:1 in light and dark mode (WCAG 1.4.11). */
            background: var(--field); border: 1px solid #8a837d; border-radius: 12px;
        }
        input:focus, select:focus, button:focus-visible, a:focus-visible {
            outline: 3px solid color-mix(in srgb, var(--accent) 45%, transparent); outline-offset: 1px; border-color: var(--accent);
        }
        .code-input { font-size: 1.75rem; letter-spacing: .5em; text-align: center; }
        .choices { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); }
        .choice { display: flex; align-items: center; gap: .6rem; padding: .7rem .8rem; border: 1px solid var(--line); border-radius: 8px; cursor: pointer; font-weight: 500; }
        .choice:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
        .choice input { accent-color: var(--accent); width: 1.1rem; height: 1.1rem; margin: 0; }
        .error { color: var(--accent); font-size: .9rem; margin-top: .35rem; font-weight: 500; }
        .actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: 1.75rem; }
        .button {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .8rem 1.4rem; border-radius: 12px; border: 1px solid transparent;
            background: var(--accent); color: var(--accent-ink); font: inherit; font-weight: 600;
            text-decoration: none; cursor: pointer;
        }
        .button:disabled { opacity: .6; cursor: progress; }
        .button.wide { width: 100%; }
        .link { background: none; border: 0; padding: 0; color: var(--muted); font: inherit; text-decoration: underline; cursor: pointer; }
        .notice { background: var(--good-soft); color: var(--good); border-radius: 8px; padding: .8rem 1rem; font-size: .9rem; margin-bottom: 1.25rem; }
        .problem { border-left: 4px solid var(--accent); padding-left: 1rem; margin: 1.5rem 0; }
        pre.command { background: var(--code); border-radius: 8px; padding: .8rem 1rem; overflow-x: auto; font: .85rem/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre; margin: .5rem 0 1rem; }
        details { margin-top: 1.5rem; color: var(--muted); font-size: .9rem; }
        summary { cursor: pointer; }
        .facts { list-style: none; padding: 0; margin: 1.5rem 0 0; display: grid; gap: .75rem; }
        .facts li { display: flex; gap: .75rem; }
        .facts li::before { content: ""; flex: none; width: .5rem; height: .5rem; margin-top: .55rem; border-radius: 50%; background: var(--accent); }
        footer { margin-top: 2rem; text-align: center; color: var(--muted); font-size: .85rem; }
        @media (max-width: 30rem) { .card { padding: 1.5rem 1.25rem; } h1 { font-size: 1.5rem; } }
    </style>
    @livewireStyles
</head>
<body>
    <div class="shell">
        <header class="brand"><picture><source srcset="{{ asset('brand/nonox-light.svg') }}" media="(prefers-color-scheme: dark)"><img src="{{ asset('brand/nonox.svg') }}" alt="NONOX"></picture><span>Ekonomisystem</span></header>

        <main id="main">
            {{ $slot }}
        </main>

        <footer>Fri programvara under AGPL-3.0. Din data stannar hos dig.<br><span style="font-variant-numeric:tabular-nums">NONOX {{ App\Support\Build\BuildInfo::line() }}</span></footer>
    </div>

    <x-ui.toaster />
    @livewireScripts
    @include('start.partials.cookie-banner')
</body>
</html>
