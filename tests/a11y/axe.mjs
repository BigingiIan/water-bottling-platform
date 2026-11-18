/*
 * npm run a11y: signs in to a running AquaOps, switches the account to one theme and colour profile, and runs
 * axe-core (WCAG 2.1 A and AA) on the main pages in a headless Edge or Chrome. Exits 1 on any violation.
 * The account's own theme choice is restored at the end.
 *
 * Environment (all optional):
 *   A11Y_BASE_URL   default http://localhost:8000
 *   A11Y_EMAIL      default admin@aquaops.test (TestUserSeeder)
 *   A11Y_PASSWORD   default password (TestUserSeeder)
 *   A11Y_THEME      light | dark | system, default dark
 *   A11Y_PROFILE    ocean | forest | slate | sunset | high-contrast, default ocean
 *   A11Y_BROWSER    path to a Chromium browser, default the installed Edge or Chrome
 */
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const baseUrl = (process.env.A11Y_BASE_URL ?? 'http://localhost:8000').replace(/\/$/, '');
const email = process.env.A11Y_EMAIL ?? 'admin@aquaops.test';
const password = process.env.A11Y_PASSWORD ?? 'password';
const theme = process.env.A11Y_THEME ?? 'dark';
const profile = process.env.A11Y_PROFILE ?? 'ocean';
const pages = [
    '/dashboard',
    '/production/schedule',
    '/production/runs',
    '/inventory',
    '/orders',
    '/invoices',
    '/revenue',
    '/forecasting',
    '/maintenance',
    '/requests',
    '/staff',
    '/settings?tab=appearance',
    '/profile?tab=preferences',
];

const browserPath = process.env.A11Y_BROWSER ?? [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/microsoft-edge',
].find((candidate) => existsSync(candidate));

if (! browserPath) {
    console.error('No Chromium browser found. Set A11Y_BROWSER to the path of Edge or Chrome.');
    process.exit(2);
}

const axeSource = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const port = 9400 + Math.floor(Math.random() * 400);
const profileDirectory = mkdtempSync(join(tmpdir(), 'aquaops-a11y-'));
const browser = spawn(browserPath, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profileDirectory}`, '--no-first-run', 'about:blank'], { stdio: 'ignore' });

let socket;
let restore = null;

try {
    let target;

    for (let attempt = 0; attempt < 40 && ! target; attempt++) {
        await sleep(250);

        try {
            target = (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).find((candidate) => candidate.type === 'page');
        } catch {
            // The browser is still starting.
        }
    }

    if (! target) {
        throw new Error('The browser did not start.');
    }

    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve) => socket.addEventListener('open', resolve));

    let nextId = 0;
    const pending = new Map();
    let loaded = () => {};

    socket.addEventListener('message', (event) => {
        const message = JSON.parse(event.data);

        if (message.id && pending.has(message.id)) {
            pending.get(message.id)(message);
            pending.delete(message.id);
        } else if (message.method === 'Page.loadEventFired') {
            loaded();
        }
    });

    const send = (method, params = {}) => new Promise((resolve) => {
        const id = ++nextId;
        pending.set(id, resolve);
        socket.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async (expression) => {
        const { result } = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });

        if (result?.exceptionDetails) {
            throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
        }

        return result?.result?.value;
    };
    const open = async (path) => {
        const load = new Promise((resolve) => { loaded = resolve; });
        await send('Page.navigate', { url: baseUrl + path });
        await Promise.race([load, sleep(15000)]);
        // Let Livewire and Alpine settle.
        await sleep(600);
    };
    const profilePage = "Livewire.all().find((component) => component.name === 'profile-page').$wire";
    const openProfile = async () => {
        await open('/profile?tab=preferences');

        for (let attempt = 0; attempt < 40; attempt++) {
            if (await evaluate("!! window.Livewire?.all().some((component) => component.name === 'profile-page')")) {
                return;
            }

            await sleep(250);
        }

        throw new Error('My Profile did not load.');
    };

    await send('Page.enable');
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    // System mode is checked in the matching OS colour scheme.
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: theme === 'light' ? 'light' : 'dark' }, { name: 'prefers-reduced-motion', value: 'reduce' }] });

    await open('/login');
    await evaluate(`(() => {
        document.querySelector('input[type=email]').value = ${JSON.stringify(email)};
        document.querySelector('input[type=password]').value = ${JSON.stringify(password)};
        document.querySelector('form').submit();
    })()`);
    await sleep(2500);

    if ((await evaluate('location.pathname')) === '/login') {
        throw new Error(`Could not sign in as ${email}. Set A11Y_EMAIL and A11Y_PASSWORD.`);
    }

    await openProfile();
    restore =await evaluate(`({ theme: ${profilePage}.theme, profile: ${profilePage}.profile })`);
    await evaluate(`(async () => {
        const wire = ${profilePage};
        wire.theme = ${JSON.stringify(theme)};
        wire.profile = ${JSON.stringify(profile)};
        await wire.savePreferences();
    })()`);

    let violationCount = 0;

    for (const path of pages) {
        await open(path);

        const appliedTheme = await evaluate("document.documentElement.className + ' ' + document.documentElement.dataset.profile");

        if (appliedTheme !== `theme-${theme} ${profile}`) {
            throw new Error(`${path} rendered as "${appliedTheme}", expected "theme-${theme} ${profile}".`);
        }

        await evaluate(axeSource);
        const violations = await evaluate(`axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } })
            .then((results) => results.violations.map((violation) => ({
                id: violation.id,
                impact: violation.impact,
                help: violation.help,
                nodes: violation.nodes.slice(0, 5).map((node) => node.target.join(' ') + (node.any[0]?.message ? ' | ' + node.any[0].message : '')),
                count: violation.nodes.length,
            })))`);

        violationCount += violations.length;
        console.log(`${violations.length === 0 ? 'pass' : 'FAIL'}  ${path}`);

        for (const violation of violations) {
            console.log(`      ${violation.id} (${violation.impact}, ${violation.count} nodes): ${violation.help}`);
            violation.nodes.forEach((node) => console.log(`        - ${node}`));
        }
    }

    await openProfile();
    await evaluate(`(async () => {
        const wire = ${profilePage};
        wire.theme = ${JSON.stringify(restore.theme)};
        wire.profile = ${JSON.stringify(restore.profile)};
        await wire.savePreferences();
    })()`);
    restore = null;

    console.log(`\n${pages.length} pages in ${theme} / ${profile}: ${violationCount === 0 ? 'no axe violations' : `${violationCount} violation types`}.`);
    process.exitCode = violationCount === 0 ? 0 : 1;
} catch (error) {
    console.error(error.message);

    if (restore) {
        console.error(`The account's theme was left as ${theme} / ${profile}; reset it in My Profile.`);
    }

    process.exitCode = 2;
} finally {
    socket?.close();
    browser.kill();
    await sleep(500);

    try {
        rmSync(profileDirectory, { recursive: true, force: true });
    } catch {
        // Edge can hold the profile briefly after exit; the OS temp cleaner removes it.
    }
}
