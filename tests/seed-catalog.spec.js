// @ts-check
/**
 * Seed the catalog with books and music records.
 * This is a SEEDER — it does NOT clean up. Records persist for manual testing.
 * SKIPPED by default so regression runs don't mutate DB state.
 * To run explicitly: E2E_RUN_SEED=1 /tmp/run-e2e.sh tests/seed-catalog.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');

test.skip(process.env.E2E_RUN_SEED !== '1', 'Seeder skipped: set E2E_RUN_SEED=1 to run');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

// 10 music records via Discogs barcode scraping
const MUSIC_BARCODES = [
  { barcode: '0720642442524', note: 'Nirvana - Nevermind' },
  { barcode: '5099902894225', note: 'Pink Floyd - Meddle' },
  { barcode: '0094638246824', note: 'Beatles - Abbey Road' },
  { barcode: '0888837168625', note: 'Daft Punk - RAM' },
  { barcode: '5099751076322', note: 'AC/DC' },
  { barcode: '0602547428714', note: 'Adele - 25' },
  { barcode: '0602537615810', note: 'Arctic Monkeys - AM' },
  { barcode: '0886971592924', note: 'Muse - The Resistance' },
  { barcode: '0602527947747', note: 'Coldplay - Mylo Xyloto' },
  { barcode: '0602557048032', note: 'Metallica - Hardwired' },
];

// 5 books via ISBN scraping
const BOOK_ISBNS = [
  { isbn: '9780061120084', note: 'To Kill a Mockingbird' },
  { isbn: '9780451524935', note: '1984' },
  { isbn: '9780141439518', note: 'Pride and Prejudice' },
  { isbn: '9780060935467', note: 'Don Quixote' },
  { isbn: '9780142437230', note: 'Moby Dick' },
];

// 1 manual entry (punk split without barcode)
const MANUAL_ENTRIES = [
  { titolo: 'Zeromila / Orsetti HC — Split', formato: 'vinile', tipo_media: 'disco' },
];

test.describe.serial('Seed Catalog (books + music)', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  /** @type {import('@playwright/test').BrowserContext} */
  let context;

  test.beforeAll(async ({ browser }) => {
    test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing env vars');
    context = await browser.newContext();
    page = await context.newPage();

    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/admin\//, { timeout: 15000 });
  });

  test.afterAll(async () => {
    // DO NOT clean up — this is a seeder
    await context?.close();
  });

  // Seed music records via barcode scraping
  for (let i = 0; i < MUSIC_BARCODES.length; i++) {
    const rec = MUSIC_BARCODES[i];
    test(`Music ${i + 1}: ${rec.note}`, async () => {
      test.setTimeout(30000);
      await page.goto(`${BASE}/admin/libri/crea`);
      await page.waitForLoadState('domcontentloaded');

      const importBtn = page.locator('#btnImportIsbn');
      if (await importBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await page.fill('#importIsbn', rec.barcode);
        await importBtn.click();
        await page.waitForTimeout(8000);

        const title = await page.locator('input[name="titolo"]').inputValue();
        if (!title) {
          await page.locator('input[name="titolo"]').fill(`CD (${rec.barcode})`);
          await page.locator('input[name="ean"]').fill(rec.barcode);
          await page.locator('input[name="formato"]').fill('cd_audio');
        }
      } else {
        await page.locator('input[name="titolo"]').fill(`CD (${rec.barcode})`);
        await page.locator('input[name="ean"]').fill(rec.barcode);
        await page.locator('input[name="formato"]').fill('cd_audio');
      }

      const copie = await page.locator('input[name="copie_totali"]').inputValue();
      if (!copie || copie === '0') await page.locator('input[name="copie_totali"]').fill('1');

      await page.locator('button[type="submit"]').first().click();
      const swal = page.locator('.swal2-confirm');
      if (await swal.isVisible({ timeout: 3000 }).catch(() => false)) await swal.click();
      await page.waitForURL(/\/admin\/libri\/\d+/, { timeout: 15000 }).catch(() => {});
      console.log(`  ✓ ${rec.note}`);
    });
  }

  // Seed books via ISBN
  for (let i = 0; i < BOOK_ISBNS.length; i++) {
    const book = BOOK_ISBNS[i];
    test(`Book ${i + 1}: ${book.note}`, async () => {
      test.setTimeout(30000);
      await page.goto(`${BASE}/admin/libri/crea`);
      await page.waitForLoadState('domcontentloaded');

      const importBtn = page.locator('#btnImportIsbn');
      if (await importBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await page.fill('#importIsbn', book.isbn);
        await importBtn.click();
        await page.waitForTimeout(8000);

        const title = await page.locator('input[name="titolo"]').inputValue();
        if (!title) {
          await page.locator('input[name="titolo"]').fill(book.note);
          await page.locator('input[name="isbn13"]').fill(book.isbn);
        }
      } else {
        await page.locator('input[name="titolo"]').fill(book.note);
        await page.locator('input[name="isbn13"]').fill(book.isbn);
      }

      const copie = await page.locator('input[name="copie_totali"]').inputValue();
      if (!copie || copie === '0') await page.locator('input[name="copie_totali"]').fill('1');

      await page.locator('button[type="submit"]').first().click();
      const swal = page.locator('.swal2-confirm');
      if (await swal.isVisible({ timeout: 3000 }).catch(() => false)) await swal.click();
      await page.waitForURL(/\/admin\/libri\/\d+/, { timeout: 15000 }).catch(() => {});
      console.log(`  ✓ ${book.note}`);
    });
  }

  // Manual entries
  for (let i = 0; i < MANUAL_ENTRIES.length; i++) {
    const entry = MANUAL_ENTRIES[i];
    test(`Manual ${i + 1}: ${entry.titolo}`, async () => {
      test.setTimeout(15000);
      await page.goto(`${BASE}/admin/libri/crea`);
      await page.waitForLoadState('domcontentloaded');

      await page.locator('input[name="titolo"]').fill(entry.titolo);
      await page.locator('input[name="formato"]').fill(entry.formato);
      if (entry.tipo_media) {
        const sel = page.locator('#tipo_media');
        if (await sel.isVisible({ timeout: 2000 }).catch(() => false)) {
          await sel.selectOption(entry.tipo_media);
        }
      }
      await page.locator('input[name="copie_totali"]').fill('1');

      await page.locator('button[type="submit"]').first().click();
      const swal = page.locator('.swal2-confirm');
      if (await swal.isVisible({ timeout: 3000 }).catch(() => false)) await swal.click();
      await page.waitForURL(/\/admin\/libri\/\d+/, { timeout: 15000 }).catch(() => {});
      console.log(`  ✓ ${entry.titolo}`);
    });
  }
});
