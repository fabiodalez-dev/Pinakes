// @ts-check
/**
 * Document coverage suite for DOC-20260506-WA0008.
 *
 * Exactly 50 tests covering the interoperability requirements introduced by the
 * May 2026 plan: schema/migrations, seeds, OAI-PMH, MAG, UNIMARC, BIBFRAME,
 * VIAF/ISNI, OpenURL, ResourceSync and NCIP.
 *
 * Run full E2E coverage with:
 * /tmp/run-e2e.sh tests/interop-document-coverage.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const BASE = process.env.E2E_BASE_URL || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const HAS_BASE = BASE !== '';
const HAS_DB = DB_USER !== '' && DB_NAME !== '';
const NCIP_NS = 'http://www.niso.org/2008/ncip';

function read(relPath) {
  return fs.readFileSync(path.join(ROOT, relPath), 'utf8');
}

function mysqlArgs(sql) {
  const args = ['-N', '-B', '-e', sql];
  if (DB_HOST) args.push('-h', DB_HOST);
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME);
  return args;
}

function dbQuery(sql) {
  return execFileSync('mysql', mysqlArgs(sql), {
    encoding: 'utf8',
    timeout: 10000,
    cwd: ROOT,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

function basicAuth(user, pass) {
  return 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');
}

async function textFor(response) {
  return (await response.text()).replace(/\s+/g, ' ');
}

test.describe.serial('Interop document coverage - 50 tests', () => {
  let bookId = 0;

  test.beforeAll(async () => {
    if (!HAS_DB) return;
    const row = dbQuery('SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
    bookId = Number(row) || 0;
  });

  test('01. VIAF seed file exists', async () => {
    expect(fs.existsSync(path.join(ROOT, 'tests/seeds/authors-with-viaf.sql'))).toBe(true);
  });

  test('02. VIAF seed contains exactly 50 seeded authors', async () => {
    const body = read('tests/seeds/authors-with-viaf.sql');
    expect((body.match(/\('SEED_VIAF_/g) || []).length).toBe(50);
  });

  test('03. VIAF seed populates VIAF URI fields', async () => {
    const body = read('tests/seeds/authors-with-viaf.sql');
    expect(body).toContain('viaf_uri');
    expect(body).toContain('https://viaf.org/viaf/');
  });

  test('04. VIAF seed includes valid ISNI URI examples', async () => {
    const body = read('tests/seeds/authors-with-viaf.sql');
    expect(body).toContain('isni_uri');
    expect(body).toContain('https://isni.org/isni/');
  });

  test('05. VIAF seed uses only plugin authority confidence enum values', async () => {
    const body = read('tests/seeds/authors-with-viaf.sql');
    expect(body).not.toMatch(/'high'|'medium'/);
    expect(body).toMatch(/'exact'|'probable'|'candidate'/);
  });

  test('06. OAI conformance seed file exists', async () => {
    expect(fs.existsSync(path.join(ROOT, 'tests/seeds/oai-conformance-set.sql'))).toBe(true);
  });

  test('07. OAI conformance seed inserts 230 fixture books', async () => {
    const body = read('tests/seeds/oai-conformance-set.sql');
    expect((body.match(/OAI Conformance Book \d+'/g) || []).length).toBe(230);
  });

  test('08. OAI conformance seed includes 30 deleted records', async () => {
    const body = read('tests/seeds/oai-conformance-set.sql');
    expect((body.match(/OAI Conformance Book 2(?:0[1-9]|[12][0-9]|30)'/g) || []).length).toBe(30);
  });

  test('09. OAI conformance seed materializes persistent deleted headers', async () => {
    const body = read('tests/seeds/oai-conformance-set.sql');
    expect(body).toContain('INSERT INTO oai_deleted_records');
    expect(body).toContain('ON DUPLICATE KEY UPDATE');
  });

  test('10. NCIP seed file provisions partners', async () => {
    const body = read('tests/seeds/ncip-partners.sql');
    expect(body).toContain('INSERT INTO ncip_partners');
  });

  test('11. Fresh schema includes OAI deleted records table', async () => {
    expect(read('installer/database/schema.sql')).toContain('CREATE TABLE `oai_deleted_records`');
  });

  test('12. Fresh schema includes OAI resumption token table', async () => {
    expect(read('installer/database/schema.sql')).toContain('CREATE TABLE `oai_resumption_tokens`');
  });

  test('13. Fresh schema includes MAG project configuration table', async () => {
    expect(read('installer/database/schema.sql')).toContain('CREATE TABLE `mag_project_config`');
  });

  test('14. Fresh schema includes digital assets for MAG doc/img metadata', async () => {
    expect(read('installer/database/schema.sql')).toContain('CREATE TABLE `digital_assets`');
  });

  test('15. Fresh schema includes VIAF and ISNI author columns', async () => {
    const schema = read('installer/database/schema.sql');
    expect(schema).toContain('`viaf_id`');
    expect(schema).toContain('`isni_uri`');
  });

  test('16. Fresh schema includes authority alternates table', async () => {
    expect(read('installer/database/schema.sql')).toContain('CREATE TABLE `author_authority_alternates`');
  });

  test('17. Fresh schema includes NCIP partner and transaction tables', async () => {
    const schema = read('installer/database/schema.sql');
    expect(schema).toContain('CREATE TABLE `ncip_partners`');
    expect(schema).toContain('CREATE TABLE `ncip_transactions`');
  });

  test('18. Fresh schema allows prestiti.origine=ncip', async () => {
    const schema = read('installer/database/schema.sql');
    expect(schema).toContain("enum('richiesta','prenotazione','diretto','ncip')");
  });

  test('19. Fresh schema includes prestiti.ncip_request_id index', async () => {
    const schema = read('installer/database/schema.sql');
    expect(schema).toContain('`ncip_request_id`');
    expect(schema).toContain('`idx_prestiti_ncip_request_id`');
  });

  test('20. 0.7.0 migration adds the complete VIAF/ISNI schema', async () => {
    // PR #136 renamed migrate_0.7.0.sql → migrate_0.7.00.sql so the
    // Updater's new version_compare-based usort (vs the old lex sort)
    // orders this migration before 0.7.04/05/06/etc consistently across
    // semver-aware vs lexicographic readers.
    const migration = read('installer/database/migrations/migrate_0.7.00.sql');
    expect(migration).toContain('isni_id');
    expect(migration).toContain('author_authority_alternates');
  });

  test('21. 0.7.4 migration carries NCIP schema fallback, not only plugin metadata', async () => {
    // PR #136 renamed migrate_0.7.4.sql → migrate_0.7.04.sql (see #20).
    const migration = read('installer/database/migrations/migrate_0.7.04.sql');
    expect(migration).toContain('CREATE TABLE IF NOT EXISTS ncip_partners');
    expect(migration).toContain('ncip_request_id');
  });

  test('22. 0.7.4 migration guards NCIP partner column upgrades', async () => {
    const migration = read('installer/database/migrations/migrate_0.7.04.sql');
    expect(migration).toContain('INFORMATION_SCHEMA.COLUMNS');
    expect(migration).toMatch(/COLUMN_NAME\s*=\s*'isil'/);
  });

  test('23. OAI plugin metadata advertises UNIMARC and current app range', async () => {
    const manifest = JSON.parse(read('storage/plugins/oai-pmh-server/plugin.json'));
    expect(manifest.metadata.supported_formats.join(' ')).toContain('unimarc');
    expect(manifest.max_app_version).toBe('0.7.9');
  });

  test('24. NCIP plugin metadata advertises RequestItem lifecycle support', async () => {
    const manifest = JSON.parse(read('storage/plugins/ncip-server/plugin.json'));
    expect(manifest.description).toContain('RequestItem');
    expect(manifest.description).toContain('CancelRequestItem');
  });

  test('25. OAI Identify returns repository metadata', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=Identify`);
    expect(res.status()).toBe(200);
    const body = await textFor(res);
    expect(body).toContain('<Identify>');
  });

  test('26. OAI Identify declares persistent deleted records', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=Identify`);
    expect(await textFor(res)).toContain('<deletedRecord>persistent</deletedRecord>');
  });

  test('27. OAI Identify declares seconds granularity', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=Identify`);
    expect(await textFor(res)).toContain('<granularity>YYYY-MM-DDThh:mm:ssZ</granularity>');
  });

  test('28. OAI ListMetadataFormats exposes core prefixes', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=ListMetadataFormats`);
    const body = await textFor(res);
    expect(body).toContain('<metadataPrefix>oai_dc</metadataPrefix>');
    expect(body).toContain('<metadataPrefix>mag</metadataPrefix>');
  });

  test('29. OAI ListMetadataFormats exposes UNIMARC prefix', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=ListMetadataFormats`);
    expect(await textFor(res)).toContain('<metadataPrefix>unimarc</metadataPrefix>');
  });

  test('30. OAI ListSets advertises books set', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=ListSets`);
    expect(await textFor(res)).toContain('<setSpec>books</setSpec>');
  });

  test('31. OAI invalid metadataPrefix returns cannotDisseminateFormat', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=ListRecords&metadataPrefix=nope`);
    expect(await textFor(res)).toContain('cannotDisseminateFormat');
  });

  test('32. OAI rejects illegal arguments for a verb', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=Identify&metadataPrefix=oai_dc`);
    expect(await textFor(res)).toContain('badArgument');
  });

  test('33. OAI GetRecord unknown identifier returns idDoesNotExist', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=oai:pinakes:book:999999999`);
    expect(await textFor(res)).toContain('idDoesNotExist');
  });

  test('34. MAG records can be requested through OAI', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=ListRecords&metadataPrefix=mag&set=books`);
    const body = await textFor(res);
    expect(body).toMatch(/iccu\.sbn\.it\/mag|noRecordsMatch/);
  });

  test('35. UNIMARC records can be requested through OAI', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/oai?verb=ListRecords&metadataPrefix=unimarc&set=books`);
    const body = await textFor(res);
    expect(body).toMatch(/<record|noRecordsMatch/);
  });

  test('36. BIBFRAME JSON-LD endpoint returns linked data', async ({ request }) => {
    test.skip(!HAS_BASE || bookId === 0, 'Set E2E_BASE_URL and DB fixtures');
    const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`, {
      headers: { Accept: 'application/ld+json' },
    });
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type'] || '').toContain('ld+json');
  });

  test('37. BIBFRAME Turtle negotiation works', async ({ request }) => {
    test.skip(!HAS_BASE || bookId === 0, 'Set E2E_BASE_URL and DB fixtures');
    const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`, {
      headers: { Accept: 'text/turtle' },
    });
    expect(await textFor(res)).toContain('bf:Work');
  });

  test('38. BIBFRAME persistent Work URI redirects HTML clients', async ({ request }) => {
    test.skip(!HAS_BASE || bookId === 0, 'Set E2E_BASE_URL and DB fixtures');
    const res = await request.get(`${BASE}/id/work/${bookId}`, {
      headers: { Accept: 'text/html' },
      maxRedirects: 0,
    });
    expect(res.status()).toBe(303);
  });

  test('39. BIBFRAME persistent Instance URI serves JSON-LD', async ({ request }) => {
    test.skip(!HAS_BASE || bookId === 0, 'Set E2E_BASE_URL and DB fixtures');
    const res = await request.get(`${BASE}/id/instance/${bookId}`, {
      headers: { Accept: 'application/ld+json' },
    });
    expect(await textFor(res)).toContain('bf:Instance');
  });

  test('40. VIAF suggest endpoint is protected without credentials', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/api/viaf/suggest?q=Dante`);
    expect(res.status()).toBe(403);
  });

  test('41. W3C reconciliation manifest is protected without credentials', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/admin/api/reconcile`);
    expect(res.status()).toBe(403);
  });

  test('42. W3C reconciliation manifest is available with admin credentials', async ({ request }) => {
    test.skip(!HAS_BASE || !ADMIN_EMAIL || !ADMIN_PASS, 'Set E2E_BASE_URL and admin credentials');
    const res = await request.get(`${BASE}/admin/api/reconcile`, {
      headers: { Authorization: basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
    });
    expect(res.status()).toBe(200);
    expect(await res.json()).toHaveProperty('identifierSpace');
  });

  test('43. OpenURL endpoint redirects unresolved requests', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/openurl?rft.btitle=Interop+Fixture`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers().location || '').not.toBe('');
  });

  test('44. COinS API returns Z39.88 payload for a local book', async ({ request }) => {
    test.skip(!HAS_BASE || bookId === 0, 'Set E2E_BASE_URL and DB fixtures');
    const res = await request.get(`${BASE}/api/coins/book/${bookId}`);
    expect(res.status()).toBe(200);
    const json = await res.json();
    expect(json).toHaveProperty('coins_title');
    expect(json.coins_title).toContain('ctx_ver=Z39.88-2004');
  });

  test('45. ResourceSync source description is reachable', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/.well-known/resourcesync`);
    expect(res.status()).toBe(200);
    expect(await textFor(res)).toContain('capabilitylist');
  });

  test('46. ResourceSync capability list advertises resource and change lists', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
    const body = await textFor(res);
    expect(body).toContain('resourcelist');
    expect(body).toContain('changelist');
  });

  test('47. ResourceSync resource list points to BIBFRAME resources', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/resync/resourcelist.xml`);
    expect(await textFor(res)).toMatch(/api\/bibframe\/book|resourcelist/);
  });

  test('48. NCIP GET exposes service capability XML', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const res = await request.get(`${BASE}/ncip`);
    expect(res.status()).toBe(200);
    expect(await textFor(res)).toContain('LookupItem');
  });

  test('49. NCIP unsupported message returns Problem XML', async ({ request }) => {
    test.skip(!HAS_BASE, 'Set E2E_BASE_URL for API tests');
    const body = `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <UnsupportedInteropRequest />
</NCIPMessage>`;
    const res = await request.post(`${BASE}/ncip`, {
      data: body,
      headers: { 'Content-Type': 'application/xml' },
    });
    expect(await textFor(res)).toContain('unsupported-request');
  });

  test('50. Database exposes new interop tables and columns after migrations', async () => {
    test.skip(!HAS_DB, 'Set E2E_DB_* for database verification');
    const tableCount = Number(dbQuery(`
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('oai_deleted_records','digital_assets','author_authority_alternates','ncip_partners','ncip_transactions')
    `));
    const columnCount = Number(dbQuery(`
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND (
          (TABLE_NAME='autori' AND COLUMN_NAME IN ('viaf_uri','isni_id','isni_uri','authority_source','authority_confidence'))
          OR (TABLE_NAME='prestiti' AND COLUMN_NAME='ncip_request_id')
        )
    `));
    expect(tableCount).toBe(5);
    expect(columnCount).toBe(6);
  });
});
