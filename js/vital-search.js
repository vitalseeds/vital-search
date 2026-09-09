/**
 * Vital Search Web Component
 *
 * Uses FlexSearch for fast client-side search with IndexedDB caching.
 * Searches both products and categories.
 */

/**
 * Load an external script once, resolving when it has executed
 *
 * Repeated calls for the same URL share a single promise, so the script
 * is only ever requested once per page.
 *
 * @param {string} url Script URL
 * @returns {Promise<void>}
 */
const vitalSearchScriptCache = {};
function vitalSearchLoadScript(url) {
    if (!url) {
        return Promise.reject(new Error('No script URL configured'));
    }
    if (vitalSearchScriptCache[url]) {
        return vitalSearchScriptCache[url];
    }

    vitalSearchScriptCache[url] = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            // Allow a later attempt to retry rather than caching the failure
            delete vitalSearchScriptCache[url];
            reject(new Error('Failed to load ' + url));
        };
        document.head.appendChild(script);
    });

    return vitalSearchScriptCache[url];
}

class VitalSearchPopup extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });

        this.dataVersion = this.getAttribute('data-version') || '0';
        this.placeholder = this.getAttribute('data-placeholder') || 'Search products...';

        this.items = [];
        this.db = null;
        this.flexIndex = null;
        this.debounceTimer = null;
        this.initialized = false;
        this.selectedIndex = -1;
        // The FlexSearch index object exists before it is populated, so track
        // readiness separately rather than testing for the index itself.
        this.indexReady = false;
        // Query typed before the index finished loading, re-run once it is ready
        this.pendingQuery = null;

        this.render();
        this.setupEventListeners();
    }

    connectedCallback() {
        // The element is only created once the visitor shows search intent,
        // so loading the index here keeps it off every page's load path.
        if (!this.initialized) {
            this.initialized = true;
            this.initDatabase();
        }
    }

    render() {
        this.shadowRoot.innerHTML = `
            <style>
                :host {
                    display: none;
                    position: fixed;
                    inset: 0;
                    background: rgba(0, 0, 0, 0.7);
                    justify-content: center;
                    align-items: flex-start;
                    padding-top: 10vh;
                    padding-top: 10dvh;
                    z-index: 99999;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                }

                #container {
                    background: white;
                    padding: 1.5rem;
                    border-radius: 8px;
                    width: 90%;
                    max-width: 600px;
                    position: relative;
                    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                }

                #close {
                    display: none;
                    position: absolute;
                    top: 0.75rem;
                    right: 0.75rem;
                    background: transparent;
                    border: none;
                    font-size: 1.5rem;
                    cursor: pointer;
                    color: #666;
                    width: 32px;
                    height: 32px;
                    align-items: center;
                    justify-content: center;
                    border-radius: 4px;
                }

                #close:hover {
                    background: #f3f4f6;
                    color: #333;
                }

                #search {
                    width: 100%;
                    padding: 0.75rem 1rem;
                    font-size: 1.1rem;
                    border: 2px solid #e5e7eb;
                    border-radius: 6px;
                    outline: none;
                    box-sizing: border-box;
                }

                #search:focus {
                    border-color: #2c6e49;
                    box-shadow: 0 0 0 3px rgba(44, 110, 73, 0.1);
                }

                #results {
                    max-height: 70vh;
                    max-height: 70dvh;
                    overflow-y: auto;
                    margin-top: 0.5rem;
                }

                .search-item {
                    display: flex;
                    align-items: center;
                    padding: 0.75rem;
                    border-radius: 6px;
                    text-decoration: none;
                    color: inherit;
                    transition: background-color 0.15s;
                }

                .search-item:hover,
                .search-item.selected {
                    background: #f3f4f6;
                    outline: none;
                }

                .search-item img {
                    width: 50px;
                    height: 50px;
                    object-fit: cover;
                    border-radius: 4px;
                    margin-right: 0.75rem;
                    flex-shrink: 0;
                }

                .item-content {
                    flex: 1;
                    min-width: 0;
                }

                .title {
                    display: block;
                    font-weight: 600;
                    color: #1f2937;
                    margin-bottom: 0.25rem;
                }

                .title mark {
                    background: #fef08a;
                    color: inherit;
                    padding: 0 2px;
                    border-radius: 2px;
                }

                .meta {
                    font-size: 0.85rem;
                    color: #6b7280;
                }

                .section-heading {
                    font-size: 0.75rem;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: #6b7280;
                    padding: 0.75rem 0.75rem 0.5rem;
                    margin-top: 0.5rem;
                }

                .section-heading:first-child {
                    margin-top: 0;
                }

                .count-badge {
                    font-size: 0.75rem;
                    color: #6b7280;
                }

                .no-results {
                    padding: 2rem;
                    text-align: center;
                    color: #6b7280;
                }

                #loading {
                    padding: 2rem;
                    text-align: center;
                    color: #6b7280;
                }

                .sr-only {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    padding: 0;
                    margin: -1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    white-space: nowrap;
                    border: 0;
                }

                @media (max-width: 640px) {
                    :host {
                        padding-top: 0;
                        align-items: stretch;
                    }

                    #container {
                        width: 100%;
                        max-width: none;
                        height: 100vh;
                        height: 100dvh;
                        border-radius: 0;
                        padding: 1rem;
                        display: flex;
                        flex-direction: column;
                        box-sizing: border-box;
                    }

                    #close {
                        display: flex;
                        position: absolute;
                        top: 6px;
                        right: 0;
                        width: 60px;
                        height: 60px;
                        // background: rgba(255, 255, 255, 0.9);
                        z-index: 1;
                    }

                    #close svg {
                        width: 28px;
                        height: 28px;
                    }

                    #results {
                        flex: 1;
                        max-height: none;
                        overflow-y: auto;
                        order: 2;
                    }

                    #search {
                        order: 1;
                        margin-bottom: 0.75rem;
                        width: calc(100% - 42px);
                    }

                    #loading {
                        order: 2;
                    }
                }
            </style>
            <div id="container">
                <button id="close" aria-label="Close search">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <input id="search" type="search" placeholder="${this.escapeHtml(this.placeholder)}" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="results" aria-autocomplete="list">
                <div id="results" role="listbox" aria-label="Search results"></div>
                <div id="status" role="status" aria-live="polite" class="sr-only"></div>
                <div id="loading" hidden>Loading search index...</div>
            </div>
        `;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    setupEventListeners() {
        this.shadowRoot.getElementById('close').addEventListener('click', () => this.hide());

        const searchInput = this.shadowRoot.getElementById('search');
        searchInput.addEventListener('input', (e) => {
            clearTimeout(this.debounceTimer);
            const value = e.target.value; // Capture value immediately before debounce
            this.debounceTimer = setTimeout(() => this.search(value), 150);
        });

        this.addEventListener('click', (e) => {
            // Use composedPath to get actual clicked element (not retargeted by Shadow DOM)
            if (e.composedPath()[0] === this) this.hide();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.style.display === 'flex') {
                this.hide();
            }
        });

        searchInput.addEventListener('keydown', (e) => this.handleKeyNav(e));
    }

    /**
     * Load FlexSearch and idb on demand
     *
     * These are only needed once the visitor opens search, so they are not
     * enqueued with the page. Loading them here keeps two render-path script
     * requests off every page load.
     *
     * @returns {Promise<void>}
     */
    async loadLibraries() {
        const needsFlexSearch = typeof FlexSearch === 'undefined';
        const needsIdb = typeof idb === 'undefined';

        if (!needsFlexSearch && !needsIdb) {
            return;
        }

        const loadingEl = this.shadowRoot.getElementById('loading');
        loadingEl.textContent = 'Loading search...';
        loadingEl.hidden = false;

        const config = window.vitalSearch || {};
        const pending = [];

        if (needsFlexSearch) {
            pending.push(vitalSearchLoadScript(config.flexsearchUrl));
        }
        if (needsIdb) {
            pending.push(vitalSearchLoadScript(config.idbUrl));
        }

        await Promise.all(pending);
        console.log('[Vital Search] Libraries loaded');
    }

    async initDatabase() {
        const DB_NAME = 'vital-search';
        const DB_VERSION = 1;
        const loadingEl = this.shadowRoot.getElementById('loading');

        console.log('[Vital Search] Initializing database...');

        try {
            await this.loadLibraries();
        } catch (error) {
            console.error('[Vital Search] Failed to load libraries:', error);
            loadingEl.textContent = 'Failed to load search. Please try again.';
            loadingEl.hidden = false;
            return;
        }

        try {
            console.log('[Vital Search] Opening IndexedDB...');
            this.db = await idb.openDB(DB_NAME, DB_VERSION, {
                upgrade(db) {
                    console.log('[Vital Search] Upgrading IndexedDB schema...');
                    if (!db.objectStoreNames.contains('items')) {
                        db.createObjectStore('items', { keyPath: 'id' });
                    }
                    if (!db.objectStoreNames.contains('meta')) {
                        db.createObjectStore('meta', { keyPath: 'key' });
                    }
                }
            });
            console.log('[Vital Search] IndexedDB opened successfully');

            this.flexIndex = new FlexSearch.Index({
                tokenize: 'forward',
                cache: 100,
                resolution: 9
            });
            console.log('[Vital Search] FlexSearch index created');

            await this.loadItems();
        } catch (error) {
            console.error('[Vital Search] Failed to initialize:', error);
            loadingEl.textContent = 'Failed to initialize search. Check console for details.';
            loadingEl.hidden = false;
        }
    }

    async loadItems() {
        const loadingEl = this.shadowRoot.getElementById('loading');

        console.log('[Vital Search] Loading items...');
        console.log('[Vital Search] vitalSearch config:', window.vitalSearch);

        try {
            const cachedMeta = await this.db.get('meta', 'version');
            const serverVersion = window.vitalSearch?.version?.toString() || this.dataVersion;
            console.log('[Vital Search] Cached version:', cachedMeta?.value, 'Server version:', serverVersion);

            if (cachedMeta && cachedMeta.value === serverVersion) {
                console.log('[Vital Search] Using cached data');
                this.items = await this.db.getAll('items');
            } else {
                console.log('[Vital Search] Fetching fresh data...');
                loadingEl.hidden = false;

                const jsonUrl = window.vitalSearch?.jsonUrl || '/wp-content/uploads/search-index.json';
                console.log('[Vital Search] Fetching from:', jsonUrl);
                const response = await fetch(jsonUrl);

                if (!response.ok) {
                    if (response.status === 404) {
                        throw new Error('Search index not found. Please regenerate via Tools > Search Index.');
                    }
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();
                console.log('[Vital Search] Received', data.items?.length, 'items');

                const tx = this.db.transaction(['items', 'meta'], 'readwrite');
                const itemStore = tx.objectStore('items');
                const metaStore = tx.objectStore('meta');

                await itemStore.clear();
                for (const item of data.items) {
                    await itemStore.put(item);
                }
                await metaStore.put({ key: 'version', value: data.version.toString() });
                await tx.done;

                this.items = data.items;
                loadingEl.hidden = true;
            }

            console.log('[Vital Search] Building search index with', this.items.length, 'items');
            this.items.forEach(item => {
                // Don't index category names for products (type === 'product' or undefined)
                const isProduct = !item.type || item.type === 'product';
                const searchText = [
                    item.title,
                    item.latin_name,
                    item.sku,
                    ...(isProduct ? [] : (item.category || []))
                ].filter(Boolean).join(' ');
                this.flexIndex.add(item.id, searchText);
            });
            console.log('[Vital Search] Search index ready');
            this.indexReady = true;

            // Run any query typed while the index was still loading
            const pending = this.pendingQuery;
            this.pendingQuery = null;
            if (pending) {
                this.search(pending);
            }

        } catch (error) {
            console.error('[Vital Search] Failed to load items:', error);
            loadingEl.textContent = error.message || 'Failed to load search data.';
            loadingEl.hidden = false;
        }
    }

    show() {
        this.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        const searchInput = this.shadowRoot.getElementById('search');
        searchInput.value = '';
        this.shadowRoot.getElementById('results').innerHTML = '';
        setTimeout(() => searchInput.focus(), 50);
    }

    hide() {
        this.style.display = 'none';
        document.body.style.overflow = '';
        this.shadowRoot.getElementById('results').innerHTML = '';
    }

    search(query) {
        const resultsEl = this.shadowRoot.getElementById('results');
        const searchInput = this.shadowRoot.getElementById('search');
        const statusEl = this.shadowRoot.getElementById('status');
        console.log('[Vital Search] Searching for:', query);
        this.selectedIndex = -1; // Reset selection on new search
        searchInput.removeAttribute('aria-activedescendant');

        if (!query || query.length < 2) {
            resultsEl.innerHTML = '';
            searchInput.setAttribute('aria-expanded', 'false');
            statusEl.textContent = '';
            return;
        }

        if (!this.indexReady) {
            // Index still loading. Remember the query so it can be run
            // as soon as the index is ready.
            this.pendingQuery = query;
            resultsEl.innerHTML = '<div class="no-results">Search is loading...</div>';
            return;
        }

        console.log('[Vital Search] Items count:', this.items.length);
        const ids = this.flexIndex.search(query, { limit: 15 });
        console.log('[Vital Search] Found', ids.length, 'results:', ids);

        if (ids.length === 0) {
            resultsEl.innerHTML = '<div class="no-results">No results found</div>';
            searchInput.setAttribute('aria-expanded', 'false');
            statusEl.textContent = 'No results found';
            return;
        }

        searchInput.setAttribute('aria-expanded', 'true');
        statusEl.textContent = `${ids.length} result${ids.length === 1 ? '' : 's'} found`;

        // Group results by type:
        // categories first,
        // then products grouped by top category
        // then tags
        // then growing guides
        const results = ids.map(id => this.items.find(x => x.id === id)).filter(Boolean);
        const categories = results.filter(item => item.type === 'category')
            .sort((a, b) => a.title.localeCompare(b.title));
        const growingGuides = results.filter(item => item.type === 'growing-guide')
            .sort((a, b) => a.title.localeCompare(b.title));
        const products = results.filter(item => item.type !== 'category' && item.type !== 'growing-guide');

        // Group products by their top-level category
        const productsByTopCat = products.reduce((groups, item) => {
            const topCat = item.top_category || 'Products';
            (groups[topCat] = groups[topCat] || []).push(item);
            return groups;
        }, {});

        let html = '';
        let resultIndex = 0;

        if (categories.length > 0) {
            html += '<div class="section-heading">Categories</div>';
            html += categories.map(item => this.renderResultItem(item, resultIndex++, query)).join('');
        }

        // Render products grouped by their top-level category
        Object.keys(productsByTopCat).forEach(topCat => {
            html += `<div class="section-heading">${this.escapeHtml(topCat)}</div>`;
            html += productsByTopCat[topCat].map(item => this.renderResultItem(item, resultIndex++, query)).join('');
        });

        if (growingGuides.length > 0) {
            html += '<div class="section-heading">Growing Guides</div>';
            html += growingGuides.map(item => this.renderResultItem(item, resultIndex++, query)).join('');
        }

        resultsEl.innerHTML = html;
    }

    highlight(text, query) {
        if (!text) return '';
        const escaped = this.escapeHtml(text);
        const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return escaped.replace(regex, '<mark>$1</mark>');
    }

    /**
     * Render a single search result item
     *
     * @param {Object} item - The item to render
     * @param {number} index - The result index for aria attributes
     * @param {string} query - The search query for highlighting
     * @returns {string} HTML string
     */
    renderResultItem(item, index, query) {
        let meta = '';
        if (item.type === 'category') {
            meta = `<span class="count-badge">${item.count} products</span>`;
        } else if (item.latin_name) {
            meta = `<em>${this.escapeHtml(item.latin_name)}</em>`;
        }

        const thumbnailHtml = item.thumbnail
            ? `<img src="${this.escapeHtml(item.thumbnail)}" alt="" loading="lazy">`
            : '';

        return `
            <a href="${this.escapeHtml(item.url)}" class="search-item" role="option" id="result-${index}" aria-selected="false">
                ${thumbnailHtml}
                <div class="item-content">
                    <span class="title">${this.highlight(item.title, query)}</span>
                    <div class="meta">${meta}</div>
                </div>
            </a>
        `;
    }

    handleKeyNav(e) {
        const results = this.shadowRoot.querySelectorAll('.search-item');
        if (results.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.selectedIndex = this.selectedIndex < results.length - 1 ? this.selectedIndex + 1 : 0;
            this.updateSelection(results);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.selectedIndex = this.selectedIndex > 0 ? this.selectedIndex - 1 : results.length - 1;
            this.updateSelection(results);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // If a result is selected, click it; otherwise go to the first result
            if (this.selectedIndex >= 0 && results[this.selectedIndex]) {
                results[this.selectedIndex].click();
            } else if (results.length > 0) {
                results[0].click();
            }
        }
    }

    updateSelection(results) {
        const searchInput = this.shadowRoot.getElementById('search');

        results.forEach((item, i) => {
            const isSelected = i === this.selectedIndex;
            item.classList.toggle('selected', isSelected);
            item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });

        // Update aria-activedescendant and scroll into view
        if (this.selectedIndex >= 0 && results[this.selectedIndex]) {
            searchInput.setAttribute('aria-activedescendant', `result-${this.selectedIndex}`);
            results[this.selectedIndex].scrollIntoView({ block: 'nearest' });
        } else {
            searchInput.removeAttribute('aria-activedescendant');
        }
    }
}

