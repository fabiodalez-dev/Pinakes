// @ts-check
/**
 * The public style after a fresh install and after an upgrade (2026 design):
 * the hero with the fan of covers and the "classic" book cards, chosen as
 * the defaults in the admin themes page. A theme that never saved the two
 * choices (every theme a fresh install seeds, every theme an upgrade carries
 * over) must read as covers + classic. Run by scripts/reinstall-test.sh on
 * both Test A and Test B, and on its own against a running install.
 */
const { test, expect } = require('@playwright/test');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';

test.describe.serial('Public style defaults: cover hero and classic cards', () => {
  test.beforeAll(() => {
    if (!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASS) throw new Error('E2E_ADMIN_EMAIL / E2E_ADMIN_PASS required');
  });

  test('1 The admin themes page offers the two styles with covers and classic selected', async ({ page }) => {
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', process.env.E2E_ADMIN_EMAIL || '');
    await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS || '');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !u.pathname.includes('accedi') && !u.pathname.includes('login'));
    await page.goto(`${BASE}/admin/themes`);
    await expect(page.locator('input[name="hero_style"][value="covers"]')).toBeChecked();
    await expect(page.locator('input[name="hero_style"][value="centered"]')).not.toBeChecked();
    await expect(page.locator('input[name="card_style"][value="classic"]')).toBeChecked();
    await expect(page.locator('input[name="card_style"][value="tinted"]')).not.toBeChecked();
    // The former layout variants are gone.
    await expect(page.locator('input[name="layout_variant"]')).toHaveCount(0);
  });

  test('2 The home shows the cover hero and classic cards', async ({ page }) => {
    await page.goto(`${BASE}/`);
    const body = page.locator('body');
    await expect(body).toHaveClass(/\bpk\b/);
    await expect(body).not.toHaveClass(/pk-hero-centered/);
    await expect(body).not.toHaveClass(/pk-cards-tinted/);
    await expect(page.locator('.pk-hero')).toHaveCount(1);
    const heroBackground = await page.locator('.pk-hero').evaluate(el => getComputedStyle(el).backgroundImage);
    expect(heroBackground, 'no background photo, only the accent wash').not.toContain('url(');
    // The fan is there whenever a catalogued book has a cover.
    const fanBooks = await page.locator('.pk-fan .pk-fan__book').count();
    if (fanBooks > 0) {
      await expect(page.locator('.pk-fan')).toBeVisible();
    }
    // Classic cards: the book alone, no tinted panel.
    const panels = page.locator('.pk-card__panel');
    if (await panels.count() > 0) {
      const style = await panels.first().evaluate(el => {
        const cs = getComputedStyle(el);
        return { padding: cs.paddingTop, background: cs.backgroundColor };
      });
      expect(style.padding).toBe('0px');
      expect(style.background).toBe('rgba(0, 0, 0, 0)');
    }
  });
});
