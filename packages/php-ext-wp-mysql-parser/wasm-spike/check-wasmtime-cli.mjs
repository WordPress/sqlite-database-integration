#!/usr/bin/env node
import { spawnSync } from 'node:child_process';
import {
  existsSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  statSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SPIKE_DIR = resolve(fileURLToPath(new URL('.', import.meta.url)));
const PHP_VERSION = process.env.PHP_VERSION || '8.4';
const ASYNC_MODE = process.env.ASYNC_MODE || 'jspi';
const EXTENSION_NAME = process.env.EXTENSION_NAME || 'wp_mysql_parser';
const SIDE_MODULE = resolve(
  process.env.SIDE_MODULE ||
    `${SPIKE_DIR}/dist/${EXTENSION_NAME}-php${PHP_VERSION}-${ASYNC_MODE}.so`
);
const WASMTIME = process.env.WASMTIME || 'wasmtime';
const EXPECTED_WASMTIME_VERSION =
  process.env.WASMTIME_EXPECTED_VERSION || process.env.WASMTIME_VERSION || '';
const WASMTIME_WASM_FLAGS = ['-W', 'all-proposals=y'];
const LEGACY_EXCEPTIONS_DIAGNOSTIC =
  /legacy_exceptions feature required for try instruction/i;

function fail(message) {
  console.error(`[wasmtime-cli] ${message}`);
  process.exit(1);
}

function runWasmtime(args, options = {}) {
  return spawnSync(WASMTIME, args, {
    encoding: 'utf8',
    ...options,
  });
}

function requireSuccess(label, result) {
  if (result.status === 0) {
    return;
  }

  failWithCommandOutput(label, result);
}

function failWithCommandOutput(label, result) {
  fail(
    `${label} failed with exit ${result.status ?? 'unknown'}\n` +
      `stdout:\n${result.stdout || '<empty>'}\n` +
      `stderr:\n${result.stderr || '<empty>'}`
  );
}

function commandOutput(result) {
  return `${result.stdout || ''}\n${result.stderr || ''}`;
}

function isLegacyExceptionsRejection(result) {
  return result.status !== 0 && LEGACY_EXCEPTIONS_DIAGNOSTIC.test(commandOutput(result));
}

if (!existsSync(SIDE_MODULE)) {
  fail(`Missing side module: ${SIDE_MODULE}`);
}

const sideModuleBytes = readFileSync(SIDE_MODULE);
let wasmModule;
try {
  wasmModule = new WebAssembly.Module(sideModuleBytes);
} catch (error) {
  fail(`Node could not parse the side module as WebAssembly: ${error.message}`);
}

const imports = WebAssembly.Module.imports(wasmModule);
const exports = WebAssembly.Module.exports(wasmModule);
const envImports = imports.filter((entry) => entry.module === 'env');
const exportNames = new Set(exports.map((entry) => entry.name));

if (envImports.length === 0) {
  fail('Expected Emscripten side-module imports from the env module.');
}

if (!exportNames.has('get_module')) {
  fail('Expected the PHP extension side module to export get_module.');
}

const version = runWasmtime(['--version']);
requireSuccess('wasmtime --version', version);
const versionText = `${version.stdout}${version.stderr}`.trim();
const expectedVersionText = EXPECTED_WASMTIME_VERSION.replace(/^v/i, '');
if (
  EXPECTED_WASMTIME_VERSION &&
  !versionText.includes(EXPECTED_WASMTIME_VERSION) &&
  !versionText.includes(expectedVersionText)
) {
  fail(
    `Expected Wasmtime ${EXPECTED_WASMTIME_VERSION}, got ${JSON.stringify(
      versionText
    )}`
  );
}
console.log(`[wasmtime-cli] ${versionText}`);
console.log(`[wasmtime-cli] wasm flags: ${WASMTIME_WASM_FLAGS.join(' ')}`);

const tempDir = mkdtempSync(join(tmpdir(), 'wp-mysql-parser-wasmtime-'));
try {
  const compiledPath = join(tempDir, `${basename(SIDE_MODULE)}.cwasm`);
  const compile = runWasmtime([
    'compile',
    ...WASMTIME_WASM_FLAGS,
    '--target',
    'x86_64-unknown-linux',
    '-o',
    compiledPath,
    SIDE_MODULE,
  ]);
  const run = runWasmtime(['run', ...WASMTIME_WASM_FLAGS, SIDE_MODULE]);

  if (compile.status !== 0) {
    if (!isLegacyExceptionsRejection(compile)) {
      failWithCommandOutput('wasmtime compile', compile);
    }
    if (!isLegacyExceptionsRejection(run)) {
      failWithCommandOutput('wasmtime run legacy-exceptions rejection check', run);
    }

    console.log(`[wasmtime-cli] side module: ${SIDE_MODULE}`);
    console.log(`[wasmtime-cli] imports: ${imports.length} (${envImports.length} from env)`);
    console.log(`[wasmtime-cli] exports: ${exports.map((entry) => entry.name).join(', ')}`);
    console.log(
      '[wasmtime-cli] compile and run reached Wasmtime validation and rejected legacy exception instructions as expected for current Playground JSPI output.'
    );
  } else {
    if (!existsSync(compiledPath) || statSync(compiledPath).size === 0) {
      fail(`wasmtime compile did not produce a non-empty ${compiledPath}`);
    }

    const objdump = runWasmtime([
      'objdump',
      '--funcs',
      'wasm',
      '--filter',
      'get_module',
      compiledPath,
    ]);
    requireSuccess('wasmtime objdump', objdump);

    if (run.status === 0) {
      fail(
        'Expected wasmtime run to reject this Emscripten PHP side module as a standalone WASI command.'
      );
    }

    const runOutput = commandOutput(run);
    if (
      !/import|instantiate|link|unknown|wasi|start|command/i.test(runOutput)
    ) {
      fail(
        `wasmtime run failed for an unexpected reason.\n` +
          `stdout:\n${run.stdout || '<empty>'}\n` +
          `stderr:\n${run.stderr || '<empty>'}`
      );
    }

    console.log(`[wasmtime-cli] side module: ${SIDE_MODULE}`);
    console.log(`[wasmtime-cli] imports: ${imports.length} (${envImports.length} from env)`);
    console.log(`[wasmtime-cli] exports: ${exports.map((entry) => entry.name).join(', ')}`);
    console.log('[wasmtime-cli] compile, objdump, and expected non-standalone run rejection passed.');
  }
} finally {
  rmSync(tempDir, { recursive: true, force: true });
}
