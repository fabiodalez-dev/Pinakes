#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const assetPaths = [
  path.resolve(__dirname, '../public/assets/main.css'),
];

for (const assetPath of assetPaths) {
  const contents = fs.readFileSync(assetPath, 'utf8');
  const normalized = contents.replace(/[ \t]+$/gm, '').trimEnd();
  fs.writeFileSync(assetPath, `${normalized}\n`);
}
