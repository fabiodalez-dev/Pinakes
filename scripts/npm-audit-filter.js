#!/usr/bin/env node
'use strict';

/**
 * Decide whether an npm audit report blocks, after applying the waivers in
 * .github/npm-audit-waivers.json.
 *
 * Usage: node scripts/npm-audit-filter.js <full-audit.json> <prod-audit.json> [waivers.json]
 *   full-audit.json  `npm audit --json` (dev dependencies included)
 *   prod-audit.json  `npm audit --omit=dev --json`
 *
 * Exit 0 when every high/critical advisory is waived, 1 when any is not, 2 on
 * unreadable input. A waiver only ever covers a build-time dependency: an
 * advisory that also appears in the production audit blocks whatever the
 * waiver file says. Each waiver carries an expiry date, and an expired or
 * malformed one counts as absent, so a waiver cannot outlive the reason for
 * it without the build turning red again. NPM_AUDIT_TODAY (YYYY-MM-DD)
 * overrides today's date, for the tests.
 */

const fs = require('fs');
const path = require('path');

const BLOCKING = new Set(['high', 'critical']);
const GHSA = /GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}/i;

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    console.error(`npm-audit-filter: cannot read ${file}: ${error.message}`);
    process.exit(2);
  }
}

/** The high/critical advisories in a report, keyed by GHSA id. Transitive entries only point at them. */
function advisories(report) {
  const found = new Map();
  for (const [pkg, vuln] of Object.entries(report.vulnerabilities || {})) {
    for (const via of vuln.via || []) {
      if (typeof via !== 'object' || via === null || !BLOCKING.has(via.severity)) continue;
      const match = GHSA.exec(String(via.url || ''));
      const id = match ? match[0].toUpperCase() : `npm-${via.source ?? pkg}`;
      found.set(id, { id, package: via.name || pkg, severity: via.severity, title: via.title || '' });
    }
  }
  return found;
}

function activeWaivers(list, today) {
  const active = new Map();
  for (const waiver of Array.isArray(list) ? list : []) {
    const id = String(waiver.id || '').toUpperCase();
    const expires = String(waiver.expires || '');
    if (!GHSA.test(id) || !/^\d{4}-\d{2}-\d{2}$/.test(expires) || String(waiver.reason || '').trim() === '') {
      console.log(`  ignored malformed waiver: ${JSON.stringify(waiver)}`);
      continue;
    }
    if (expires < today) {
      console.log(`  waiver for ${id} expired on ${expires}: check whether a fixed release exists, then remove or renew it`);
      continue;
    }
    active.set(id, waiver);
  }
  return active;
}

function main() {
  const [fullFile, prodFile, waiverFile] = process.argv.slice(2);
  if (!fullFile || !prodFile) {
    console.error('usage: npm-audit-filter.js <full-audit.json> <prod-audit.json> [waivers.json]');
    process.exit(2);
  }
  const today = process.env.NPM_AUDIT_TODAY || new Date().toISOString().slice(0, 10);
  const waivers = activeWaivers(
    readJson(waiverFile || path.join(__dirname, '..', '.github', 'npm-audit-waivers.json')),
    today,
  );
  const full = advisories(readJson(fullFile));
  const prod = advisories(readJson(prodFile));

  const blocking = [];
  for (const advisory of full.values()) {
    if (prod.has(advisory.id)) {
      blocking.push(`${advisory.id} (${advisory.package}, ${advisory.severity}) reaches production dependencies`);
    } else if (!waivers.has(advisory.id)) {
      blocking.push(`${advisory.id} (${advisory.package}, ${advisory.severity})`);
    } else {
      console.log(`  waived until ${waivers.get(advisory.id).expires}: ${advisory.id} (${advisory.package}, ${advisory.severity}, build-time only)`);
    }
  }
  // An advisory only the production audit reports still blocks.
  for (const advisory of prod.values()) {
    if (!full.has(advisory.id)) blocking.push(`${advisory.id} (${advisory.package}, ${advisory.severity}) reaches production dependencies`);
  }

  if (blocking.length > 0) {
    console.log('Blocking high/critical advisories:');
    for (const line of blocking) console.log(`  ${line}`);
    process.exit(1);
  }
  console.log('npm audit: no blocking high/critical advisory (waived build-time advisories listed above)');
  process.exit(0);
}

main();
