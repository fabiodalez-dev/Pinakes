#!/usr/bin/env node
'use strict';

const expectedMajor = 22;
const actualMajor = Number(process.versions.node.split('.')[0]);

if (actualMajor !== expectedMajor) {
  console.error(
    `Pinakes production assets require Node ${expectedMajor}.x; ` +
    `current runtime is ${process.version}. Use the version in .nvmrc.`
  );
  process.exit(1);
}
