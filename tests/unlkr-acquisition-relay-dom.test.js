const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('web/app/mu-plugins/unlkr-acquisition-relay.php', 'utf8');
const scriptMatch = source.match(/<script>([\s\S]*?)<\/script>/);
if (!scriptMatch) throw new Error('relay bootstrap script missing');
const bootstrap = scriptMatch[1]
  .replace('<?php echo $form_id; ?>', '42')
  .replace('<?php echo $field_name; ?>', '"unlkr_attempt"');

function check(condition, label) {
  if (!condition) throw new Error(`FAIL: ${label}`);
}

function run({ crypto = true } = {}) {
  const input = { name: 'unlkr_attempt', value: '' };
  const wrapper = {
    getAttribute: (name) => name === 'data-form-id' ? '42' : null,
    querySelectorAll: () => wrapper.ready ? [input] : [],
    ready: false,
  };
  let observer;
  const state = { stopped: false };
  const document = {
    readyState: 'complete',
    documentElement: {},
    querySelectorAll: () => [wrapper],
    addEventListener: () => {},
  };
  function MutationObserver(callback) {
    observer = { callback, disconnected: false, observe: () => {}, disconnect: () => { observer.disconnected = true; } };
    return observer;
  }
  const window = {
    MutationObserver,
    setTimeout: () => 7,
    clearTimeout: () => { state.stopped = true; },
    crypto: crypto ? { randomUUID: () => '11111111-1111-4111-8111-111111111111', getRandomValues: () => {} } : undefined,
  };
  vm.runInNewContext(bootstrap, { window, document, Uint8Array });
  return { input, wrapper, observer, state };
}

const asynchronous = run();
check(asynchronous.observer && !asynchronous.observer.disconnected, 'empty MetForm wrapper starts bounded observer');
asynchronous.wrapper.ready = true;
asynchronous.observer.callback();
check(asynchronous.input.value === '11111111-1111-4111-8111-111111111111', 'observer fills asynchronously rendered hidden attempt input');
check(asynchronous.observer.disconnected && asynchronous.state.stopped, 'observer and timeout are cleaned once input exists');

const noCrypto = run({ crypto: false });
noCrypto.wrapper.ready = true;
noCrypto.observer.callback();
check(noCrypto.input.value === '', 'absence of Web Crypto remains fail-closed');

console.log('OK - 4 DOM assertions');
