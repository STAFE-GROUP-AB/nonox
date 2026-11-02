// Takes the manual's screenshots in Chrome, driven over the DevTools
// protocol with nothing to install. Started by `php artisan
// manual:screenshots`, which signs in the demo user and writes the plan:
//
//   { "chrome": "/path/to/chrome", "cookies": [{ name, value, url }],
//     "shots": [{ "url", "file", "width", "height", "scale", "mobile", "selector", "clicks", "reveal", "expect", "forbid" }] }
//
// `clicks` are CSS selectors (or text=… for a button or link with exactly
// that text) clicked in order before the shot, `reveal` one
// scrolled to the top of the window.
//
// A shot is saved only when its page shows `expect`, the demo company's name,
// and none of `forbid` (the local administrator's address, for one).
//
// Each page is shown in light mode with reduced motion, waits until the
// network has been quiet, and is saved as WebP. A shot with a selector is
// cropped to that element.
import { spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const plan = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const profile = mkdtempSync(path.join(tmpdir(), 'nonox-shots-'));
const chrome = spawn(plan.chrome, [
    '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
    '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', '--force-color-profile=srgb', 'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'] });

const endpoint = await new Promise((resolve, reject) => {
    let output = '';
    chrome.stderr.on('data', (chunk) => {
        output += chunk;
        const match = output.match(/DevTools listening on (ws:\/\/\S+)/);

        if (match) {
            resolve(match[1]);
        }
    });
    chrome.on('exit', () => reject(new Error(`Chrome stopped: ${output}`)));
});

const socket = new WebSocket(endpoint);
await new Promise((resolve) => socket.addEventListener('open', resolve, { once: true }));

let nextId = 1;
const pending = new Map();
const listeners = new Set();

socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);

    if (message.id && pending.has(message.id)) {
        const { resolve, reject } = pending.get(message.id);
        pending.delete(message.id);
        message.error ? reject(new Error(`${message.error.message}`)) : resolve(message.result);

        return;
    }

    listeners.forEach((listener) => listener(message));
});

const send = (method, params = {}, sessionId = undefined) => new Promise((resolve, reject) => {
    const id = nextId++;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params, sessionId }));
});

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Resolves when no request has been in flight for `quiet` ms, or after `limit` ms. */
function networkIdle(sessionId, quiet = 700, limit = 15_000) {
    return new Promise((resolve) => {
        const inFlight = new Set();
        let timer;
        const done = () => {
            listeners.delete(listener);
            clearTimeout(timer);
            clearTimeout(cap);
            resolve();
        };
        const arm = () => {
            clearTimeout(timer);

            if (inFlight.size === 0) {
                timer = setTimeout(done, quiet);
            }
        };
        const listener = (message) => {
            if (message.sessionId !== sessionId) {
                return;
            }

            if (message.method === 'Network.requestWillBeSent') {
                inFlight.add(message.params.requestId);
                clearTimeout(timer);
            } else if (message.method === 'Network.loadingFinished' || message.method === 'Network.loadingFailed') {
                inFlight.delete(message.params.requestId);
                arm();
            }
        };
        const cap = setTimeout(done, limit);
        listeners.add(listener);
        arm();
    });
}

let failures = 0;

