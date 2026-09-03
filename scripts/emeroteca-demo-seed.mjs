#!/usr/bin/env node
/**
 * Emeroteca demo seeder — drives the REAL admin forms with Playwright.
 *
 * Every testata, annata, fascicolo, cover and article below is created
 * through the actual UI (login, form fill, submit, file upload): no SQL
 * inserts, so the seeding itself exercises the whole write path. Data is
 * intentionally LEFT in place so the public /emeroteca views can be
 * inspected with realistic content. Re-running creates duplicates: wipe
 * the demo testate from /admin/periodicals first if you need a fresh run.
 *
 * Usage:
 *   SEED_BASE_URL=http://localhost:8081 \
 *   SEED_ADMIN_EMAIL=... SEED_ADMIN_PASS=... \
 *   SEED_COVERS_DIR=/tmp/emeroteca-covers \
 *   node scripts/emeroteca-demo-seed.mjs
 */
import { chromium } from '@playwright/test';
import fs from 'fs';
import path from 'path';

function allowedSeedUrl(rawUrl) {
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch {
    throw new Error(`SEED_BASE_URL non valido: ${rawUrl}`);
  }
  const host = parsed.hostname.toLowerCase();
  const octets = host.split('.');
  const ipv4Loopback = octets.length === 4
    && octets[0] === '127'
    && octets.every((part) => /^\d{1,3}$/.test(part) && Number(part) <= 255);
  const loopback = host === 'localhost' || host === '[::1]' || ipv4Loopback;
  if (parsed.protocol !== 'https:' && !(parsed.protocol === 'http:' && loopback)) {
    throw new Error('SEED_BASE_URL deve usare HTTPS; HTTP è consentito solo su localhost/loopback.');
  }
  return parsed;
}

const BASE_URL = allowedSeedUrl(process.env.SEED_BASE_URL || 'http://localhost:8081');
const BASE = BASE_URL.href.replace(/\/$/, '');
const EMAIL = process.env.SEED_ADMIN_EMAIL || 'e2e-admin@test.local';
const PASS = process.env.SEED_ADMIN_PASS || 'Test1234!Aa';
const COVERS = process.env.SEED_COVERS_DIR || '/tmp/emeroteca-covers';

const log = (m) => console.log(`[seed] ${m}`);

const browser = await chromium.launch();
const page = await browser.newPage();

// Guardia a livello di rete per l'INTERA sessione: nessuna richiesta può
// lasciare l'origine configurata, quindi nemmeno un redirect 307/308 durante
// il submit del login può inoltrare le credenziali a un'altra origine o a HTTP.
await page.route('**/*', (route) => {
  let origin = '';
  try {
    origin = new URL(route.request().url()).origin;
  } catch {
    return route.abort('blockedbyclient');
  }
  return origin === BASE_URL.origin ? route.continue() : route.abort('blockedbyclient');
});

async function login() {
  await page.goto(`${BASE}/accedi`);
  // Validate the final URL too: page.goto() may have followed a redirect.
  // Credentials are never filled on cleartext non-loopback destinations,
  // nor on a DIFFERENT origin than the one the operator configured.
  const finalUrl = allowedSeedUrl(page.url());
  if (finalUrl.origin !== BASE_URL.origin) {
    throw new Error(`login redirected off-origin: ${finalUrl.origin} (atteso ${BASE_URL.origin})`);
  }
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASS);
  await page.click('button[type="submit"]');
  await page.waitForURL(/admin|dashboard/, { timeout: 15000 });
  log('login ok');
}