customElements.define('vital-search-popup', VitalSearchPopup);

/**
 * Progressive enhancement: enhance search links to open popup
 *
 * Without JS or custom element support, the links navigate to /search as a fallback.
 * With JS and custom element support, clicks are intercepted to show the search popup.
 *
 * The popup is only created when the visitor shows search intent (hover, focus,
 * touch or click). Creating it is what triggers the index fetch, the IndexedDB
 * writes and the FlexSearch build, so deferring it keeps that work off the load
 * path of every page for the majority of visitors who never search.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Bail out if browser doesn't support custom elements - links will work as normal
    if (!('customElements' in window)) return;

    const triggers = document.querySelectorAll('[data-vital-search-trigger]');
    if (triggers.length === 0) return;

    let popup = null;

    /**
     * Create the popup on first use
     *
     * Appending it to the document fires connectedCallback, which loads the
     * search index. Safe to call repeatedly - the popup is only built once.
     *
     * @returns {VitalSearchPopup} The popup element
     */
    function ensurePopup() {
        if (popup) return popup;

        const firstTrigger = triggers[0];
        popup = document.createElement('vital-search-popup');
        popup.setAttribute('data-version', firstTrigger.dataset.vitalSearchVersion || '0');
        popup.setAttribute('data-placeholder', firstTrigger.dataset.vitalSearchPlaceholder || 'Search products...');
        document.body.appendChild(popup);
        return popup;
    }

    // Start loading on intent so the index is usually ready by the time the
    // popup opens. Pointer/focus fire well before click; touchstart covers mobile.
    const warmOptions = { once: true, passive: true };
    function warmOnIntent(element) {
        element.addEventListener('pointerenter', ensurePopup, warmOptions);
        element.addEventListener('touchstart', ensurePopup, warmOptions);
        element.addEventListener('focus', ensurePopup, warmOptions);
    }

    // Enhance all trigger links
    triggers.forEach(link => {
        warmOnIntent(link);
        link.addEventListener('click', (e) => {
            e.preventDefault();
            ensurePopup().show();
        });
    });

    // Replace Storefront handheld footer bar search with vital-search
    const handheldSearchLink = document.querySelector('.storefront-handheld-footer-bar li.search > a');
    if (handheldSearchLink) {
        warmOnIntent(handheldSearchLink);
        // Capture click before Storefront's handler (use capture phase)
        handheldSearchLink.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            ensurePopup().show();
        }, true);
    }

    // Hide the old mega menu search button (replaced by custom search)
    const oldSearchLink = document.querySelector('.mega-menu-link.dashicons-search');
    if (oldSearchLink && oldSearchLink.closest('.mega-menu-item')) {
        oldSearchLink.closest('.mega-menu-item').style.display = 'none';
    }
});