try {
    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });

    await send('Page.enable', {}, sessionId);
    await send('Network.enable', {}, sessionId);
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }, { name: 'prefers-reduced-motion', value: 'reduce' }] }, sessionId);
    await send('Network.setCookies', { cookies: plan.cookies }, sessionId);
    // The cookie choice is made, so the consent banner does not cover the page.
    await send('Page.addScriptToEvaluateOnNewDocument', {
        source: "try { localStorage.setItem('nonox-consent', JSON.stringify({ version: 1, at: Date.now(), granted: [] })); } catch (e) {}",
    }, sessionId);

    for (const shot of plan.shots) {
        await send('Emulation.setDeviceMetricsOverride', {
            width: shot.width, height: shot.height, deviceScaleFactor: shot.scale, mobile: shot.mobile,
        }, sessionId);

        const idle = networkIdle(sessionId);
        const { errorText } = await send('Page.navigate', { url: shot.url }, sessionId);
        await idle;

        // Fonts in place, Livewire and Alpine settled, the caret out of sight.
        await send('Runtime.evaluate', { expression: 'document.fonts.ready.then(() => { document.activeElement?.blur(); window.scrollTo(0, 0); })', awaitPromise: true }, sessionId);
        await sleep(400);

        const { result } = await send('Runtime.evaluate', {
            expression: `JSON.stringify({ status: performance.getEntriesByType('navigation')[0]?.responseStatus ?? 0, path: location.pathname, clip: ${JSON.stringify(shot.selector ?? null)} && (() => { const box = document.querySelector(${JSON.stringify(shot.selector ?? '')})?.getBoundingClientRect(); return box ? { x: box.x, y: box.y + window.scrollY, width: box.width, height: box.height } : null; })() })`,
            returnByValue: true,
        }, sessionId);
        const page = JSON.parse(result.value);

        // Open a dialog, a tab or a menu first; each click waits for what it loads.
        let clickFailed = null;

        for (const selector of shot.clicks ?? []) {
            const settled = networkIdle(sessionId, 500, 8_000);
            const { result: clicked } = await send('Runtime.evaluate', {
                expression: `JSON.stringify((() => { const wanted = ${JSON.stringify(selector)}; const nodes = wanted.startsWith('text=') ? [...document.querySelectorAll('button, a, [role=tab], summary, label')].filter((node) => node.textContent.trim() === wanted.slice(5)) : [...document.querySelectorAll(wanted)]; const el = nodes.find((node) => node.getClientRects().length > 0 && node.getBoundingClientRect().width > 0); if (!el) return null; el.scrollIntoView({ block: 'center' }); const b = el.getBoundingClientRect(); return { x: b.x + b.width / 2, y: b.y + b.height / 2 }; })())`,
                returnByValue: true,
            }, sessionId);
            const point = JSON.parse(clicked.value);

            // A real mouse click on the first visible match, as a person makes it: Alpine and Livewire see every event.
            if (point) {
                for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
                    await send('Input.dispatchMouseEvent', { type, x: point.x, y: point.y, button: 'left', clickCount: 1 }, sessionId);
                }
            }

            if (!point) {
                clickFailed = selector;
                break;
            }

            await settled;
            await sleep(400);
        }

        if (clickFailed) {
            failures++;
            console.log(`✗ ${shot.file}: nothing to click at ${clickFailed}`);

            continue;
        }

        if (shot.reveal) {
            await send('Runtime.evaluate', {
                expression: `(() => { const wanted = ${JSON.stringify(shot.reveal)}; const el = wanted.startsWith('text=') ? [...document.querySelectorAll('h1, h2, h3, h4, legend, summary, p, span, div')].find((node) => node.childElementCount === 0 && node.textContent.trim() === wanted.slice(5) && node.getClientRects().length > 0) : document.querySelector(wanted); el?.scrollIntoView({ block: 'start' }); window.scrollBy(0, -24); })()`,
            }, sessionId);
            await sleep(300);
        }

        if (shot.selector) {
            const { result: box } = await send('Runtime.evaluate', {
                expression: `JSON.stringify((() => { const b = document.querySelector(${JSON.stringify(shot.selector)})?.getBoundingClientRect(); return b ? { x: b.x, y: b.y + window.scrollY, width: b.width, height: b.height } : null; })())`,
                returnByValue: true,
            }, sessionId);
            page.clip = JSON.parse(box.value);
        }
        const { result: text } = await send('Runtime.evaluate', { expression: 'document.body.innerText', returnByValue: true }, sessionId);

        // Only the demo company may be on the page: anything else means the app is reading other data.
        const forbidden = (shot.forbid ?? []).find((word) => text.value.toLowerCase().includes(word.toLowerCase()));

        if (forbidden) {
            failures++;
            console.log(`✗ ${shot.file}: the page shows something that must not be in the manual; not saved.`);

            continue;
        }

        // innerText follows text-transform, so a name set in capitals is compared without case.
        if (!text.value.toLowerCase().includes(shot.expect.toLowerCase())) {
            failures++;
            console.log(`✗ ${shot.file}: "${shot.expect}" is not on the page; not saved. Page starts: ${text.value.slice(0, 160).replace(/\s+/g, " ")}`);

            continue;
        }
        const expected = new URL(shot.url).pathname;

        if (errorText || page.status >= 400 || page.path !== expected) {
            failures++;
            console.log(`✗ ${shot.file}: ${errorText || `HTTP ${page.status}`} at ${page.path}`);

            continue;
        }

        const options = { format: 'webp', quality: 82, captureBeyondViewport: false };

        if (page.clip) {
            options.clip = { ...page.clip, scale: 1 };
            options.captureBeyondViewport = true;
        }

        const { data } = await send('Page.captureScreenshot', options, sessionId);
        mkdirSync(path.dirname(shot.file), { recursive: true });
        writeFileSync(shot.file, Buffer.from(data, 'base64'));
        console.log(`✓ ${shot.file}`);
    }
} finally {
    socket.close();
    chrome.kill();
    await sleep(300);
    rmSync(profile, { recursive: true, force: true });
}

process.exit(failures > 0 ? 1 : 0);
