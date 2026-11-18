// @ts-check
// Film cataloguing (#539), through the real admin form, controller, views
// and RIS route:
//
//   1. the "Dettagli film" card is hidden for a book and appears when the
//      media type becomes "Film e video";
//   2. the five film fields are saved and read back (runtime, cast,
//      subtitles, age rating, region);
//   3. the admin record page shows them, with the director label;
//   4. the public page shows them, with JSON-LD Movie (duration, actor,
//      contentRating) and "Cita questo film";
//   5. the RIS download is TY MPCT, named film-{id}.ris;
//   6. the public catalogue lists the film under the "Film e video" facet;
//   7. film discovery shelves reflect genre, audience and circulation;
//   8. citation services receive the public RIS address;
//   9. the individual UNIMARC download preserves film metadata;
//  10. switching the type away keeps the values (the hidden card is still
//      posted), and a cleared field becomes NULL.
//
// Marker-scoped to titles `ZZ_FILM539_%`, cleaned up in afterAll.
const { test, expect } = require('@playwright/test');
const h = require('./helpers/minor-features-090');

test.skip(!h.configured, 'E2E credentials not configured');

const STAMP = Date.now().toString(36);
const TITLE = `ZZ_FILM539_ Oak Street ${STAMP}`;
const DIRECTOR = `Regista Film539 ${STAMP}`;
const STUDIO = `Studio Film539 ${STAMP}`;