async function createTestata({ titolo, sottotitolo, tipo, periodicita, issn, luogo, annoInizio, descrizione }) {
  await page.goto(`${BASE}/admin/periodicals/create`);
  await page.fill('#titolo', titolo);
  if (sottotitolo) await page.fill('#sottotitolo', sottotitolo);
  if (issn) await page.fill('#issn', issn);
  await page.selectOption('#tipo', tipo);
  if (periodicita) await page.selectOption('#periodicita', periodicita);
  if (luogo) await page.fill('input[name="luogo_pubblicazione"]', luogo);
  if (annoInizio) await page.fill('input[name="anno_inizio"]', String(annoInizio));
  if (descrizione) await page.fill('textarea[name="descrizione"]', descrizione);
  await page.locator('form button[type="submit"]:has-text("Crea testata")').click();
  await page.waitForURL(/\/admin\/periodicals\/(\d+)\/issues/, { timeout: 15000 });
  const id = page.url().match(/periodicals\/(\d+)\/issues/)[1];
  log(`testata "${titolo}" creata (id ${id})`);
  return id;
}

async function bulkIssues(testataId, anno, da, a) {
  await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
  await page.fill('#blk-anno', String(anno));
  await page.fill('#blk-da', String(da));
  await page.fill('#blk-a', String(a));
  await Promise.all([
    page.waitForEvent('load', { timeout: 15000 }),
    page.locator('form:has(#blk-anno) button[type="submit"]').click(),
  ]);
  log(`  annata ${anno}: fascicoli ${da}–${a} creati in serie`);
}

async function kardexGenerate(testataId, anno) {
  await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
  await page.fill('#krd-anno', String(anno));
  await Promise.all([
    page.waitForEvent('load', { timeout: 15000 }),
    page.locator('form:has(#krd-anno) button[type="submit"]').click(),
  ]);
  log(`  kardex ${anno}: fascicoli attesi generati`);
}

async function receiveFirstExpected(testataId, howMany) {
  for (let i = 0; i < howMany; i++) {
    await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
    const form = page.locator('form:has(input[name="action"][value="receive_issue"])').first();
    if (!(await form.count())) break;
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      form.locator('button[type="submit"]').click(),
    ]);
  }
  log(`  kardex: ${howMany} fascicoli ricevuti`);
}

async function markMissing(testataId) {
  await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
  const form = page.locator('form:has(input[name="action"][value="mark_missing"])').first();
  if (await form.count()) {
    page.once('dialog', (d) => d.accept().catch(() => {}));
    await Promise.all([
      page.waitForEvent('load', { timeout: 15000 }),
      form.locator('button[type="submit"]').click(),
    ]);
    log('  kardex: attesi residui marcati mancanti');
  }
}

/** Issue links on the manage page look like /admin/periodicals/issue/{id}. */
async function issueIds(testataId) {
  await page.goto(`${BASE}/admin/periodicals/${testataId}/issues`);
  const hrefs = await page.locator('a[href*="/admin/periodicals/issue/"]').evaluateAll(
    (as) => as.map((a) => a.getAttribute('href')),
  );
  return [...new Set(hrefs.map((h) => h.match(/issue\/(\d+)/)?.[1]).filter(Boolean))];
}

async function uploadCover(issueId, coverPath) {
  await page.goto(`${BASE}/admin/periodicals/issue/${issueId}`);
  await page.locator('#emt-cover-upload input[type="file"]').setInputFiles(coverPath);
  await Promise.all([
    page.waitForEvent('load', { timeout: 15000 }),
    page.locator('form button[type="submit"]').first().click(),
  ]);
}

async function addArticles(issueId, articles) {
  await page.goto(`${BASE}/admin/periodicals/issue/${issueId}`);
  for (let i = 0; i < articles.length; i++) {
    await page.locator('#emt-art-add').click();
  }
  const t = page.locator('input[name="art_titolo[]"]');
  const au = page.locator('input[name="art_autori[]"]');
  const pd = page.locator('input[name="art_pag_da[]"]');
  for (let i = 0; i < articles.length; i++) {
    await t.nth(i).fill(articles[i].titolo);
    if (articles[i].autori) await au.nth(i).fill(articles[i].autori);
    if (articles[i].pag) await pd.nth(i).fill(String(articles[i].pag));
  }
  await Promise.all([
    page.waitForEvent('load', { timeout: 15000 }),
    page.locator('form button[type="submit"]').first().click(),
  ]);
}

