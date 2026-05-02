// Headless Playground runner for the wasm-spike. Uses the stock
// load-built-extension.mjs harness shipped with @php-wasm/compile-extension
// (PR #3567), which already exercises loadPHPExtension via the PR #3566
// `manifest` source format. We feed it our manifest and a snippet of PHP
// that pokes the Rust parser, then assert the output.
//
// Run with:
//   node packages/php-ext-wp-mysql-parser/wasm-spike/run-spike.mjs
//
// Optional env: PLAYGROUND_REPO=/path/to/wordpress-playground checkout.

import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const SPIKE_DIR = here;
const MANIFEST = resolve(SPIKE_DIR, 'dist/manifest.json');
const PHP_VERSION = '8.4';
const ASYNC_MODE = 'jspi';

if (!existsSync(MANIFEST)) {
  console.error(`[spike] Missing ${MANIFEST}. Run build-in-docker-rust.sh first.`);
  process.exit(2);
}

const PLAYGROUND_REPO =
  process.env.PLAYGROUND_REPO ||
  resolve(here, '../../../../wordpress-playground-spike');

if (!existsSync(resolve(PLAYGROUND_REPO, 'package.json'))) {
  console.error(`[spike] PLAYGROUND_REPO not found at ${PLAYGROUND_REPO}`);
  process.exit(2);
}

const PHP_CODE = `<?php
$missing = [];
foreach ([
  'WP_MySQL_Native_Lexer',
  'WP_MySQL_Native_Parser',
  'WP_MySQL_Native_Grammar',
  'WP_MySQL_Native_Token_Stream',
] as $class) {
  if (!class_exists($class)) { $missing[] = $class; }
}
if ($missing) {
  echo 'MISSING:', implode(',', $missing);
  exit;
}

$lexer = new WP_MySQL_Native_Lexer('SELECT 1 FROM wp_posts');
$names = [];
while ($lexer->next_token()) {
  $token = $lexer->get_token();
  $names[] = WP_MySQL_Native_Lexer::get_token_name($token->get_type());
}
echo 'TOKENS=', implode(',', $names);
`;

const EXPECTED =
  'TOKENS=SELECT_SYMBOL,INT_NUMBER,FROM_SYMBOL,IDENTIFIER';

const cmd = [
  '--experimental-wasm-jspi',
  '--experimental-strip-types',
  '--experimental-transform-types',
  '--disable-warning=ExperimentalWarning',
  '--import',
  resolve(
    PLAYGROUND_REPO,
    'packages/meta/src/node-es-module-loader/register.mts'
  ),
  resolve(
    PLAYGROUND_REPO,
    'packages/php-wasm/compile-extension/tests/load-built-extension.mjs'
  ),
  MANIFEST,
  PHP_VERSION,
  PHP_CODE,
  EXPECTED,
];

console.error('[spike] running load-built-extension.mjs against', MANIFEST);
const result = spawnSync(process.execPath, cmd, {
  cwd: PLAYGROUND_REPO,
  stdio: 'inherit',
});
process.exit(result.status ?? 1);
