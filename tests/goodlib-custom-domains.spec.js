// @ts-check
const { test, expect } = require('@playwright/test');
const {
  BASE_URL,
  createTempAdminUser,
  createTempBook,
  deleteTempAdminUser,
  deleteTempBook,
  getPluginIdByName,
  restorePluginSettings,
  snapshotPluginSettings,
} = require('./helpers/e2e-fixtures');

test.describe.serial('GoodLib custom domains', () => {
  /** @type {{id: number, email: string, password: string, locale: string} | null} */
  let adminUser = null;
  /** @type {{id: number, title: string} | null} */
  let book = null;
  /** @type {Array<{setting_key: string, setting_value: string, autoload: number}>} */
  let originalSettings = [];
  let pluginId = 0;

  test.beforeAll(() => {
    pluginId = getPluginIdByName('goodlib');
    originalSettings = snapshotPluginSettings(pluginId);
    adminUser = createTempAdminUser('it_IT');
    book = createTempBook();
  });

  test.afterAll(() => {
    restorePluginSettings(pluginId, originalSettings);
    if (book) {
      deleteTempBook(book.id);
    }
    if (adminUser) {
      deleteTempAdminUser(adminUser.id);
    }
  });

  test('accepts suggested mirrors and custom hosts in the same field', async ({ page }) => {
    const annaCustom = 'https://annas-archive.custom.test/path?q=ignored';
    const zlibCustom = 'z-lib.custom.test:8443';

    await page.goto(`${BASE_URL}/accedi`);
    await page.getByRole('textbox', { name: 'Email' }).fill(adminUser.email);
    await page.getByRole('textbox', { name: 'Password' }).fill(adminUser.password);
    await page.getByRole('button', { name: 'Accedi' }).click();
    await page.waitForURL('**/admin/dashboard');

    await page.goto(`${BASE_URL}/admin/plugins`);
    await page.getByRole('button', { name: 'Configura Fonti' }).click();

    await expect(page.locator('#goodlib_anna_domain_select option')).toHaveCount(4);
    await expect(page.locator('#goodlib_zlib_domain_select option')).toHaveCount(7);

    await page.locator('#goodlib_anna_domain_select').selectOption('__custom__');
    await page.locator('#goodlib_anna_domain_custom').fill(annaCustom);
    await page.locator('#goodlib_zlib_domain_select').selectOption('__custom__');
    await page.locator('#goodlib_zlib_domain_custom').fill(zlibCustom);
    await page.getByRole('button', { name: 'Salva' }).click();

    await expect(page.getByRole('dialog')).toContainText('Impostazioni GoodLib salvate correttamente.');
    await page.getByRole('button', { name: 'OK' }).click();

    await page.goto(`${BASE_URL}/libro/${book.id}`);

    await expect(page.getByRole('link', { name: "Anna's Archive" })).toHaveAttribute(
      'href',
      /https:\/\/annas-archive\.custom\.test\/search\?q=/
    );
    await expect(page.getByRole('link', { name: 'Z-Library' })).toHaveAttribute(
      'href',
      /https:\/\/z-lib\.custom\.test:8443\/s\//
    );
    await expect(page.getByRole('link', { name: 'Project Gutenberg' })).toBeVisible();
  });
});
