#!/usr/bin/env node
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SPIKE_DIR = resolve(fileURLToPath(new URL('.', import.meta.url)));
const PHP_VERSION = process.env.PHP_VERSION || '8.4';
const ASYNC_MODE = process.env.ASYNC_MODE || 'jspi';
const EXTENSION_NAME = process.env.EXTENSION_NAME || 'wp_mysql_parser';
const SIDE_MODULE = resolve(
  process.env.SIDE_MODULE ||
    `${SPIKE_DIR}/dist/${EXTENSION_NAME}-php${PHP_VERSION}-${ASYNC_MODE}.so`
);
const PLAYGROUND_REPO = resolve(
  process.env.PLAYGROUND_REPO ||
    `${SPIKE_DIR}/../../../../wordpress-playground-spike`
);
const EXPECTED_MISSING = new Set(
  (process.env.PLAYGROUND_WEB_EXPECTED_MISSING_SYMBOLS || '')
    .split(/[\s,]+/)
    .filter(Boolean)
);

function findWasmFile(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = resolve(dir, entry.name);
    if (entry.isDirectory()) {
      const nested = findWasmFile(path);
      if (nested) {
        return nested;
      }
      continue;
    }
    if (entry.isFile() && entry.name.endsWith('.wasm')) {
      return path;
    }
  }
  return null;
}

function loadWasmModule(path) {
  if (!existsSync(path)) {
    console.error(`[web-compat] Missing ${path}`);
    process.exit(2);
  }
  return new WebAssembly.Module(readFileSync(path));
}

const phpDir = PHP_VERSION.replace('.', '-');
const webBuildDir = resolve(
  PLAYGROUND_REPO,
  `packages/php-wasm/web-builds/${phpDir}/${ASYNC_MODE}`
);
const webRuntime = findWasmFile(webBuildDir);
if (!webRuntime) {
  console.error(`[web-compat] Could not find a PHP.wasm file under ${webBuildDir}`);
  process.exit(2);
}

const sideImports = WebAssembly.Module.imports(loadWasmModule(SIDE_MODULE));
const importedFunctions = sideImports
  .filter((entry) => entry.module === 'env' && entry.kind === 'function')
  .map((entry) => entry.name)
  .sort();
const runtimeExports = new Map(
  WebAssembly.Module.exports(loadWasmModule(webRuntime)).map((entry) => [
    entry.name,
    entry.kind,
  ])
);
const missing = importedFunctions.filter(
  (name) => runtimeExports.get(name) !== 'function'
);
const unexpectedMissing = missing.filter((name) => !EXPECTED_MISSING.has(name));
const staleExpectedMissing = [...EXPECTED_MISSING].filter(
  (name) => !missing.includes(name)
);

console.log(`[web-compat] PHP ${PHP_VERSION} side module: ${SIDE_MODULE}`);
console.log(`[web-compat] PHP ${PHP_VERSION} web runtime: ${webRuntime}`);

if (unexpectedMissing.length > 0 || staleExpectedMissing.length > 0) {
  if (unexpectedMissing.length > 0) {
    console.error(
      `[web-compat] Missing browser runtime exports: ${unexpectedMissing.join(', ')}`
    );
  }
  if (staleExpectedMissing.length > 0) {
    console.error(
      `[web-compat] Expected missing exports are now available: ${staleExpectedMissing.join(', ')}`
    );
    console.error(
      '[web-compat] Update the browser demo PHP pin and remove the stale allowlist.'
    );
  }
  process.exit(1);
}

if (missing.length > 0) {
  console.log(
    `[web-compat] Known browser runtime gap confirmed: ${missing.join(', ')}`
  );
} else {
  console.log('[web-compat] Browser runtime exports all imported functions.');
}
