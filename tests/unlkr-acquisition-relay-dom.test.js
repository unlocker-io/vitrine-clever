const fs = require('fs');
const vm = require('vm');

const bootstrap = fs.readFileSync('web/app/mu-plugins/unlkr-acquisition-relay.js', 'utf8');

function check(condition, label) {
  if (!condition) throw new Error(`FAIL: ${label}`);
}

let sequence = 0;

function memoryStorage() {
  const values = {};
  return {
    values,
    getItem: (key) => Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null,
    setItem: (key, value) => { values[key] = String(value); },
    removeItem: (key) => { delete values[key]; },
  };
}

function run({ crypto = true, localStorage = memoryStorage() } = {}) {
  const form = { listeners: {}, addEventListener(type, callback) { this.listeners[type] = callback; } };
  const input = { name: 'unlkr_attempt', value: '', form };
  const wrapper = {
    ready: false,
    getAttribute: (name) => name === 'data-form-id' ? '42' : null,
    querySelectorAll: () => wrapper.ready ? [input] : [],
    closest: () => form,
  };
  const meta = { getAttribute: (name) => ({ 'data-form-id': '42', 'data-attempt-field': 'unlkr_attempt', 'data-attempt-retention-seconds': '2592000' })[name] || null };
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
    localStorage,
  };
  vm.runInNewContext(bootstrap, { window, document, Uint8Array });
  return { input, wrapper, form, observer, state, localStorage };
}

function renderAsync(instance) {
  instance.wrapper.ready = true;
  instance.observer.callback();
}

const browserStorage = memoryStorage();
const asynchronous = run({ localStorage: browserStorage });
check(asynchronous.observer && !asynchronous.observer.disconnected, 'empty MetForm wrapper starts bounded observer');
renderAsync(asynchronous);
const firstAttempt = asynchronous.input.value;
check(/^11111111-1111-4111-8111-/.test(firstAttempt), 'observer fills asynchronously rendered hidden attempt input');
check(asynchronous.observer.disconnected && asynchronous.state.timerCleared, 'observer and timeout are cleaned once input exists');
asynchronous.input.value = '';
asynchronous.form.listeners.reset();
check(asynchronous.input.value === firstAttempt, 'targeted reset keeps the stable attempt token for an identical retry');

const reloaded = run({ localStorage: browserStorage });
renderAsync(reloaded);
check(reloaded.input.value === firstAttempt, 'a fresh page bootstrap reuses the opaque browser/form attempt token');
const stored = JSON.parse(browserStorage.values.unlkr_acquisition_attempt_v1_42);
check(stored.v === 1 && stored.id === firstAttempt && stored.expires_at > Date.now(), 'attempt storage is versioned, opaque and has a bounded expiry');
browserStorage.values.unlkr_acquisition_attempt_v1_42 = JSON.stringify({ v: 1, id: firstAttempt, expires_at: Date.now() - 1 });
const expired = run({ localStorage: browserStorage });
renderAsync(expired);
check(expired.input.value !== firstAttempt, 'expired browser attempt record rotates to a new UUID');

const noStorage = run({ localStorage: null });
renderAsync(noStorage);
check(noStorage.input.value === '', 'unavailable first-party storage remains fail-closed');

const noCrypto = run({ crypto: false });
noCrypto.wrapper.ready = true;
check(noCrypto.input.value === '', 'absence of Web Crypto remains fail-closed');

console.log('OK - 9 DOM assertions');
