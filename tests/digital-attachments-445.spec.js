// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_NAME, 'E2E credentials required');
function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}
async function login(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !/accedi|login/.test(u.pathname));
  }
}
async function addRow(page, url, label, kind = 'ebook') {
  await page.locator('#add-digital-attachment').click();
  const row = page.locator('[data-attachment-row]').last();
  await row.locator('input[name$="[url]"]').fill(url);
  await row.locator('input[name$="[label]"]').fill(label);
  await row.locator('select[name$="[kind]"]').selectOption(kind);
}
async function submit(page) {
  await page.locator('#bookForm button[type=submit]').click();
  const confirmation = page.locator('.swal2-confirm');
  if (await confirmation.isVisible({ timeout: 3000 }).catch(() => false)) await confirmation.click();
  await page.waitForURL(u => !/\/admin\/books\/(edit|create)/.test(u.pathname));
}

test.describe.serial('Multiple digital contents (#445)', () => {
  let bookId;
  const created = [];
  const uploads = new Set();
  const marker = `Digital445-${Date.now()}`;
  let pluginWasActive;
  test.beforeAll(() => {
    pluginWasActive = db("SELECT is_active FROM plugins WHERE name='digital-library'");
    db("UPDATE plugins SET is_active=1 WHERE name='digital-library'");
    db(`INSERT INTO libri (titolo,file_url,audio_url) VALUES ('${marker}','/uploads/digital/legacy-445.pdf','/uploads/digital/legacy-445.wav')`);
    bookId = Number(db(`SELECT id FROM libri WHERE titolo='${marker}'`));
    created.push(bookId);
  });
  test.afterAll(() => {
    for (const id of created) { db(`DELETE FROM copie WHERE libro_id=${id}`); db(`DELETE FROM libri WHERE id=${id}`); }
    if (pluginWasActive !== '') db(`UPDATE plugins SET is_active=${Number(pluginWasActive)} WHERE name='digital-library'`);
    for (const url of uploads) {
      if (/^\/uploads\/digital\/\d+_[a-f0-9]+\.(pdf|wav)$/.test(url)) {
        // Uploads live in storage/digital since #480; public/uploads/digital is the pre-#480 place.
        const name = path.basename(url);
        fs.rmSync(path.join(__dirname, '../storage/digital', name), { force: true });
        fs.rmSync(path.join(__dirname, '../public/uploads/digital', name), { force: true });
      }
    }
  });
  test('upgrading a legacy record shows both original attachments and one editor', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('[data-attachment-row]')).toHaveCount(2);
    await expect(page.locator('#file_url')).toHaveCount(1);
    await expect(page.locator('#audio_url')).toHaveCount(1);
    expect(db("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='libri' AND column_name='digital_attachments'")).toBe('1');
    await expect(page.locator('[data-attachment-row]').first().locator('input[name$="[url]"]')).toHaveValue('/uploads/digital/legacy-445.pdf');
  });
  test('uploading two PDFs appends both instead of replacing the first', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    page.on('response', async response => {
      if (response.url().endsWith('/digital-library/upload') && response.status() === 200) {
        const result = await response.json(); if (result.uploadURL) uploads.add(result.uploadURL);
      }
    });
    await page.locator('#upload-ebook-btn').click();
    const buffer = fs.readFileSync(path.join(__dirname, 'fixtures/archive-test.pdf'));
    await page.locator('#ebook-uploader input[type=file]').setInputFiles([
      { name: 'edition-445.pdf', mimeType: 'application/pdf', buffer },
      { name: 'review-445.pdf', mimeType: 'application/pdf', buffer },
    ]);
    await expect(page.locator('[data-attachment-row]')).toHaveCount(4, { timeout: 15000 });
    const confirm = page.locator('.swal2-confirm'); if (await confirm.isVisible()) await confirm.click();
    await page.locator('[data-attachment-row]').last().locator('select[name$="[kind]"]').selectOption('supplement');
    await page.locator('[data-attachment-row]').last().locator('input[name$="[label]"]').fill('A review <&>');
    await submit(page);
    await expect.poll(() => db(`SELECT JSON_LENGTH(digital_attachments) FROM libri WHERE id=${bookId}`)).toBe('4');
  });
  test('two audio uploads and an ePub survive saving and reloading the form', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    const csrf = await page.locator('#bookForm input[name=csrf_token]').inputValue();
    const buffer = fs.readFileSync(path.join(__dirname, 'fixtures/archive-test-audio.wav'));
    for (const label of ['Audio edition one', 'Audio edition two']) {
      const response = await page.request.post(`${BASE}/admin/plugins/digital-library/upload`, {
        headers: { 'X-CSRF-Token': csrf },
        multipart: { digital_type: 'audio', file: { name: 'edition-445.wav', mimeType: 'audio/wav', buffer } },
      });
      expect(response.status()).toBe(200);
      const body = await response.json(); uploads.add(body.uploadURL);
      await addRow(page, body.uploadURL, label, 'audio');
    }
    await addRow(page, 'https://example.org/edition-445.epub', 'ePub edition');
    await submit(page);
    await expect.poll(() => db(`SELECT JSON_LENGTH(digital_attachments) FROM libri WHERE id=${bookId}`)).toBe('7');
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('[data-attachment-row]')).toHaveCount(7);
    await expect(page.locator('input[name$="[label]"]').last()).toHaveValue('ePub edition');
  });
  test('the public record exposes every labelled document and audio player', async ({ page }) => {
    await page.goto(`${BASE}/libro/${bookId}`);
    await expect(page.locator('.digital-attachments-list li')).toHaveCount(7);
    await expect(page.locator('.digital-attachments-list audio')).toHaveCount(3);
    await expect(page.locator('.digital-attachments-list a[href$=".epub"]')).toBeVisible();
    await expect(page.locator('.digital-attachments-list')).toContainText('A review <&>');
    await expect(page.locator('#audiobook-player-container')).toHaveCount(0);
    // The first PDF is a legacy URL without a file; use the actual uploaded PDF.
    const read = page.locator('[data-digital-pdf-toggle]').nth(1);
    await page.emulateMedia({ colorScheme: 'dark' });
    await read.click();
    const reader = page.locator('dialog.digital-pdf-lightbox[open]');
    await expect(reader).toBeVisible();
    const bounds = await reader.boundingBox();
    expect(bounds.x).toBe(0);
    expect(bounds.y).toBe(0);
    expect(bounds.width).toBe(page.viewportSize().width);
    expect(bounds.height).toBe(page.viewportSize().height);
    const iframe = reader.locator('iframe');
    const readerUrl = new URL(await iframe.getAttribute('src'), BASE);
    expect(readerUrl.origin).toBe(new URL(BASE).origin);
    expect(readerUrl.pathname).toContain('/assets/vendor/pdfjs-6.4.299/web/viewer.html');
    const pdfUrl = new URL(readerUrl.searchParams.get('file'));
    expect(pdfUrl.origin).toBe(readerUrl.origin);
    const pdfResponse = await page.request.get(pdfUrl.href);
    expect(pdfResponse.ok()).toBeTruthy();
    expect(pdfResponse.headers()['content-type']).toContain('application/pdf');
    const pdfReader = page.frameLocator('dialog.digital-pdf-lightbox[open] iframe');
    await expect(pdfReader.locator('.page[data-loaded="true"] canvas').first()).toBeVisible();
    await expect(pdfReader.locator('body')).toHaveCSS('color-scheme', 'light');
    await expect(pdfReader.locator('body')).toHaveCSS('background-color', 'rgb(244, 241, 243)');
    const frameBounds = await iframe.boundingBox();
    expect(frameBounds.x).toBeGreaterThanOrEqual(12);
    expect(frameBounds.x + frameBounds.width).toBeLessThanOrEqual(page.viewportSize().width - 12);
    await reader.getByRole('button', { name: 'Chiudi', exact: true }).press('Escape');
    await expect(reader).toHaveCount(0);
    await expect(read).toBeFocused();
    await expect(page.locator('dialog.digital-pdf-lightbox iframe[src]')).toHaveCount(0);
  });
  test('invalid attachment URLs reject the complete save without changing the book', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await page.locator('[data-attachment-row]').first().locator('input[name$="[url]"]').fill('javascript:alert(1)');
    const result = await page.locator('#bookForm').evaluate(async form => {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
      return { status: response.status, body: await response.json() };
    });
    expect(result.status).toBe(400);
    expect(result.body.error).toBe('validation');
    await page.locator('#bookForm button[type=submit]').click();
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-html-container')).toHaveText(result.body.message);
    await page.locator('.swal2-confirm').click();
    expect(db(`SELECT JSON_LENGTH(digital_attachments) FROM libri WHERE id=${bookId}`)).toBe('7');
    expect(db(`SELECT file_url FROM libri WHERE id=${bookId}`)).toBe('/uploads/digital/legacy-445.pdf');
  });
  test('removing all attachments stays empty after reloading and on the public record', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    while (await page.locator('[data-remove-attachment]').count()) await page.locator('[data-remove-attachment]').first().click();
    await submit(page);
    await expect.poll(() => db(`SELECT JSON_LENGTH(digital_attachments) FROM libri WHERE id=${bookId}`)).toBe('0');
    expect(db(`SELECT CONCAT(COALESCE(file_url,''),'|',COALESCE(audio_url,'')) FROM libri WHERE id=${bookId}`)).toBe('|');
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(page.locator('[data-attachment-row]')).toHaveCount(0);
    await page.goto(`${BASE}/libro/${bookId}`);
    await expect(page.locator('.digital-attachments-list, #pdf-viewer-container, #audiobook-player-container')).toHaveCount(0);
  });
  test('a new book saves multiple editions in its first transaction', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE}/admin/books/create`);
    await page.locator('#titolo').fill(`${marker}-new`);
    await addRow(page, '/uploads/digital/new-445.pdf', 'PDF edition');
    await addRow(page, '/uploads/digital/new-445.epub', 'ePub edition');
    await submit(page);
    await expect.poll(() => db(`SELECT COALESCE(JSON_LENGTH(digital_attachments),0) FROM libri WHERE titolo='${marker}-new'`)).toBe('2');
    const newId = Number(db(`SELECT id FROM libri WHERE titolo='${marker}-new'`));
    created.push(newId);
    // A legacy client/import may save the record without knowing the collection.
    await page.goto(`${BASE}/admin/books/edit/${newId}`);
    const legacyStatus = await page.locator('#bookForm').evaluate(async form => {
      const data = new FormData(form);
      for (const key of [...data.keys()]) {
        if (key === 'digital_attachments_present' || key.startsWith('digital_attachments[')) data.delete(key);
      }
      return (await fetch(form.action, { method: 'POST', body: data })).status;
    });
    expect(legacyStatus).toBe(200);
    expect(db(`SELECT JSON_LENGTH(digital_attachments) FROM libri WHERE id=${newId}`)).toBe('2');
  });
  test('a legacy link written as free text survives saving an unrelated field', async ({ page }) => {
    // Older versions stored file_url/audio_url unvalidated: a relative upload path and a
    // raw space must reach the editor and be kept, not blanked by the next save.
    db(`INSERT INTO libri (titolo,file_url,audio_url) VALUES ('${marker}-legacy','uploads/digital/legacy 445.pdf','javascript:alert(1)')`);
    const legacyId = Number(db(`SELECT id FROM libri WHERE titolo='${marker}-legacy'`));
    created.push(legacyId);
    await login(page);
    await page.goto(`${BASE}/admin/books/edit/${legacyId}`);
    const rows = page.locator('[data-attachment-row]');
    await expect(rows).toHaveCount(2);
    await expect(rows.first().locator('input[name$="[url]"]')).toHaveValue('/uploads/digital/legacy%20445.pdf');
    // The unreadable one is shown with an explanation, and blocks the save instead of vanishing.
    await expect(rows.nth(1).locator('.digital-attachment-invalid')).toBeVisible();
    const blocked = await page.locator('#bookForm').evaluate(async form => (await fetch(form.action, { method: 'POST', body: new FormData(form) })).status);
    expect(blocked).toBe(400);
    expect(db(`SELECT audio_url FROM libri WHERE id=${legacyId}`)).toBe('javascript:alert(1)');
    await rows.nth(1).locator('[data-remove-attachment]').click();
    await page.locator('#titolo').fill(`${marker}-legacy edited`);
    await submit(page);
    await expect.poll(() => db(`SELECT titolo FROM libri WHERE id=${legacyId}`)).toBe(`${marker}-legacy edited`);
    expect(db(`SELECT file_url FROM libri WHERE id=${legacyId}`)).toBe('/uploads/digital/legacy%20445.pdf');
    expect(db(`SELECT COALESCE(audio_url,'') FROM libri WHERE id=${legacyId}`)).toBe('');
  });
});
