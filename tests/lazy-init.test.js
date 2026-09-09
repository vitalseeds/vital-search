/**
 * Lazy initialisation
 *
 * The popup must not be created, and the search index must not be fetched,
 * until the visitor shows search intent. Creating the popup is what triggers
 * the index fetch, the IndexedDB writes and the FlexSearch build, so doing it
 * on page load makes every visitor pay for search they may never use.
 *
 * Also covers the loading window: a query typed before the index is ready
 * must show a loading state and then run automatically once it is.
 *
 * Usage: node tests/lazy-init.test.js
 */
const fs = require('fs');
const path = require('path');
const { check, report, loadJsdom, makeIdbStub, bootstrapPlugin, PLUGIN_JS, FIXTURE } = require('./helpers');

const JSDOM = loadJsdom();
const INDEX = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));

let fetchCount = 0;
let releaseFetch;
const heldFetch = new Promise(resolve => { releaseFetch = resolve; });

const dom = new JSDOM(`<!doctype html><html><body>
  <a href="/search" data-vital-search-trigger data-vital-search-version="123" data-vital-search-placeholder="Search">Search</a>
</body></html>`, { runScripts: 'outside-only', pretendToBeVisual: true });
const { window } = dom;

// Libraries are already present here, so this test isolates init timing
// rather than library loading (see on-demand-libraries.test.js for that).
window.FlexSearch = require(path.join(__dirname, '..', 'vendor', 'flexsearch.bundle.min.js'));
window.idb = makeIdbStub();

// Hold the response open so the loading window can be tested
window.fetch = async () => {
    fetchCount++;
    await heldFetch;
    return { ok: true, status: 200, json: async () => INDEX };
};
window.vitalSearch = { jsonUrl: '/search-index-123.json', version: 123 };
window.console.log = () => {};

const popup = () => window.document.querySelector('vital-search-popup');

bootstrapPlugin(window, fs.readFileSync(PLUGIN_JS, 'utf8')).then(() => {
    check('no popup created synchronously on page load', popup() === null);
    check('no index fetched synchronously on page load', fetchCount === 0);

    // Let timers and microtasks drain first, so that deferring the same eager
    // work behind setTimeout or requestIdleCallback is still caught.
    setTimeout(() => {
        check('no popup created after the page settles', popup() === null);
        check('no index fetched after the page settles', fetchCount === 0);

        // Intent: hovering a trigger should warm the index without opening anything
        const trigger = window.document.querySelector('[data-vital-search-trigger]');
        trigger.dispatchEvent(new window.Event('pointerenter'));
        runIntentChecks(trigger);
    }, 50);
});

function runIntentChecks(trigger) {
    setTimeout(() => {
        check('popup created on hover', popup() !== null);
        check('exactly one popup created',
            window.document.querySelectorAll('vital-search-popup').length === 1);
        check('index fetch started on hover', fetchCount === 1);
        check('popup stays hidden on hover alone', popup().style.display !== 'flex');

        trigger.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        check('popup opens on click', popup().style.display === 'flex');
        check('click does not trigger a second fetch', fetchCount === 1);

        // Opening the popup must not announce loading. Nothing has been typed,
        // so there is nothing to wait for from the visitor's point of view.
        check('no message shown when the popup opens',
            popup().shadowRoot.getElementById('message').hidden === true);
        check('no results shown when the popup opens',
            popup().shadowRoot.getElementById('results').innerHTML.trim() === '');

        // Type while the index is still loading
        const input = popup().shadowRoot.getElementById('search');
        input.value = 'tomato';
        input.dispatchEvent(new window.Event('input'));

        setTimeout(() => {
            const results = popup().shadowRoot.getElementById('results');
            check('shows a loading state while the index is unready', /loading/i.test(results.innerHTML));

            releaseFetch();

            setTimeout(() => {
                const html = popup().shadowRoot.getElementById('results').innerHTML;
                check('query typed during load runs once ready', html.includes('search-item'));
                check('those results are real matches', /tomato/i.test(html));
                report();
            }, 300);
        }, 250);
    }, 50);
}
