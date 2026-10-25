const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/krbl.js'), 'utf8');

function screen(responses, missingOutput = false) {
  let onClick;
  const requests = [];
  const text = { textContent: 'Report broken links on this page' };
  const output = { children: [], replaceChildren(node) { this.children = [node]; } };
  const input = { value: 'https://example.net/missing', disabled: false, checkValidity: () => true, focus() { this.focused = true; } };
  const loading = new Set();
  const container = { querySelector: (selector) => selector === '.krbl-output' ? (missingOutput ? null : output) : input };
  const button = {
    disabled: false, dataset: { post: '42' },
    classList: { add: (name) => loading.add(name), remove: (name) => loading.delete(name) },
    closest: () => container, querySelector: () => text,
    remove() { throw new Error('Report button must remain usable'); }
  };
  vm.runInNewContext(source, {
    document: { addEventListener: (_, callback) => { onClick = callback; }, createElement: () => ({}) },
    KRBL: { nonce: 'test', ajaxUrl: '/ajax', i18n: {} }, URL, FormData,
    fetch: (_, request) => {
      requests.push(Object.fromEntries(request.body));
      const result = responses.shift();
      if (result instanceof Error) return Promise.reject(result);
      return Promise.resolve({ json: () => result === 'bad-json' ? Promise.reject(new Error('Invalid JSON')) : Promise.resolve(result) });
    }
  });
  return {
    input, button, output, requests, text, loading,
    click: () => onClick({ target: { closest: () => button }, preventDefault() {} }),
    settle: () => new Promise((resolve) => setImmediate(resolve))
  };
}

function assertReady(ui) {
  assert.equal(ui.button.disabled, false);
  assert.equal(ui.input.disabled, false);
  assert.equal(ui.loading.size, 0);
  assert.equal(ui.text.textContent, 'Report broken links on this page');
}

test('success restores controls and permits a second target without reload', async () => {
  const ui = screen([{ success: true }, { success: true }]);
  ui.click();
  assert.equal(ui.button.disabled, true);
  assert.equal(ui.input.disabled, true);
  ui.click();
  assert.equal(ui.requests.length, 1, 'in-flight clicks cannot duplicate a submission');
  await ui.settle();
  assertReady(ui);
  assert.equal(ui.input.value, '');
  ui.input.value = 'https://example.net/second';
  ui.click();
  await ui.settle();
  assertReady(ui);
  assert.deepEqual(ui.requests.map((request) => request.target_url), ['https://example.net/missing', 'https://example.net/second']);
});

test('duplicate preserves the target and allows changing it', async () => {
  const ui = screen([{ success: false, data: { code: 'duplicate' } }, { success: true }]);
  ui.click();
  await ui.settle();
  assertReady(ui);
  assert.equal(ui.input.value, 'https://example.net/missing');
  assert.equal(ui.output.children[0].className, 'krbl-msg notice');
  ui.input.value = 'https://example.net/another';
  ui.click();
  await ui.settle();
  assert.equal(ui.requests.length, 2);
});

for (const [label, result] of [
  ['server failure', { success: false }],
  ['network failure', new Error('Offline')],
  ['invalid JSON', 'bad-json'],
  ['rate limit', { success: false, data: { code: 'rate_limited', message: 'Please wait.' } }]
]) {
  test(label + ' restores controls and preserves the target', async () => {
    const ui = screen([result]);
    ui.click();
    await ui.settle();
    assertReady(ui);
    assert.equal(ui.input.value, 'https://example.net/missing');
    assert.equal(ui.output.children[0].className, 'krbl-msg error');
  });
}

test('invalid and unsupported targets are rejected before submission', () => {
  for (const value of ['https://', '/relative', 'ftp://example.net/a', 'https://user:pass@example.net/a']) {
    const ui = screen([]);
    ui.input.value = value;
    ui.click();
    assertReady(ui);
    assert.equal(ui.requests.length, 0);
    assert.equal(ui.input.focused, true);
  }
});

test('server messages are rendered as text', async () => {
  const message = '<img src=x onerror=alert(1)>';
  const ui = screen([{ success: false, data: message }]);
  ui.click();
  await ui.settle();
  assert.equal(ui.output.children[0].textContent, message);
  assert.equal(ui.output.children[0].innerHTML, undefined);
});

test('missing output element does not leave controls disabled', async () => {
  const ui = screen([new Error('Offline')], true);
  ui.click();
  await ui.settle();
  assertReady(ui);
});
