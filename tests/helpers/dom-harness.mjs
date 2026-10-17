import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { build } from 'esbuild';

const root = new URL('../../', import.meta.url);
const rootDir = fileURLToPath(root);
const bundles = new Map();
const scenes = new Set();

export const closeAll = () => { for (const dom of scenes) dom.window.close(); scenes.clear(); };

// The Inertia client is replaced by a recording stub; everything else is the real production code.
const inertiaStub = {
    name: 'inertia-stub',
    setup(pluginBuild) {
        pluginBuild.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia-stub', namespace: 'stub' }));
        pluginBuild.onLoad({ filter: /.*/, namespace: 'stub' }, () => ({
            resolveDir: rootDir,
            loader: 'js',
            contents: `
                import { createElement, useState } from 'react';
                export const usePage = () => ({ props: window.__pageProps, url: window.__pageUrl ?? '/' });
                export const Head = () => null;
                export const Link = ({ href, children, ...rest }) => createElement('a', { href, ...(rest.className ? { className: rest.className } : {}) }, children);
                export const useForm = (initial) => { const [data, set] = useState(initial); return { data, setData: (key, value) => set({ ...data, [key]: value }), post: (url) => window.__inertia.push(['form-post', url]), processing: false, errors: {} }; };
                export const router = {
                    reload: (options) => window.__inertia.push(['reload', options]),
                    visit: (url, options) => window.__inertia.push(['visit', url, options]),
                    get: (url, data, options) => window.__inertia.push(['get', url, data, options]),
                    post: (url, data, options) => window.__inertia.push(['post', url, data, options]),
                    on: () => () => {},
                };
            `,
        }));
    },
};

export async function bundleEntry(entry) {
    if (!bundles.has(entry)) {
        const result = await build({
            entryPoints: [fileURLToPath(new URL(entry, root))],
            bundle: true,
            write: false,
            format: 'iife',
            globalName: 'Entry',
            platform: 'browser',
            jsx: 'automatic',
            define: { 'process.env.NODE_ENV': '"development"' },
            plugins: [inertiaStub],
            logLevel: 'silent',
        });
        bundles.set(entry, result.outputFiles[0].text);
    }

    return bundles.get(entry);
}

/** Boots a jsdom window with the bundled entry plus scripted axios; returns helpers to drive and inspect the UI. */
export async function createScene({ entry, locale = 'en', url = 'http://localhost/super-admin/subscriptions?state=lapsed', handler = async () => ({ data: {} }), pageProps = {}, pageUrl = '/' }) {
    const dom = new JSDOM('<!doctype html><html><body><div id="app"></div></body></html>', { url, runScripts: 'outside-only', pretendToBeVisual: true });
    scenes.add(dom);
    const { window } = dom;
    window.IS_REACT_ACT_ENVIRONMENT = true;
    // Node's MessageChannel keeps the process alive; the scheduler only needs a macrotask hop.
    window.MessageChannel = class {
        constructor() {
            this.port1 = {};
            this.port2 = { postMessage: () => { setTimeout(() => this.port1.onmessage?.({}), 0); } };
        }
    };
    window.TextEncoder = globalThis.TextEncoder;
    window.TextDecoder = globalThis.TextDecoder;
    window.__pageProps = { locale, capabilities: { 'platform.subscriptions.plan_change': true }, ...pageProps };
    window.__pageUrl = pageUrl;
    window.matchMedia = () => ({ matches: window.innerWidth < 900, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
    window.__inertia = [];
    window.document.documentElement.lang = locale;
    window.document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    const errors = [];
    window.addEventListener('error', (event) => errors.push(event.message));
    window.console.error = (...args) => errors.push(args.map(String).join(' '));
    const requests = [];
    const call = async (config) => {
        const plain = (value) => (value === undefined ? undefined : JSON.parse(JSON.stringify(value)));
        const record = { method: String(config.method ?? 'get').toLowerCase(), url: config.url, data: plain(config.data), params: plain(config.params) };
        requests.push(record);
        return handler(record);
    };
    window.axios = Object.assign(call, { get: (target, config = {}) => call({ method: 'get', url: target, ...config }), post: (target, data) => call({ method: 'post', url: target, data }) });
    window.eval(await bundleEntry(entry));
    const Entry = window.Entry;
    const flush = () => Entry.act(async () => { await new Promise((resolve) => window.setTimeout(resolve, 0)); });
    const nativeSetter = (element) => Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value').set;

    return {
        window,
        document: window.document,
        requests,
        errors,
        inertia: window.__inertia,
        Entry,
        flush,
        mount: async (props) => { Entry.mount(window.document.getElementById('app'), props); await flush(); },
        update: async (props) => { Entry.update(props); await flush(); },
        $: (selector) => window.document.querySelector(selector),
        $$: (selector) => [...window.document.querySelectorAll(selector)],
        text: () => window.document.body.textContent,
        setValue: async (element, value, eventName = 'change') => {
            await Entry.act(async () => {
                nativeSetter(element).call(element, value);
                element.dispatchEvent(new window.Event(eventName, { bubbles: true }));
            });
            await flush();
        },
        click: async (element, times = 1) => {
            await Entry.act(async () => {
                for (let index = 0; index < times; index++) element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
            });
            await flush();
        },
        close: () => dom.window.close(),
    };
}

export const readSource = (relative) => readFileSync(new URL(relative, root), 'utf8');
