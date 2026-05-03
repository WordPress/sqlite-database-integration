#!/usr/bin/env node
import { existsSync } from 'node:fs';
import { writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const DEFAULT_PHP_VERSIONS = ['8.5', '8.4', '8.3', '8.2', '8.1', '8.0'];

const [, , outDirArg, ...argvPhpVersions] = process.argv;
const outDir = resolve(outDirArg || 'dist');
const extensionName = process.env.EXTENSION_NAME || 'wp_mysql_parser';
const asyncMode = process.env.ASYNC_MODE || 'jspi';
const version = process.env.EXTENSION_VERSION || '';
const envPhpVersions = (process.env.PHP_VERSIONS || '')
  .split(/[,\s]+/)
  .filter(Boolean);
const phpVersions =
  argvPhpVersions.length > 0
    ? argvPhpVersions
    : envPhpVersions.length > 0
      ? envPhpVersions
      : DEFAULT_PHP_VERSIONS;

const missingArtifacts = [];
const artifacts = phpVersions.map((phpVersion) => {
  const sourcePath = `${extensionName}-php${phpVersion}-${asyncMode}.so`;
  if (!existsSync(resolve(outDir, sourcePath))) {
    missingArtifacts.push(sourcePath);
  }
  return {
    phpVersion,
    sourcePath,
  };
});

if (asyncMode !== 'jspi') {
  console.error(
    `Unsupported ASYNC_MODE: ${asyncMode}. Playground external extension manifests are JSPI-only.`
  );
  process.exit(1);
}

if (missingArtifacts.length > 0) {
  console.error(
    `Missing extension artifact(s) in ${outDir}: ${missingArtifacts.join(', ')}`
  );
  process.exit(1);
}

const manifest = {
  name: extensionName,
  mode: 'php-extension',
  artifacts,
};

if (version) {
  manifest.version = version;
}

const manifestPath = resolve(outDir, 'manifest.json');
await writeFile(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);
console.log(`Wrote ${manifestPath}`);
