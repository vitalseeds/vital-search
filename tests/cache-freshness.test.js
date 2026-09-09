/**
 * Index cache freshness
 *
 * The IndexedDB cache is keyed on the version the page advertises. Opening
 * search twice against the same version must reuse the cache rather than
 * refetching and rewriting every item.
 *
 * The case that matters is a cached JSON body whose version lags the page.
 * A long-lived CDN cache on the index makes that reachable, and keying the
 * stored version off the body rather than the page turns it into a refetch
 * on every single page load.
 *
 * Usage: node tests/cache-freshness.test.js
 */
const fs = require('fs');
const path = require('path');
const { check, report, loadJsdom, makeIdbStub, bootstrapPlugin, PLUGIN_JS, FIXTURE } = require('./helpers');

const JSDOM = loadJsdom();
const INDEX = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const FLEXSEARCH = path.join(__dirname, '..', 'vendor', 'flexsearch.bundle.min.js');

/**
 * Open search in a fresh page that shares one IndexedDB, and report fetches
 *
 * @param {Object} sharedIdb idb stub shared across the runs
 * @param {number|string} pageVersion Version the page advertises
 * @param {Object} body Index payload the server returns
 * @returns {Promise<{fetches: number, items: number}>} Fetches made during
 *          this page view, and how many items the popup ended up holding
 */
function visitPage(sharedIdb, pageVersion, body) {
    return new Promise(resolve => {
        const dom = new JSDOM(`<!doctype html><html><body>
            <a href="/search" data-vital-search-trigger data-vital-search-version="${pageVersion}">Search</a>
        </body></html>`, { runScripts: 'outside-only', pretendToBeVisual: true });
        const { window } = dom;

        let fetches = 0;
        window.FlexSearch = require(FLEXSEARCH);
        window.idb = sharedIdb;
        window.fetch = async () => {
            fetches++;
            return { ok: true, status: 200, json: async () => body };
        };
        window.vitalSearch = { jsonUrl: '/search-index-' + pageVersion + '.json', version: pageVersion };
        window.console.log = () => {};

        bootstrapPlugin(window, fs.readFileSync(PLUGIN_JS, 'utf8')).then(() => {
            window.document.querySelector('[data-vital-search-trigger]')
                .dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
            setTimeout(() => {
                const popup = window.document.querySelector('vital-search-popup');
                resolve({ fetches, items: popup ? popup.items.length : 0 });
            }, 400);
        });
    });
}

(async () => {
    // One IndexedDB, as a real visitor would have across page views
    const sharedIdb = makeIdbStub();

    const total = INDEX.items.length;

    const first = await visitPage(sharedIdb, 500, { ...INDEX, version: 500 });
    check('first visit fetches the index', first.fetches === 1);
    check('first visit loads every item', first.items === total);

    const second = await visitPage(sharedIdb, 500, { ...INDEX, version: 500 });
    check('second visit at the same version uses the cache', second.fetches === 0);
    // Reads back what the batched write persisted. A partial batch, or a
    // clear that landed after the puts, would show up as a short count here.
    check('cached visit restores every item', second.items === total);

    const third = await visitPage(sharedIdb, 600, { ...INDEX, version: 600 });
    check('a new version refetches', third.fetches === 1);
    check('refetched visit loads every item', third.items === total);

    // A stale cached body: the page says 700, the response still says 600.
    const stale = makeIdbStub();
    const staleFirst = await visitPage(stale, 700, { ...INDEX, version: 600 });
    check('stale cached body still fetches once', staleFirst.fetches === 1);

    const staleSecond = await visitPage(stale, 700, { ...INDEX, version: 600 });
    check('stale cached body does not refetch on every page load', staleSecond.fetches === 0);
    check('stale cached body still restores every item', staleSecond.items === total);

    report();
})();
