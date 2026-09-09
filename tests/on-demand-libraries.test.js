/**
 * On-demand library loading
 *
 * FlexSearch and idb are self-hosted and must not be requested until the
 * visitor opens search. Enqueueing them with the page put two blocking
 * cross-origin script requests on every page load for libraries most
 * visitors never need.
 *
 * Usage: node tests/on-demand-libraries.test.js
 */
const fs = require('fs');
const path = require('path');
const { check, report, loadJsdom, makeIdbStub, bootstrapPlugin, PLUGIN_JS, FIXTURE } = require('./helpers');

const JSDOM = loadJsdom();
const INDEX = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const VENDOR_DIR = path.join(__dirname, '..', 'vendor');

const dom = new JSDOM(`<!doctype html><html><body>
  <a href="/search" data-vital-search-trigger data-vital-search-version="123">Search</a>
</body></html>`, { runScripts: 'outside-only', pretendToBeVisual: true });
const { window } = dom;

check('vendored libraries exist in the plugin',
    fs.existsSync(path.join(VENDOR_DIR, 'flexsearch.bundle.min.js')) &&
    fs.existsSync(path.join(VENDOR_DIR, 'idb.umd.js')));

// Stand in for the browser fetching and executing an injected <script src>.
// jsdom will not load these itself, so define the globals the real files
// would define and fire the load event.
const requested = [];
const observer = new window.MutationObserver(mutations => {
    mutations.forEach(mutation => mutation.addedNodes.forEach(node => {
        if (node.tagName !== 'SCRIPT' || !node.src) return;
        requested.push(node.src);

        setTimeout(() => {
            const file = node.src.split('/').pop().split('?')[0];
            try {
                if (file === 'flexsearch.bundle.min.js') {
                    window.FlexSearch = require(path.join(VENDOR_DIR, 'flexsearch.bundle.min.js'));
                } else if (file === 'idb.umd.js') {
                    window.idb = makeIdbStub();
                }
                node.dispatchEvent(new window.Event('load'));
            } catch (error) {
                node.dispatchEvent(new window.Event('error'));
            }
        }, 10);
    }));
});
observer.observe(window.document.head, { childList: true });

window.fetch = async () => ({ ok: true, status: 200, json: async () => INDEX });
window.vitalSearch = {
    jsonUrl: '/search-index-123.json',
    version: 123,
    flexsearchUrl: 'https://example.test/wp-content/plugins/vital-search/vendor/flexsearch.bundle.min.js?ver=0.7.31',
    idbUrl: 'https://example.test/wp-content/plugins/vital-search/vendor/idb.umd.js?ver=7.1.1',
};
window.console.log = () => {};

bootstrapPlugin(window, fs.readFileSync(PLUGIN_JS, 'utf8')).then(() => {
    check('no libraries requested on page load', requested.length === 0);
    check('FlexSearch is not a global on page load', typeof window.FlexSearch === 'undefined');
    check('idb is not a global on page load', typeof window.idb === 'undefined');

    const trigger = window.document.querySelector('[data-vital-search-trigger]');
    trigger.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    runChecks();
});

function runChecks() {
    setTimeout(() => {
        check('both libraries requested after intent', requested.length === 2);
        check('libraries come from the plugin, not a public CDN',
            requested.every(url => url.includes('/vendor/') && !url.includes('jsdelivr')));

        setTimeout(() => {
            const popup = window.document.querySelector('vital-search-popup');
            const input = popup.shadowRoot.getElementById('search');
            input.value = 'tomato';
            input.dispatchEvent(new window.Event('input'));

            setTimeout(() => {
                const html = popup.shadowRoot.getElementById('results').innerHTML;
                check('search works after loading on demand', html.includes('search-item'));
                check('results are real matches', /tomato/i.test(html));
                report();
            }, 400);
        }, 300);
    }, 100);
}
