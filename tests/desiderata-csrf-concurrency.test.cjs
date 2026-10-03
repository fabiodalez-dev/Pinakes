const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// Execute the production inline script. Only PHP-rendered configuration and DOM
// surfaces unrelated to submission are stubbed; requests resolve under our control.
function harness(initialToken = '') {
  const source = fs.readFileSync(path.join(__dirname, '../storage/plugins/desiderata/views/partials/offer-assets.php'), 'utf8');
  const script = source.split('<script>')[1].split('</script>')[0].replace(/<\?=[\s\S]*?\?>/g, '""');
  const calls = [];
  let submit, posted;
  const form = {
    elements: { csrf_token: { value: initialToken }, book_id: { value: '' } },
    querySelector: () => ({ disabled: false }),
    addEventListener: (_, fn) => { submit = fn; },
  };
  const root = { querySelector: selector => selector === 'form' ? form : null };
  vm.runInNewContext(script, {
    document: { querySelectorAll: selector => selector === '[data-desiderata-form]' ? [root] : [] },
    fetch: () => new Promise((resolve, reject) => calls.push({ resolve, reject })),
    HTMLFormElement: { prototype: { submit() { posted = this.elements.csrf_token.value; } } },
    window: {},
  });
  return { calls, send: () => submit({ preventDefault() {} }), posted: () => posted };
}
const tick = () => new Promise(setImmediate);
const response = token => ({ ok: true, json: async () => ({ token }) });

test('submit waits for the initial session before requesting a fresh token', async () => {
  const h = harness();
  const sending = h.send();
  await tick();
  assert.equal(h.calls.length, 1, 'no second request while the browser has no session');
  assert.equal(h.posted(), undefined);
  h.calls[0].resolve(response('initial-token'));
  await tick();
  assert.equal(h.calls.length, 2);
  h.calls[1].resolve(response('refreshed-token'));
  await sending;
  assert.equal(h.posted(), 'refreshed-token');
});

test('failed initialization settles before submit retries', async () => {
  const h = harness();
  const sending = h.send();
  h.calls[0].reject(new Error('network failure'));
  await tick();
  assert.equal(h.calls.length, 2);
  h.calls[1].resolve(response('retry-token'));
  await sending;
  assert.equal(h.posted(), 'retry-token');
});

test('invalid refresh response never submits the donation', async () => {
  const h = harness();
  h.calls[0].resolve(response('initial-token'));
  await tick();
  const sending = h.send();
  await tick();
  h.calls[1].resolve(response(''));
  await sending;
  assert.equal(h.posted(), undefined);
});

test('an existing session skips initialization and refreshes once on submit', async () => {
  const h = harness('existing-token');
  assert.equal(h.calls.length, 0);
  const sending = h.send();
  await tick();
  assert.equal(h.calls.length, 1);
  h.calls[0].resolve(response('fresh-token'));
  await sending;
  assert.equal(h.posted(), 'fresh-token');
});

test('malformed initialization is recovered by the submit refresh', async () => {
  const h = harness();
  h.calls[0].resolve(response(null));
  await tick();
  const sending = h.send();
  await tick();
  h.calls[1].resolve(response('recovered-token'));
  await sending;
  assert.equal(h.posted(), 'recovered-token');
});

test('an HTTP error cannot submit a stale token', async () => {
  const h = harness('stale-token');
  const sending = h.send();
  await tick();
  h.calls[0].resolve({ ok: false });
  await sending;
  assert.equal(h.posted(), undefined);
});

test('a failed submit can be retried successfully', async () => {
  const h = harness('old-token');
  const failed = h.send();
  await tick();
  h.calls[0].reject(new Error('offline'));
  await failed;
  assert.equal(h.posted(), undefined);
  const retry = h.send();
  await tick();
  h.calls[1].resolve(response('retry-token'));
  await retry;
  assert.equal(h.posted(), 'retry-token');
});
