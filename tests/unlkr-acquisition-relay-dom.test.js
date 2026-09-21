const fs = require('fs');
const vm = require('vm');

const bootstrap = fs.readFileSync('web/app/mu-plugins/unlkr-acquisition-relay.js', 'utf8');

function check(condition, label) {
  if (!condition) throw new Error(`FAIL: ${label}`);
}

function run({ crypto = true } = {}) {
  let sequence = 0;
  const form = { listeners: {}, addEventListener(type, callback) { this.listeners[type] = callback; } };
  const input = { name: 'unlkr_attempt', value: '', form };
  const wrapper = {
    ready: false,
    getAttribute: (name) => name === 'data-form-id' ? '42' : null,
    querySelectorAll: () => wrapper.ready ? [input] : [],
    closest: () => form,
  };
  const meta = { getAttribute: (name) => name === 'data-form-id' ? '42' : 'unlkr_attempt' };
  let observer;
  const state = { timerCleared: false };
  const document = {
    readyState: 'complete', documentElement: {},
    querySelector: () => meta,
    querySelectorAll: () => [wrapper],
    addEventListener: () => {},
  };
  function MutationObserver(callback) {
    observer = { callback, disconnected: false, observe: () => {}, disconnect: () => { observer.disconnected = true; } };
    return observer;
  }
  const window = {
    MutationObserver,
    setTimeout: (callback, delay) => { if (delay === 0) callback(); return 7; },
    clearTimeout: () => { state.timerCleared = true; },
    crypto: crypto ? { randomUUID: () => `11111111-1111-4111-8111-${String(++sequence).padStart(12, '0')}`, getRandomValues: () => {} } : undefined,
  };
  vm.runInNewContext(bootstrap, { window, document, Uint8Array });
  return { input, wrapper, form, observer, state };
}

const asynchronous = run();
check(asynchronous.observer && !asynchronous.observer.disconnected, 'empty MetForm wrapper starts bounded observer');
asynchronous.wrapper.ready = true;
asynchronous.observer.callback();
const firstAttempt = asynchronous.input.value;
check(/^11111111-1111-4111-8111-/.test(firstAttempt), 'observer fills asynchronously rendered hidden attempt input');
check(asynchronous.observer.disconnected && asynchronous.state.timerCleared, 'observer and timeout are cleaned once input exists');
asynchronous.input.value = '';
asynchronous.form.listeners.reset();
check(asynchronous.input.value !== '' && asynchronous.input.value !== firstAttempt, 'targeted form reset rotates attempt token for second submission');

const noCrypto = run({ crypto: false });
noCrypto.wrapper.ready = true;
check(noCrypto.input.value === '', 'absence of Web Crypto remains fail-closed');

console.log('OK - 5 DOM assertions');
