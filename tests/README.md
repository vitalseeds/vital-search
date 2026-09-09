# vital-search tests

Regression cover for the page-load performance work: the plugin must not do
search work until the visitor actually wants to search.

## Running

```sh
npm install     # jsdom, for the JS suites only
./tests/run.sh
```

Or individually:

```sh
node tests/lazy-init.test.js
node tests/on-demand-libraries.test.js
php tests/index-files.test.php
```

The JS suites report `SKIP` if jsdom is not installed. `run.sh` falls back to
the PHP binary Local ships if there is no usable `php` on `PATH`.

## What each suite covers

| Suite | Covers |
| --- | --- |
| `lazy-init.test.js` | Nothing is created or fetched until search intent; a query typed while the index is loading shows a loading state and then runs |
| `on-demand-libraries.test.js` | FlexSearch and idb are not requested on page load, and come from the plugin rather than a public CDN |
| `index-files.test.php` | Versioned index filenames, the upgrade fallback, atomic writes, and pruning that spares unrelated files |

## Notes

These are plain scripts with no test framework, matching the repo's
no-build-tooling convention. `package.json` exists only to pull in jsdom as a
dev dependency; nothing in it is needed to run the plugin.

`tests/fixtures/search-index.json` is a small synthetic index in the same shape
as a real export. It is deliberately not a copy of production data.

The JS suites stub `idb` rather than using a real IndexedDB, so they verify
*when* the plugin does its work, not the persistence itself.
