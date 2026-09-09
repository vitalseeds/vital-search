/**
 * Shared helpers for the vital-search JS tests
 */
const path = require('path');

const PLUGIN_JS = path.join(__dirname, '..', 'js', 'vital-search.js');
const FIXTURE = path.join(__dirname, 'fixtures', 'search-index.json');

const results = [];

/**
 * Record a check result
 *
 * @param {string} name What is being asserted
 * @param {boolean} condition Whether it holds
 */
function check(name, condition) {
    results.push({ name, passed: !!condition });
    console.log((condition ? 'PASS  ' : 'FAIL  ') + name);
}

/**
 * Print a summary and exit non-zero if anything failed
 */
function report() {
    const failed = results.filter(r => !r.passed);
    console.log('\n' + (results.length - failed.length) + '/' + results.length + ' checks passed');
    process.exit(failed.length ? 1 : 0);
}

/**
 * Load jsdom, exiting with the skip code if it is not installed
 *
 * @returns {Function} The JSDOM constructor
 */
function loadJsdom() {
    try {
        return require('jsdom').JSDOM;
    } catch (error) {
        console.log('SKIP  jsdom is not installed. Run: npm install');
        process.exit(2); // run.sh reports this as skipped, not failed
    }
}

/**
 * Evaluate the plugin in a jsdom window and fire DOMContentLoaded once
 *
 * jsdom fires its own DOMContentLoaded as the document settles. Evaluating
 * the plugin before that happens would leave its listener bound to both
 * jsdom's event and the one dispatched here, running the bootstrap twice and
 * creating two popups - something a real browser never does. So wait for the
 * document to finish loading first, then evaluate and dispatch exactly once.
 *
 * @param {Object} window jsdom window
 * @param {string} source Plugin JS source
 * @returns {Promise<void>} Resolves once the bootstrap has run
 */
function bootstrapPlugin(window, source) {
    return new Promise(resolve => {
        const start = () => {
            window.eval(source);
            window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
            resolve();
        };

        if (window.document.readyState === 'complete') {
            start();
        } else {
            window.addEventListener('load', start, { once: true });
        }
    });
}

/**
 * Minimal in-memory stand-in for the idb library
 *
 * Covers only the surface vital-search.js uses: openDB, get, getAll and a
 * readwrite transaction over the items and meta stores.
 *
 * @returns {Object} An idb-shaped stub
 */
function makeIdbStub() {
    const makeStore = () => {
        const entries = new Map();
        return {
            clear: async () => entries.clear(),
            put: async (value) => entries.set(value.id || value.key, value),
            entries,
        };
    };

    const stores = { items: makeStore(), meta: makeStore() };

    return {
        openDB: async () => ({
            get: async (store, key) => stores[store].entries.get(key),
            getAll: async (store) => [...stores[store].entries.values()],
            transaction: () => ({
                objectStore: (name) => stores[name],
                done: Promise.resolve(),
            }),
        }),
    };
}

module.exports = { check, report, loadJsdom, makeIdbStub, bootstrapPlugin, PLUGIN_JS, FIXTURE };
