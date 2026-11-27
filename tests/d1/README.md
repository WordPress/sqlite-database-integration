# D1 compatibility tests

These local tests accompany [issue #288](https://github.com/WordPress/sqlite-database-integration/issues/288). They exercise the SQLite operations a D1 backend would need. Miniflare creates an in-memory database with synthetic data.

From the repository root, with Node.js 22 and npm:

```sh
d1_test_deps=$(mktemp -d)
npm install --prefix "$d1_test_deps" --no-save miniflare@5.20261118.0-beta
NODE_PATH="$d1_test_deps/node_modules" node --test tests/d1/compatibility.test.cjs
```

The tests cover prepared statements, write metadata, atomic batches, and the D1 behavior for transaction commands and the PHP-backed `md5()` function used by the local driver.
