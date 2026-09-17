// End-to-end desiderata and home carousel alignment. Disposable DB fixtures only.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { ensurePluginActive } = require('./helpers/plugin-activation');
const root = path.resolve(__dirname, '..');
const envFile=path.join(__dirname, '.env.test');
const settings = Object.fromEntries((fs.existsSync(envFile) ? fs.readFileSync(envFile, 'utf8') : '').split(/\r?\n/).filter(line => line.includes('=') && !line.startsWith('#')).map(line => { const i=line.indexOf('='); return [line.slice(0,i),line.slice(i+1).trim().replace(/^["']|["']$/g,'')]; }));
const tag=crypto.randomBytes(6).toString('hex');
const fixture = action => JSON.parse(execFileSync('php',[path.join(__dirname,'helpers/desiderata-fixture.php'),action,tag],{cwd:root,encoding:'utf8'}));
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || settings.E2E_ADMIN_EMAIL;
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || settings.E2E_ADMIN_PASS;
let seeded;
test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Requires an installed app and E2E_ADMIN_EMAIL/E2E_ADMIN_PASS');
test.describe.configure({mode:'serial'});
// The plugin is optional and ships inactive: nothing else in this shard turns
// it on, and its routes 404 until it is. Activating here — through the real
// admin endpoint, before the fixture seeds anything — is what lets the spec run
// standalone instead of inheriting another suite's state.
test.beforeAll(async ({browser}) => {
  if (ADMIN_EMAIL && ADMIN_PASS) {
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      await login(page);
      await ensurePluginActive(page, 'desiderata', {smokePath: '/desiderata'});
    } finally { await context.close(); }
  }
  seeded=fixture('seed');
});
test.afterAll(() => { fixture('cleanup'); });
async function login(page) {
  await page.goto('/accedi');
  await page.getByRole('textbox',{name:'Email',exact:true}).fill(ADMIN_EMAIL);
  await page.getByRole('textbox',{name:'Password',exact:true}).fill(ADMIN_PASS);
  await page.getByRole('button',{name:'Accedi',exact:true}).click();
  await page.waitForURL(/\/admin/);
}
async function donor(page) {
  await page.getByLabel('Il tuo nome *',{exact:true}).fill('Donatore test');
  await page.getByLabel('Email per essere contattato *',{exact:true}).fill(seeded.email);
  await page.getByRole('checkbox',{name:/Acconsento/}).check();
}

test('genre headings align with the books on desktop, tablet and mobile',async({page})=>{
  await page.goto('/');
  if(await page.locator('.genre-carousel-section').count()===0) {
    await page.locator('main').evaluate((main,html)=>main.insertAdjacentHTML('beforeend',html),fixture('carousel').html);
  }
  await expect(page.locator('.genre-carousel-section').first()).toBeVisible();
  await expect(page.locator('#genre-carousels > .genre-carousel-section')).toHaveCount(await page.locator('.genre-carousel-section').count());
  for(const width of [1440,1024,768,390]) {
    await page.setViewportSize({width,height:1000});
    const geometry=await page.locator('.genre-carousel-section').evaluateAll(sections=>sections.map(s=>{
      const title=s.querySelector('.genre-carousel-title').getBoundingClientRect();
      const book=s.querySelector('.carousel-book-card').getBoundingClientRect();
      return {delta:Math.abs(title.left-book.left),overflow:document.documentElement.scrollWidth>innerWidth};
    }));
    for(const row of geometry) { expect(row.delta,`alignment at ${width}px`).toBeLessThanOrEqual(1); expect(row.overflow).toBe(false); }
  }
});

test('explicit checkbox disables copies, restores prior input, and keeps scraping available',async({page})=>{
  await login(page); await page.goto('/admin/books/create');
  const flag=page.getByRole('checkbox',{name:'Desiderata: cerchiamo questo libro',exact:true});
  const copies=page.locator('#copie_totali');
  await copies.fill('3'); await flag.check();
  await expect(copies).toBeDisabled(); await expect(copies).toHaveValue('0');
  await expect(page.locator('#isbn13')).toBeEnabled();
  await flag.uncheck(); await expect(copies).toBeEnabled(); await expect(copies).toHaveValue('3');
});

test('anonymous home offer, actual receipt and duplicate receipt protection',async({page,browser})=>{
  await page.goto('/');
  const search=page.getByRole('searchbox',{name:'Cerca tra i desiderata',exact:true});
  await search.fill('DW');
  await expect(page.locator('#desiderata-search-status')).toContainText('almeno 3 caratteri');
  await search.fill(seeded.prefix);
  await expect(page.locator('#desiderata-results li')).toHaveCount(1);
  await page.getByRole('button',{name:'Ce l’ho, posso donarlo',exact:true}).click();
  await expect(page.locator('#donation-book-id')).toHaveValue(String(seeded.wanted));
  await donor(page); await page.getByRole('button',{name:'Invia la proposta',exact:true}).click();
  // Instrumented on purpose. This assertion failed once in the deep-regression
  // shard (position 115/452) while passing locally, in isolation and in
  // sequence with the extended spec — and while its twin at the end of this
  // file, which asserts the same banner after submitting from /desiderata
  // instead of from the homepage, passed in the same CI run. That pair rules
  // out the banner mechanism itself and points at something about this path or
  // that environment. Rather than guess again, make the next failure explain
  // itself: the URL actually reached, every role=status text on the page, and
  // whether the alert element exists at all under a different string.
  try {
    await expect(page.getByRole('status').filter({hasText:'Grazie!'})).toBeVisible();
  } catch (error) {
    const diagnosis = await page.evaluate(() => ({
      url: location.href,
      statuses: [...document.querySelectorAll('[role="status"]')].map(n => n.textContent.trim()),
      alerts: [...document.querySelectorAll('.alert')].map(n => n.className + ' :: ' + n.textContent.trim()),
      formPresent: !!document.querySelector('[data-desiderata-form]'),
    }));
    throw new Error(`${error.message}\n--- page state when the banner was expected ---\n${JSON.stringify(diagnosis, null, 2)}`);
  }
  let state=fixture('state'); expect(state.books.find(b=>b.id===seeded.wanted).physical).toBe(0);
  const offer=state.offers.find(o=>o.book_id===seeded.wanted); expect(offer.status).toBe('pending');
  const context=await browser.newContext(); const admin=await context.newPage();
  try {
    // The receipt button now asks for confirmation (it creates a real
    // inventory copy). Playwright dismisses dialogs by default, which would
    // cancel the action; accept it the way an operator does.
    admin.on('dialog', dialog => dialog.accept());
    await login(admin); await admin.goto('/admin/desiderata');
    let row=admin.locator('article').filter({hasText:seeded.prefix+' desiderata'});
    await row.getByRole('button',{name:'Accetta proposta',exact:true}).click();
    await expect(admin.locator('article').filter({hasText:seeded.prefix+' desiderata'})).toContainText('In attesa della consegna');
    expect(fixture('state').books.find(b=>b.id===seeded.wanted).physical).toBe(0);
    row=admin.locator('article').filter({hasText:seeded.prefix+' desiderata'});
    await row.getByRole('button',{name:'Libro arrivato: registra una copia',exact:true}).click();
    await expect(admin.locator('article').filter({hasText:seeded.prefix+' desiderata'})).toContainText('Ricevuta');
    state=fixture('state'); const acquired=state.books.find(b=>b.id===seeded.wanted);
    expect(acquired.physical).toBe(1); expect(acquired.is_desiderata).toBe(0);
    const token=(await (await admin.request.get('/csrf-token')).json()).token;
    const replay=await admin.request.post('/admin/desiderata/offers/'+offer.id,{form:{action:'received',csrf_token:token}});
    expect(replay.status()).toBe(422); expect(fixture('state').books.find(b=>b.id===seeded.wanted).physical).toBe(1);
    await admin.goto('/admin/books/edit/'+seeded.wanted);
    await expect(admin.locator('#is_desiderata')).not.toBeChecked(); await expect(admin.locator('#is_desiderata')).toBeDisabled();
    expect(await (await page.request.get('/desiderata/search?q='+encodeURIComponent(seeded.prefix))).json()).toEqual([]);
    expect((await page.request.get('/book/'+seeded.wanted)).status()).toBe(200);
  } finally { await context.close(); }
});

test('free donation remains a proposal and can be matched to an existing zero-copy book',async({page})=>{
  await page.setViewportSize({width:390,height:844}); await page.goto('/desiderata');
  await donor(page); await page.getByLabel('Titolo del libro *',{exact:true}).fill('Offerta libera '+tag);
  await page.getByLabel('Condizioni del libro e note (facoltativo)').fill('<script>window.donationXss=true</script>');
  await page.getByRole('button',{name:'Invia la proposta',exact:true}).click();
  await expect(page.getByRole('status').filter({hasText:'Grazie!'})).toBeVisible();
  expect(fixture('state').books).toHaveLength(2);
  page.on('dialog', dialog => dialog.accept());
  await page.setViewportSize({width:1440,height:1000}); await login(page); await page.goto('/admin/desiderata');
  let row=page.locator('article').filter({hasText:'Offerta libera '+tag});
  await expect(row).toContainText('<script>window.donationXss=true</script>');
  expect(await page.evaluate(()=>window.donationXss)).toBeUndefined();
  await row.getByRole('searchbox').fill(seeded.prefix+' ordinario');
  await expect(row.locator('select option')).toHaveCount(2);
  await row.locator('select').selectOption(String(seeded.normal));
  await row.getByRole('button',{name:'Libro arrivato: registra una copia',exact:true}).click();
  await expect(page.locator('article').filter({hasText:'Offerta libera '+tag})).toContainText('Ricevuta');
  expect(fixture('state').books.find(b=>b.id===seeded.normal).physical).toBe(1);
});
