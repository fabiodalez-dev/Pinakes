const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const marker = `Article412-${Date.now()}`;
function db(sql) {
  const args = ['-u',process.env.E2E_DB_USER,process.env.E2E_DB_NAME,'-N','-B','-e',sql];
  if(process.env.E2E_DB_SOCKET) args.unshift('-S',process.env.E2E_DB_SOCKET);
  return execFileSync('mysql',args,{encoding:'utf8',env:{...process.env,MYSQL_PWD:process.env.E2E_DB_PASS}}).trim();
}
async function login(page) {
  await page.goto(BASE+'/admin/periodicals/articles');
  if(await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASS);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u=>!u.pathname.includes('accedi')&&!u.pathname.includes('login'));
  }
}
let originalMode; let articleId; let testataId;
test.describe.serial('Emeroteca 412 complete workflow',()=>{
  test.beforeAll(()=>{
    if(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_USER) throw new Error('Run with /tmp/run-e2e.sh');
    originalMode=db("SELECT setting_value FROM plugin_settings WHERE plugin_id=(SELECT id FROM plugins WHERE name='emeroteca') AND setting_key='mode'");
  });
  test.afterAll(()=>{
    try {
      const pdf=db(`SELECT COALESCE(pdf_path,'') FROM emeroteca_contributi WHERE titolo LIKE '${marker}%'`);
      db(`DELETE FROM emeroteca_contributi WHERE titolo LIKE '${marker}%'`);
      db(`DELETE FROM emeroteca_testate WHERE titolo LIKE '${marker}%'`);
      if(originalMode) db(`UPDATE plugin_settings SET setting_value='${originalMode==='simple'?'simple':'complete'}' WHERE plugin_id=(SELECT id FROM plugins WHERE name='emeroteca') AND setting_key='mode'`);
      for(const name of pdf.split('\n')) if(/^[a-f0-9]{40}\.pdf$/.test(name)) fs.rmSync(`storage/uploads/plugins/emeroteca/contributi/${name}`,{force:true});
    } catch(e) { console.error('Scoped cleanup failed:',e.message); }
  });
  test('create, publish, attach later, import, privacy, PDF, modes and mobile layout',async({page,browser})=>{
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await login(page);
    await page.goto(BASE+'/admin/periodicals/articles');
    await expect(page.getByRole('heading',{name:'Articoli',exact:true})).toBeVisible();
    await page.getByRole('link',{name:'Aggiungi articolo',exact:true}).click();
    for(const [name,value] of Object.entries({titolo:marker+' Tyll',autori:'Marc J. Schweissinger',contenitore_titolo:'International Journal of Language and Literature',data_pubblicazione_testo:'giugno 2019',anno_pubblicazione:'2019',volume:'7',numero:'1',pagine:'138–148'})) {
      await page.locator(`[name="${name}"]`).fill(value);
    }
    await page.locator('[name=pubblico]').check();
    await page.getByText('Descrizione, note e PDF',{exact:true}).click();
    await page.locator('[name=note_private]').fill('SECRET412');
    await page.locator('[name=pdf]').setInputFiles({name:'tyll.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n')});
    await page.getByRole('button',{name:'Salva articolo',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Modifica articolo'})).toBeVisible();
    articleId=Number(page.url().split('/').pop());
    expect(articleId).toBeGreaterThan(0);
    expect(db(`SELECT CONCAT(COALESCE(testata_id,0),':',COALESCE(fascicolo_id,0)) FROM emeroteca_contributi WHERE id=${articleId}`)).toBe('0:0');
    const anonymous=await browser.newContext();const publicPage=await anonymous.newPage();
    await publicPage.goto(BASE+`/emeroteca/articolo/${articleId}`);
    await expect(publicPage.getByRole('heading',{name:marker+' Tyll'})).toBeVisible();
    await expect(publicPage.locator('body')).not.toContainText('SECRET412');
    expect((await publicPage.request.get(BASE+`/emeroteca/articolo/${articleId}/pdf`)).status()).toBe(404);
    await page.getByText('Descrizione, note e PDF',{exact:true}).click();
    await page.locator('[name=pdf_pubblico]').check();await page.getByRole('button',{name:'Salva articolo',exact:true}).click();
    const pdf=await publicPage.request.get(BASE+`/emeroteca/articolo/${articleId}/pdf`);expect(pdf.status()).toBe(200);expect(pdf.headers()['cache-control']).toContain('no-store');
    const originalPdf=db(`SELECT pdf_path FROM emeroteca_contributi WHERE id=${articleId}`);
    await page.getByText('Descrizione, note e PDF',{exact:true}).click();
    await page.locator('[name=pdf]').setInputFiles({name:'invalid.pdf',mimeType:'application/pdf',buffer:Buffer.from('not a PDF')});
    await page.getByRole('button',{name:'Salva articolo',exact:true}).click();
    await expect(page.getByRole('alert')).toContainText('PDF');
    expect(db(`SELECT pdf_path FROM emeroteca_contributi WHERE id=${articleId}`)).toBe(originalPdf);
    await page.goto(BASE+`/admin/periodicals/articles/${articleId}`);
    await page.getByText('Descrizione, note e PDF',{exact:true}).click();
    await page.locator('[name=pdf]').setInputFiles({name:'replacement.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n')});
    await page.getByRole('button',{name:'Salva articolo',exact:true}).click();
    expect(db(`SELECT pdf_path FROM emeroteca_contributi WHERE id=${articleId}`)).not.toBe(originalPdf);
    expect(fs.existsSync(`storage/uploads/plugins/emeroteca/contributi/${originalPdf}`)).toBe(false);
    await page.goto(BASE+'/admin/periodicals/articles');
    await page.locator(`[name="ids[]"][value="${articleId}"]`).check();
    await page.getByText('Associa gli articoli selezionati a una testata',{exact:true}).click();
    await page.locator('[name=new_title]').fill(marker+' Journal');
    await page.getByRole('button',{name:'Anteprima associazione'}).click();
    await expect(page.getByRole('heading',{name:'Anteprima associazione'})).toBeVisible();
    await page.getByRole('button',{name:'Conferma associazione'}).click();
    testataId=Number(db(`SELECT testata_id FROM emeroteca_contributi WHERE id=${articleId}`));expect(testataId).toBeGreaterThan(0);
    expect(db(`SELECT COUNT(*) FROM emeroteca_annate WHERE testata_id=${testataId}`)).toBe('0');
    db(`INSERT INTO emeroteca_annate(testata_id,anno,volume) VALUES(${testataId},2019,'7'); SET @a=LAST_INSERT_ID(); INSERT INTO emeroteca_fascicoli(annata_id,numero) VALUES(@a,'1'); SET @f=LAST_INSERT_ID(); INSERT INTO emeroteca_articoli(fascicolo_id,titolo) VALUES(@f,'${marker} Indexed')`);
    await page.goto(BASE+`/admin/periodicals/articles?source=spoglio&testata=${testataId}`);
    await expect(page.getByRole('link',{name:marker+' Indexed',exact:true})).toBeVisible();
    await expect(page.locator('[name="ids[]"]')).toHaveCount(0);
    await page.goto(BASE+'/admin/periodicals/articles');
    // The issue picker is fetched per masthead instead of holding the whole
    // Kardex: this masthead has exactly one issue, so choosing it must list that
    // one and nothing from the other mastheads; clearing it must empty the list.
    await page.getByText('Associa gli articoli selezionati a una testata',{exact:true}).click();
    await page.locator('#target-host').selectOption(String(testataId));
    await expect(page.locator('#target-issue option')).toHaveCount(2);
    await expect(page.locator('#target-issue option').nth(1)).toHaveText('2019 · 7 · 1');
    await page.locator('#target-host').selectOption('0');
    await expect(page.locator('#target-issue option')).toHaveCount(1);
    await publicPage.goto(BASE+`/emeroteca/${testataId}`);await expect(publicPage.getByRole('link',{name:marker+' Tyll'})).toBeVisible();
    // The chooser is radio rows now (the plugin's emt-choice pattern, shared by
    // the mastheads list, the articles list and the plugin settings page) and it
    // returns the operator to the page they submitted from rather than to a
    // fixed target — which is why both switches land back on the articles list.
    await page.locator('input[name=mode][value=simple]').check();await page.getByRole('button',{name:'Salva modalità'}).click();
    await expect(page).toHaveURL(/\/admin\/periodicals\/articles/);
    await page.locator('input[name=mode][value=complete]').check();await page.getByRole('button',{name:'Salva modalità'}).click();
    await expect(page).toHaveURL(/\/admin\/periodicals\/articles/);
    await page.goto(BASE+'/admin/periodicals/articles/import');
    await page.locator('[name=csv]').setInputFiles({name:'articles.csv',mimeType:'text/csv',buffer:Buffer.from(`titolo,media_type,container_title,pages\n${marker} Imported,journal_article,Host,iv–x\n`)});
    await page.getByRole('button',{name:'Mostra anteprima'}).click();await expect(page.getByText('Anteprima: destinazione Emeroteca')).toBeVisible();
    await page.getByRole('button',{name:'Importa le righe valide'}).click();await expect(page.getByText('Risultato importazione')).toBeVisible();
    expect(db(`SELECT pagine FROM emeroteca_contributi WHERE titolo='${marker} Imported'`)).toBe('iv–x');
    await page.goto(BASE+`/admin/periodicals/articles/${articleId}`);
    await page.locator('[name=pubblico]').uncheck();await page.getByRole('button',{name:'Salva articolo',exact:true}).click();
    expect((await publicPage.request.get(BASE+`/emeroteca/articolo/${articleId}`)).status()).toBe(404);
    expect((await publicPage.request.get(BASE+`/emeroteca/articolo/${articleId}/pdf`)).status()).toBe(404);
    const csrf=await page.locator('[name=csrf_token]').first().inputValue();
    expect((await page.request.post(BASE+'/admin/periodicals/articles/save',{form:{id:articleId,titolo:'tamper'}})).status()).toBe(403);
    await page.setViewportSize({width:390,height:844});await page.goto(BASE+'/admin/periodicals/articles/create');
    await expect(page.locator('[name=titolo]')).toBeVisible();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
    fs.mkdirSync('output/playwright',{recursive:true});await page.screenshot({path:'output/playwright/emeroteca-412-form-mobile.png',fullPage:true});
    await page.setViewportSize({width:1440,height:1000});await page.reload();await page.screenshot({path:'output/playwright/emeroteca-412-form-desktop.png',fullPage:true});
    expect(errors).toEqual([]);await anonymous.close();
  });
});
