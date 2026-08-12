#!/usr/bin/env node
'use strict';

const fs = require('fs');

const resultPath = process.argv[2];
if (!resultPath || !fs.existsSync(resultPath)) {
  console.error(`✗ Playwright JSON result not found: ${resultPath || '<missing argument>'}`);
  process.exit(1);
}

const report = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
const stats = report.stats || {};
const expected = Number(stats.expected || 0);
const skipped = Number(stats.skipped || 0);
const unexpected = Number(stats.unexpected || 0);
const flaky = Number(stats.flaky || 0);
const durationSeconds = (Number(stats.duration || 0) / 1000).toFixed(1);
const skipReasons = [];

function visitSuite(suite) {
  for (const spec of suite.specs || []) {
    for (const test of spec.tests || []) {
      if (test.status !== 'skipped') continue;
      const annotations = test.annotations || [];
      const reason = annotations.find((annotation) => annotation.type === 'skip')?.description || '';
      skipReasons.push({ title: spec.title, file: spec.file || suite.file || '', reason });
    }
  }
  for (const child of suite.suites || []) visitSuite(child);
}
for (const suite of report.suites || []) visitSuite(suite);

const fatalReason = /(missing|required|not configured|cannot reach db|app not installed|setup failed|login failed|not seeded|not found|no .* seeded|set e2e_)/i;
const suspicious = skipReasons.filter(({ reason }) => !reason.trim() || fatalReason.test(reason));
let failed = false;

if (unexpected > 0) {
  console.error(`✗ ${unexpected} unexpected Playwright failure(s)`);
  failed = true;
}
if (flaky > 0) {
  console.error(`✗ ${flaky} flaky test(s): retries must not turn nondeterminism green`);
  failed = true;
}
if (expected === 0) {
  console.error('✗ The suite executed zero passing tests');
  failed = true;
}
if (suspicious.length > 0) {
  console.error(`✗ ${suspicious.length} suspicious skip(s) hide missing fixtures or infrastructure:`);
  for (const item of suspicious.slice(0, 30)) {
    console.error(`  - ${item.file}: ${item.title} — ${item.reason || '<no reason>'}`);
  }
  failed = true;
}

const summary = [
  '## Playwright regression audit',
  '',
  '| Passed | Skipped | Unexpected | Flaky | Duration |',
  '|---:|---:|---:|---:|---:|',
  `| ${expected} | ${skipped} | ${unexpected} | ${flaky} | ${durationSeconds}s |`,
  '',
  suspicious.length ? `Suspicious skips: **${suspicious.length}** (gate failed).` : 'No suspicious infrastructure/fixture skips.',
  '',
].join('\n');

if (process.env.GITHUB_STEP_SUMMARY) {
  fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, summary);
}
process.stdout.write(summary);
process.exit(failed ? 1 : 0);
