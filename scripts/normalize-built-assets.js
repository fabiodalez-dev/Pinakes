#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const assetPaths = [
  path.resolve(__dirname, '../public/assets/main.css'),
];

for (const assetPath of assetPaths) {
  const contents = fs.readFileSync(assetPath, 'utf8');
  const normalized = contents
    .replace(/[ \t]+$/gm, '')
    .replace(
      '.disabled\\:hover\\:bg-gray-800:hover:disabled {\n  --tw-bg-opacity: 1;\n  background-color:',
      '.disabled\\:hover\\:bg-gray-800:hover:disabled {\n  --tw-bg-opacity: 1;\n\n  background-color:'
    )
    .trimEnd();
  fs.writeFileSync(assetPath, `${normalized}\n`);
}

// Webpack content-hashes emitted fonts and WASM, but output.clean cannot be
// enabled because public/assets also contains application-managed files. Drop
// only unreferenced hash-named resources so dependency upgrades cannot leave
// obsolete binaries in the release forever.
const assetsDir = path.resolve(__dirname, '../public/assets');
const references = fs.readdirSync(assetsDir)
  .filter((file) => file.endsWith('.css') || file.endsWith('.js'))
  .map((file) => fs.readFileSync(path.join(assetsDir, file), 'utf8'))
  .join('\n');

for (const file of fs.readdirSync(assetsDir)) {
  if (!/^[a-f0-9]{20}\.(?:woff2?|wasm)$/.test(file)) continue;
  if (!references.includes(file)) {
    fs.unlinkSync(path.join(assetsDir, file));
    process.stdout.write(`Removed stale generated asset: ${file}\n`);
  }
}
