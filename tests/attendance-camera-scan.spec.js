// @ts-check
// Visitor log from a phone: "Scansiona" opens the self-hosted camera scanner
// (zxing-wasm), the card's Code128 is decoded from the camera picture and the
// entry is registered at once, with no tap on "Registra ingresso". Chromium's
// fake camera plays a picture of a real Code128 card barcode drawn by TCPDF
// (the same library that prints the cards), so the decode is real, not a
// filled field.
const { test, expect, devices } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const featureToggles = require('./helpers/feature-toggles');
const h = require('./helpers/minor-features-090');

test.skip(!h.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const CARD = `E2EC${STAMP}`.slice(0, 20).toUpperCase();

// The camera picture: the card code as Code128 on a white frame, written as
// a Y4M stream before the browser starts (the file is a launch option). Only
// the worker that runs the tests writes it: the runner also loads this file.
const FRAME = path.join(os.tmpdir(), `attendance-card-${STAMP}.y4m`);
if (process.env.TEST_WORKER_INDEX !== undefined) execFileSync('php', ['-r', `
  require ${JSON.stringify(path.resolve(__dirname, '..', 'vendor', 'autoload.php'))};
  $png = (new TCPDFBarcode($argv[1], 'C128'))->getBarcodePngData(3, 140, [0, 0, 0]);
  $code = imagecreatefromstring($png);
  $frame = imagecreatetruecolor(640, 480);
  imagefill($frame, 0, 0, imagecolorallocate($frame, 255, 255, 255));
  imagecopy($frame, $code, (int) ((640 - imagesx($code)) / 2), (int) ((480 - imagesy($code)) / 2), 0, 0, imagesx($code), imagesy($code));
  // Y4M (YUV 4:2:0), the format Chromium's fake camera plays.
  $w = 640; $h = 480; $y = ''; $u = ''; $v = '';
  for ($py = 0; $py < $h; $py++) {
    for ($px = 0; $px < $w; $px++) {
      $luma = imagecolorat($frame, $px, $py) & 0xFF;
      $y .= chr($luma);
    }
  }
  $chroma = str_repeat(chr(128), intdiv($w, 2) * intdiv($h, 2));
  $one = "FRAME\n" . $y . $chroma . $chroma;
  file_put_contents($argv[2], "YUV4MPEG2 W{$w} H{$h} F30:1 Ip A1:1 C420jpeg\n" . str_repeat($one, 10));
`, CARD, FRAME]);

test.use({
  // A phone (viewport, touch, mobile UA) on Chromium: the device preset
  // defaults to WebKit, which has no fake camera.
  ...devices['iPhone 13'],
  defaultBrowserType: 'chromium',
  launchOptions: { args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', `--use-file-for-fake-video-capture=${FRAME}`] },
});

test.describe.serial('Visitor log: card scanned with the camera', () => {
  let featureSnapshot;
  let userId = 0;

  test.beforeAll(async () => {
    featureSnapshot = await featureToggles.enableFeatures(['visitor_log']);
    h.dbQuery(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente) VALUES (${h.sqlString(CARD)}, 'Carla', 'Camerini', ${h.sqlString(`presenze-cam-${STAMP}@example.test`)}, 'x', 'attivo', 'standard')`);
    userId = parseInt(h.dbQuery(`SELECT id FROM utenti WHERE codice_tessera = ${h.sqlString(CARD)}`), 10);
  });

  test.afterAll(async () => {
    try { h.dbQuery(`DELETE FROM presenze WHERE utente_id = ${userId}`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM utenti WHERE id = ${userId}`); } catch (_) { /* cleanup */ }
    await featureToggles.restoreFeatures(featureSnapshot);
    fs.rmSync(FRAME, { force: true });
  });

  test('a card read by the phone camera registers the entry without another tap', async ({ page }) => {
    await h.loginAsAdmin(page);
    await page.goto(`${h.BASE}/admin/attendance`);
    const scan = page.locator('#attendance-card-scan');
    await expect(scan).toHaveAttribute('data-copy-scan-bound', '1');
    await Promise.all([
      page.waitForURL(/\/admin\/attendance$/, { timeout: 30000 }),
      page.waitForResponse((r) => r.url().endsWith('/admin/attendance/scan') && r.request().method() === 'POST', { timeout: 30000 }),
      scan.tap(),
    ]);
    await expect(page.getByText('Benvenuto/a, Carla C.: presenza registrata.')).toBeVisible();
    expect(parseInt(h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id = ${userId} AND tipo = 'socio'`), 10)).toBe(1);
  });

  test('a typed or pasted code is not sent on its own, also after a cancelled scan', async ({ page }) => {
    await h.loginAsAdmin(page);
    await page.goto(`${h.BASE}/admin/attendance`);
    const before = parseInt(h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id = ${userId}`), 10);
    let posted = false;
    page.on('request', (r) => { if (r.url().endsWith('/admin/attendance/scan') && r.method() === 'POST') posted = true; });
    // A scan opened and cancelled first (camera refused here, so it cannot
    // decode before the cancel): the next code must still wait.
    await page.evaluate(() => { navigator.mediaDevices.getUserMedia = async () => { const e = new Error('denied'); e.name = 'NotAllowedError'; throw e; }; });
    await page.locator('#attendance-card-scan').tap();
    await page.locator('[role="dialog"][aria-label="Scansiona codice a barre"] button', { hasText: 'Annulla' }).tap();
    await expect(page.locator('[role="dialog"][aria-label="Scansiona codice a barre"]')).toHaveCount(0);
    // fill() inserts the text without key presses, like paste or autofill.
    await page.locator('#codice_tessera').fill(CARD);
    // Leaving the field fires "change" like a scan does: it must not submit.
    await page.locator('#motivo').focus();
    await page.waitForTimeout(800);
    expect(posted).toBe(false);
    expect(parseInt(h.dbQuery(`SELECT COUNT(*) FROM presenze WHERE utente_id = ${userId}`), 10)).toBe(before);
  });
});