test.describe.serial('Film cataloguing (#539)', () => {
  let context;
  let page;
  let bookId = 0;
  let authorId = 0;
  let publisherId = 0;
  let ordinaryBookId = 0;

  const filmRow = () => {
    const [durata, interpreti, sottotitoli, eta, regione, tipo] = h.dbQuery(
      `SELECT IFNULL(durata_minuti,'NULL'), IFNULL(REPLACE(interpreti, '\\n', '|'),'NULL'), IFNULL(sottotitoli,'NULL'),
              IFNULL(classificazione_eta,'NULL'), IFNULL(codice_regione,'NULL'), tipo_media
         FROM libri WHERE id = ${bookId}`).split('\t');
    return { durata, interpreti, sottotitoli, eta, regione, tipo };
  };

  test.beforeAll(async ({ browser }) => {
    h.dbQuery(`INSERT INTO autori (nome) VALUES (${h.sqlString(DIRECTOR)})`);
    authorId = parseInt(h.dbQuery(`SELECT MAX(id) FROM autori WHERE nome = ${h.sqlString(DIRECTOR)}`), 10);
    h.dbQuery(`INSERT INTO editori (nome) VALUES (${h.sqlString(STUDIO)})`);
    publisherId = parseInt(h.dbQuery(`SELECT MAX(id) FROM editori WHERE nome = ${h.sqlString(STUDIO)}`), 10);
    bookId = h.seedBook({
      titolo: TITLE, tipo_media: 'libro', formato: 'Blu-ray', anno_pubblicazione: '2009',
      parole_chiave: 'bambini, drammatico', lingua: 'inglese', ean: `50518${STAMP.replace(/\D/g, '').padEnd(8, '7').slice(0, 8)}`,
      editore_id: String(publisherId), copie_totali: '1', copie_disponibili: '1',
    });
    h.dbQuery(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (${bookId}, ${authorId}, 'principale', 1)`);
    ordinaryBookId = h.seedBook({ titolo: `ZZ_FILM539_ Text ${STAMP}`, tipo_media: 'libro', parole_chiave: 'bambini, drammatico' });
    context = await browser.newContext();
    page = await context.newPage();
    await h.loginAsAdmin(page);
  });

  test.afterAll(async () => {
    if (bookId) h.dbQuery(`DELETE FROM prestiti WHERE libro_id = ${bookId}`);
    h.deleteBooks([bookId, ordinaryBookId]);
    try { h.dbQuery(`DELETE FROM libri_editori WHERE editore_id = ${publisherId}`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM editori WHERE id = ${publisherId}`); } catch (_) { /* cleanup */ }
    try { h.dbQuery(`DELETE FROM autori WHERE id = ${authorId}`); } catch (_) { /* cleanup */ }
    await context?.close();
  });

  test('1. The film card follows the media type select', async () => {
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    const card = page.locator('#film-details');
    await expect(card).toBeHidden();
    await expect(page.locator('#tipo_media option[value="dvd"]')).toHaveText(/Film e video/);
    await page.selectOption('#tipo_media', 'dvd');
    await expect(card).toBeVisible();
    await expect(card.locator('h2')).toContainText('Dettagli film');
    await page.selectOption('#tipo_media', 'libro');
    await expect(card).toBeHidden();
  });

  test('2. The film fields are saved', async () => {
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await page.selectOption('#tipo_media', 'dvd');
    await page.fill('#durata_minuti', '95');
    await page.fill('#interpreti', 'Ana de Armas\nDaniel Craig');
    await page.fill('#sottotitoli', 'danese,   inglese');
    await page.fill('#classificazione_eta', '15');
    await page.fill('#codice_regione', 'B');
    await h.submitBookForm(page);
    expect(filmRow()).toEqual({
      durata: '95', interpreti: 'Ana de Armas|Daniel Craig', sottotitoli: 'danese, inglese', eta: '15', regione: 'B', tipo: 'dvd',
    });
  });

  test('3. The admin record shows the film facts', async () => {
    await page.goto(`${h.BASE}/admin/books/${bookId}`);
    await expect(page.locator('[data-film-field="durata_minuti"]')).toHaveText('95 min');
    await expect(page.locator('[data-film-field="interpreti"]')).toHaveText('Ana de Armas, Daniel Craig');
    await expect(page.locator('[data-film-field="sottotitoli"]')).toHaveText('danese, inglese');
    await expect(page.locator('[data-film-field="classificazione_eta"]')).toHaveText('15');
    await expect(page.locator('[data-film-field="codice_regione"]')).toHaveText('B');
    await expect(page.getByText('Regista', { exact: false }).first()).toBeVisible();
  });

  test('4. The public page shows the film, with JSON-LD Movie', async () => {
    const resp = await page.goto(`${h.BASE}/autore/film/${bookId}`);
    expect(resp?.status()).toBeLessThan(400);
    await expect(page.locator('#book-details-section .section-title')).toContainText('Dettagli film');
    await expect(page.locator('#book-details-section [data-film-field="durata_minuti"]')).toHaveText('95 min');
    await expect(page.locator('#book-details-section [data-film-field="interpreti"]')).toHaveText('Ana de Armas, Daniel Craig');
    await expect(page.locator('#book-cite-card')).toContainText('Cita questo film');
    const movie = await page.evaluate(() => {
      for (const s of Array.from(document.querySelectorAll('script[type="application/ld+json"]'))) {
        try {
          const data = JSON.parse(s.textContent || '');
          const nodes = Array.isArray(data) ? data : [data, ...(data['@graph'] || [])];
          for (const node of nodes) {
            if (node && node['@type'] === 'Movie') return node;
          }
        } catch (_) { /* not JSON-LD we want */ }
      }
      return null;
    });
    expect(movie).not.toBeNull();
    expect(movie.duration).toBe('PT1H35M');
    expect(movie.contentRating).toBe('15');
    expect(movie.actor.map((a) => a.name)).toEqual(['Ana de Armas', 'Daniel Craig']);
    expect(JSON.stringify(movie.director)).toContain(DIRECTOR);
  });

  test('5. The RIS reference is a motion picture', async () => {
    const res = await page.request.get(`${h.BASE}/books/${bookId}/citation.ris`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-disposition']).toBe(`attachment; filename="film-${bookId}.ris"`);
    const ris = await res.text();
    expect(ris.startsWith('TY  - MPCT\r\n')).toBe(true);
    expect(ris).toContain(`PB  - ${STUDIO}\r\n`);
    expect(ris).toContain('PY  - 2009\r\n');
    expect(ris).toContain('M3  - Blu-ray\r\n');
    expect(ris).toContain('N1  - Cast: Ana de Armas, Daniel Craig\r\n');
  });

  test('6. The catalogue lists the film under "Film e video"', async () => {
    await page.goto(`${h.BASE}/catalogo?tipo_media=dvd`);
    await expect(page.locator('#media-types-filter')).toContainText('Film e video');
    await expect(page.getByText(TITLE).first()).toBeVisible();
  });

  test('7. Film shelves link to the catalogue record and use real circulation', async () => {
    h.dbQuery(`INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, data_restituzione, stato, attivo)
               SELECT ${bookId}, id, CURRENT_DATE, CURRENT_DATE, CURRENT_DATE, 'restituito', 0 FROM utenti ORDER BY id LIMIT 1`);
    await page.goto(`${h.BASE}/catalogo?tipo_media=dvd`);
    await page.getByRole('link', { name: 'Esplora i film' }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Film e video');
    for (const key of ['latest', 'children', 'drama']) {
      await expect(page.locator(`section[aria-labelledby="films-${key}"] [data-book-id="${bookId}"]`)).toBeVisible();
    }
    const popular = page.locator('section[aria-labelledby="films-popular"] .book-card');
    await expect(popular.first()).toBeVisible();
    const popularIds = await popular.evaluateAll(cards => cards.map(card => Number(card.getAttribute('data-book-id'))));
    const counts = popularIds.map(id => Number(h.dbQuery(`SELECT COUNT(*) FROM prestiti WHERE libro_id = ${id}
      AND stato IN ('in_corso','in_ritardo','restituito','perso','danneggiato')`)));
    expect(Math.min(...counts)).toBeGreaterThan(0);
    expect(counts).toEqual([...counts].sort((a, b) => b - a));
    await expect(page.locator(`[data-book-id="${ordinaryBookId}"]`)).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Tutti i film' })).toHaveAttribute('href', /tipo_media=dvd$/);
    await page.locator(`section[aria-labelledby="films-latest"] [data-book-id="${bookId}"] .book-title a`).click();
    await expect(page.locator('#book-cite-card')).toBeVisible();
  });

  test('8. The citation dialog offers both direct imports and a public RIS download', async ({ request }) => {
    await page.goto(`${h.BASE}/autore/film/${bookId}`);
    await page.locator('#cite-open').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('link', { name: 'Scarica RIS' })).toBeVisible();
    for (const [name, base, expected] of [
      ['RefWorks', 'https://api.refworks.proquest.com/api-ipa/express', { filter: 'RIS Format', vendor: 'Pinakes', encoding: '65001' }],
      ['EndNote Web', 'https://www.myendnoteweb.com/', { func: 'directExport', partnerName: 'Pinakes', dataIdentifier: '1' }],
    ]) {
      const href = await dialog.getByRole('link', { name }).getAttribute('href');
      const link = new URL(href);
      expect(link.origin + link.pathname).toBe(base);
      for (const [key, value] of Object.entries(expected)) expect(link.searchParams.get(key)).toBe(value);
      const callback = link.searchParams.get(name === 'RefWorks' ? 'url' : 'dataRequestUrl');
      expect(new URL(callback).pathname).toMatch(new RegExp(`/books/${bookId}/citation\\.ris$`));
      const res = await request.get(callback);
      expect(res.status()).toBe(200);
      const ris = await res.text();
      expect(ris).toContain(`ID  - ${bookId}\r\n`);
      expect(ris).toMatch(/\r\nDP  - [^\r\n]+\r\n/);
      expect(ris).toContain('TY  - MPCT\r\n');
    }
  });

  test('9. The admin UNIMARC download preserves film metadata', async () => {
    const res = await page.request.get(`${h.BASE}/admin/books/${bookId}/export.unimarc.xml`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('xml');
    const fields = await page.evaluate((xml) => {
      const doc = new DOMParser().parseFromString(xml, 'application/xml');
      const sub = (tag, code) => doc.querySelector(`datafield[tag="${tag}"] subfield[code="${code}"]`)?.textContent;
      return {
        type: doc.querySelector('leader')?.textContent?.[6],
        extent: sub('215', 'a'), cast: sub('323', 'a'), audience: sub('333', 'a'), directorRole: sub('700', '4'),
      };
    }, await res.text());
    expect(fields).toEqual({
      type: 'g', extent: '1 videodisc (95 min)', cast: 'Ana de Armas, Daniel Craig', audience: '15', directorRole: '300',
    });
  });

  test('10. Switching type keeps the values; a cleared field is NULL', async () => {
    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await page.selectOption('#tipo_media', 'libro');
    await expect(page.locator('#film-details')).toBeHidden();
    await h.submitBookForm(page);
    expect(filmRow()).toMatchObject({ durata: '95', eta: '15', tipo: 'libro' });

    await page.goto(`${h.BASE}/admin/books/edit/${bookId}`);
    await page.selectOption('#tipo_media', 'dvd');
    await page.fill('#classificazione_eta', '');
    await page.fill('#durata_minuti', '');
    await h.submitBookForm(page);
    expect(filmRow()).toMatchObject({ durata: 'NULL', eta: 'NULL', regione: 'B', tipo: 'dvd' });
  });
});
