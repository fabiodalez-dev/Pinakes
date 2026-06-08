// @ts-check
// Issue #165: an auto-imported (e.g. Google Books) cover must be replaceable in
// one step — uploading a new cover in the edit form should REPLACE the existing
// one, not be silently reverted (previously you had to remove-then-save-then-add).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs'); const os = require('os'); const path = require('path');
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '', DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '', DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '', DB_NAME = process.env.E2E_DB_NAME || '';
test.skip(!ADMIN_EMAIL || !DB_USER || !DB_NAME, 'creds not configured');

// Two distinct, valid 1x1 PNG fixtures written to a temp dir so the test is
// self-contained (no reliance on files existing on disk / in CI).
const PNG_RED  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
const PNG_BLUE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const tmpdir = fs.mkdtempSync(path.join(os.tmpdir(), 'pk165-'));
const coverAPath = path.join(tmpdir, 'cover-A.png');
const coverBPath = path.join(tmpdir, 'cover-B.png');
fs.writeFileSync(coverAPath, Buffer.from(PNG_RED, 'base64'));
fs.writeFileSync(coverBPath, Buffer.from(PNG_BLUE, 'base64'));
test.afterAll(() => { try { fs.rmSync(tmpdir, { recursive: true, force: true }); } catch {} });

function dbQuery(sql){
  const args=[]; if(DB_HOST){args.push('-h',DB_HOST); if(DB_PORT)args.push('-P',DB_PORT);} else if(DB_SOCKET){args.push('-S',DB_SOCKET);}
  args.push('-u',DB_USER,DB_NAME,'-N','-B','-e',sql);
  const cnf=path.join(os.tmpdir(),`pk-165-${process.pid}.cnf`); fs.writeFileSync(cnf,`[client]\npassword="${DB_PASS}"\n`,{mode:0o600});
  try{ return execFileSync('mysql',[`--defaults-extra-file=${cnf}`,...args],{encoding:'utf-8',timeout:10000}).trim(); } finally { try{fs.unlinkSync(cnf);}catch{} }
}
async function login(page){
  await page.goto(`${BASE}/accedi`); await page.fill('input[name="email"]',ADMIN_EMAIL); await page.fill('input[name="password"]',ADMIN_PASS);
  await page.locator('button[type="submit"]').click(); await page.waitForURL(u=>!u.toString().includes('/accedi'),{timeout:15000});
}
async function submitBook(page){
  await page.locator('#bookForm button[type="submit"], button[type="submit"]').first().click();
  await Promise.race([ page.waitForSelector('.swal2-popup',{timeout:8000}), page.waitForURL(/\/admin\/books\/\d+/,{timeout:8000}) ]).catch(()=>{});
  const c=page.locator('.swal2-confirm'); if(await c.isVisible({timeout:2000}).catch(()=>false)) await c.click().catch(()=>{});
  await page.waitForLoadState('networkidle').catch(()=>{});
}

test('#165: uploading a new cover in edit replaces the existing one (no remove-first)', async ({ page }) => {
  await login(page);
  const title = `COVER165_${Date.now().toString(36)}`;

  // Create with cover A
  await page.goto(`${BASE}/admin/books/create`);
  await page.fill('#titolo', title);
  await page.setInputFiles('#fallback-file-input', coverAPath);
  await submitBook(page);

  const id = parseInt(dbQuery(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1`),10);
  expect(id).toBeGreaterThan(0);
  const coverA = dbQuery(`SELECT IFNULL(copertina_url,'') FROM libri WHERE id=${id}`);
  expect(coverA.length).toBeGreaterThan(0); // cover A was saved

  // Edit: upload cover B (the new, correct cover) — must REPLACE A in one step
  await page.goto(`${BASE}/admin/books/edit/${id}`);
  await expect(page.locator('#titolo')).toBeVisible({ timeout: 10000 });
  await page.setInputFiles('#fallback-file-input', coverBPath);
  await submitBook(page);

  const coverB = dbQuery(`SELECT IFNULL(copertina_url,'') FROM libri WHERE id=${id}`);
  expect(coverB.length).toBeGreaterThan(0);
  expect(coverB).not.toBe(coverA); // #165: the cover was REPLACED, not reverted to A

  dbQuery(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id=${id}`);
});