async function setIssueState(issueId, stato) {
  await page.goto(`${BASE}/admin/periodicals/issue/${issueId}`);
  await page.selectOption('select[name="stato"]', stato);
  await Promise.all([
    page.waitForEvent('load', { timeout: 15000 }),
    page.locator('form button[type="submit"]').first().click(),
  ]);
}

const cover = (name) => path.join(COVERS, name);
const hasCover = (name) => fs.existsSync(cover(name));

await login();

// ── 1. Internazionale — rivista settimanale, due annate ricche ──
const intz = await createTestata({
  titolo: 'Internazionale', sottotitolo: 'Il meglio della stampa mondiale',
  tipo: 'rivista', periodicita: 'settimanale', issn: '1122-2832',
  luogo: 'Roma', annoInizio: 1993,
  descrizione: 'Settimanale di attualità che traduce e pubblica articoli dalla stampa di tutto il mondo.',
});
await bulkIssues(intz, 2024, 1, 8);
await bulkIssues(intz, 2025, 1, 6);
{
  const ids = await issueIds(intz);
  for (let i = 0; i < Math.min(6, ids.length); i++) {
    if (hasCover(`INTERNAZIONALE-${i + 1}.png`)) await uploadCover(ids[i], cover(`INTERNAZIONALE-${i + 1}.png`));
  }
  if (ids[2]) await setIssueState(ids[2], 'mancante');
  if (ids[5]) await setIssueState(ids[5], 'danneggiato');
  if (ids[0]) {
    await addArticles(ids[0], [
      { titolo: 'Il futuro delle biblioteche pubbliche', autori: 'Sara Whitman', pag: 12 },
      { titolo: 'Cataloghi aperti, saperi condivisi', autori: 'Jean-Pierre Aubert', pag: 24 },
      { titolo: 'Reportage: le emeroteche dimenticate', autori: 'Lucia Marini', pag: 38 },
    ]);
    log('  spoglio: 3 articoli sul primo fascicolo');
  }
  log(`  Internazionale: ${ids.length} fascicoli, 6 copertine, stati misti`);
}

// ── 2. Linus — magazine mensile con Kardex e lacune vere ──
const linus = await createTestata({
  titolo: 'Linus', sottotitolo: 'Rivista di fumetti e di illustrazione',
  tipo: 'magazine', periodicita: 'mensile', issn: '0024-4198',
  luogo: 'Milano', annoInizio: 1965,
  descrizione: 'Storica rivista italiana di fumetto d’autore, satira e cultura pop.',
});
await kardexGenerate(linus, 2025);
await receiveFirstExpected(linus, 8);
await markMissing(linus);
{
  const ids = await issueIds(linus);
  for (let i = 0; i < Math.min(4, ids.length); i++) {
    if (hasCover(`LINUS-${i + 1}.png`)) await uploadCover(ids[i], cover(`LINUS-${i + 1}.png`));
  }
  log(`  Linus: kardex 2025 → 8 ricevuti, 4 mancanti, 4 copertine`);
}

// ── 3. La Gazzetta del Territorio — giornale quotidiano, un'annata ──
const gazz = await createTestata({
  titolo: 'La Gazzetta del Territorio', sottotitolo: 'Cronache locali dal 1948',
  tipo: 'giornale', periodicita: 'quotidiano',
  luogo: 'Padova', annoInizio: 1948,
  descrizione: 'Quotidiano locale: in emeroteca si conservano le raccolte rilegate per annata.',
});
await bulkIssues(gazz, 2024, 1, 10);
{
  const ids = await issueIds(gazz);
  for (let i = 0; i < Math.min(2, ids.length); i++) {
    if (hasCover(`GAZZETTA-${i + 1}.png`)) await uploadCover(ids[i], cover(`GAZZETTA-${i + 1}.png`));
  }
  if (ids[4]) await setIssueState(ids[4], 'in_restauro');
  log(`  Gazzetta: 10 numeri, 2 copertine, uno in restauro`);
}

await browser.close();
log('DONE — apri /emeroteca per vedere il risultato');
