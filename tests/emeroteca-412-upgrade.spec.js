const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const BASE=process.env.E2E_BASE_URL;
const phase=process.env.E2E_412_UPGRADE_PHASE;
test.skip(!BASE || !phase, 'Dedicated disposable upgrade instance required');
function db(sql) {
    return execFileSync('mysql',['-S',process.env.E2E_DB_SOCKET,'-u',process.env.E2E_DB_USER,process.env.E2E_DB_NAME,'-N','-B','-e',sql],{encoding:'utf8',env:{...process.env,MYSQL_PWD:process.env.E2E_DB_PASS}}).trim();
}
async function login(page) {
    await page.goto(BASE+'/admin/plugins');
    console.log('Login page:', page.url());
    await page.locator('[name=email]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('[name=password]').fill(process.env.E2E_ADMIN_PASS);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u=>u.pathname.startsWith('/admin'));
    console.log('Logged in:', page.url());
    await page.goto(BASE+'/admin/plugins');
}
async function activate(page) {
    console.log('Activating plugin');
    const id=db("SELECT id FROM plugins WHERE name='emeroteca'");
    const card=page.locator(`[data-plugin-id="${id}"]`).first();
    await card.locator('button:has-text("Attiva")').click();
    const confirmation=page.locator('.swal2-confirm');
    await expect(confirmation).toBeVisible();await confirmation.click();
    await expect.poll(()=>db(`SELECT is_active FROM plugins WHERE id=${Number(id)}`)).toBe('1');
    await page.goto(BASE+'/admin/periodicals');
}
test('legacy plugin preparation or post-upgrade verification',async({page})=>{
    test.setTimeout(45000);
    await login(page);
    if(phase==='prepare') {
        await activate(page);
        expect(db("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='emeroteca_contributi'")).toBe('0');
        db("INSERT INTO emeroteca_testate(titolo) VALUES('Legacy412'); SET @t=LAST_INSERT_ID(); INSERT INTO emeroteca_annate(testata_id,anno,volume) VALUES(@t,2019,'7'); SET @a=LAST_INSERT_ID(); INSERT INTO emeroteca_fascicoli(annata_id,numero,stato) VALUES(@a,'1','posseduto'); SET @f=LAST_INSERT_ID(); INSERT INTO emeroteca_articoli(fascicolo_id,titolo,pagina_inizio,pagina_fine) VALUES(@f,'Legacy412 article',138,148)");
    } else if(phase==='fresh') {
        await activate(page);
        expect(db("SELECT setting_value FROM plugin_settings WHERE plugin_id=(SELECT id FROM plugins WHERE name='emeroteca') AND setting_key='mode'")).toBe('simple');
        await expect(page).toHaveURL(/\/admin\/periodicals\/articles$/);
        await expect(page.getByRole('link',{name:'Aggiungi articolo',exact:true})).toBeVisible();
    } else {
        await page.goto(BASE+'/admin/periodicals');
        expect(JSON.parse(fs.readFileSync(process.env.E2E_INSTALL_ROOT+'/version.json','utf8')).version).toBe('0.7.84');
        expect(db("SELECT version FROM plugins WHERE name='emeroteca'")).toBe('1.5.0');
        expect(db("SELECT setting_value FROM plugin_settings WHERE plugin_id=(SELECT id FROM plugins WHERE name='emeroteca') AND setting_key='mode'")).toBe('complete');
        expect(db("SELECT COUNT(*) FROM emeroteca_articoli WHERE titolo='Legacy412 article' AND pagina_inizio=138 AND pagina_fine=148")).toBe('1');
        expect(db("SELECT COUNT(*) FROM emeroteca_fascicoli WHERE stato='posseduto'")).toBe('1');
        expect(db('SELECT COUNT(*) FROM emeroteca_contributi')).toBe('0');
        await page.goto(BASE+'/admin/periodicals/articles/create');
        await page.locator('[name=titolo]').fill('Upgraded412 article');
        await page.getByRole('button',{name:'Salva articolo',exact:true}).click();
        await expect(page.getByRole('heading',{name:'Modifica articolo'})).toBeVisible();
        expect(db("SELECT COUNT(*) FROM emeroteca_contributi WHERE titolo='Upgraded412 article'")).toBe('1');
        await page.goto(BASE+'/admin/plugins');
        const id=db("SELECT id FROM plugins WHERE name='emeroteca'");
        await page.goto(BASE+`/admin/plugins/${id}/settings`);
        await expect(page.locator('[name=mode]')).toHaveValue('complete');
    }
});
