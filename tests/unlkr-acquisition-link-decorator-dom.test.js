const fs = require('fs');
const vm = require('vm');

// UNL-4643, amendment 4: the fragment continuity link decorator lives inside
// the touches producer (docs/crm-acquisition-touches.md), so it is exercised
// against the real file, exactly like tests/unlkr-acquisition-touches-dom.test.js.
const producer = fs.readFileSync('web/app/mu-plugins/unlkr-acquisition-touches.js', 'utf8');
const HANDLE = `av1_${'A'.repeat(43)}`;
const RECEIPT = `acr1_${'B'.repeat(43)}`;
const OTHER_HANDLE = `av1_${'C'.repeat(43)}`;
const OTHER_RECEIPT = `acr1_${'D'.repeat(43)}`;

let assertions = 0;
function check(condition, label) {
  assertions++;
  if (!condition) throw new Error(`FAIL: ${label}`);
}

function memoryStorage(initial = {}) {
  const values = Object.assign({}, initial);
  return {
    values,
    getItem: key => Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null,
    setItem: (key, value) => { values[key] = String(value); },
    removeItem: key => { delete values[key]; },
  };
}

function handleRecord(overrides = {}) {
  return JSON.stringify(Object.assign({
    v: 1,
    visitor_handle: HANDLE,
    consent_receipt: RECEIPT,
    expires_at: Date.now() + 3600000,
  }, overrides));
}

function continuityRecord(overrides = {}) {
  const payload = Object.assign({
    schema_version: 1,
    site_key: 'unlocker-web',
    visitor_handle: OTHER_HANDLE,
    consent_receipt: OTHER_RECEIPT,
    identify_attempt_id: '11111111-1111-4111-8111-111111111111',
  }, overrides.payload || {});
  return JSON.stringify(Object.assign({
    v: 1,
    payload,
    expires_at: Date.now() + 3600000,
  }, overrides, { payload }));
}

function anchor(href) {
  const el = {
    tagName: 'A',
    href,
    closest(selector) {
      return selector === 'a[href]' && typeof this.href === 'string' && this.href !== '' ? this : null;
    },
  };
  return el;
}

// Simulates a click landing on a nested element (e.g. an inner <span>) whose
// closest('a[href]') resolves to the real anchor -- the exact case a plain
// event.target check would miss.
function nestedTarget(anchorEl) {
  return {
    tagName: 'SPAN',
    closest(selector) { return anchorEl.closest(selector); },
  };
}

function run({ sessionStorage = memoryStorage(), attrs = {}, getCkyConsent } = {}) {
  const documentListeners = {};
  const metaValues = Object.assign({
    'data-preferences-url': 'https://crm.example.test/acquisition-web/preferences',
    'data-touches-url': 'https://crm.example.test/acquisition-web/touches',
    'data-site-key': 'unlocker-web',
    'data-notice-version': '2026-09',
    'data-landing-key': 'vitrine_web',
    'data-ttl-seconds': '300',
    'data-timeout-ms': '3000',
    'data-retries': '1',
    'data-link-decorator-enabled': '1',
    'data-link-decorator-app-origins': 'https://app.unlocker.io',
  }, attrs);
  const document = {
    querySelector: () => ({ getAttribute: name => (name in metaValues ? metaValues[name] : null) }),
    // Supports multiple listeners per event name (click AND auxclick), unlike
    // the single-slot dict in the sibling touches-dom harness -- irrelevant
    // there since it never registers two listeners under the same name.
    addEventListener: (name, callback) => {
      documentListeners[name] = documentListeners[name] || [];
      documentListeners[name].push(callback);
    },
  };
  const window = {
    location: { search: '' },
    sessionStorage,
    crypto: { randomUUID: () => '11111111-1111-4111-8111-000000000000', getRandomValues: () => {} },
    fetch: async () => ({ status: 200, json: async () => ({}) }),
    URL,
    AbortController: function () { this.signal = {}; this.abort = () => {}; },
    setTimeout: () => 0,
    clearTimeout: () => {},
    getCkyConsent,
  };
  vm.runInNewContext(producer, { window, document, Uint8Array, Date, Number, Object, Array, JSON, decodeURIComponent, URL });
  return { documentListeners, window };
}

function dispatch(instance, type, target) {
  const listeners = instance.documentListeners[type] || [];
  listeners.forEach(listener => listener({ target }));
}

function granted() {
  return { isUserActionCompleted: true, categories: { advertisement: true } };
}

function denied() {
  return { isUserActionCompleted: true, categories: { advertisement: false } };
}

(async () => {
  // 1. Consent + valid C2c handle in storage => the clicked href gets the
  // #ul_ch=<handle>.<receipt> fragment.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });
    const link = anchor('https://app.unlocker.io/login');
    dispatch(instance, 'click', link);
    check(link.href === `https://app.unlocker.io/login#ul_ch=${HANDLE}.${RECEIPT}`, 'consent granted + valid C2c couple: clicked href carries the ul_ch fragment');
  }

  // 2. No consent at all => href left untouched even with a valid couple.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: undefined });
    const link = anchor('https://app.unlocker.io/login');
    dispatch(instance, 'click', link);
    check(link.href === 'https://app.unlocker.io/login', 'no getCkyConsent at all (no CookieYes signal): href is left untouched');
  }

  // 2b. Explicit denial => href left untouched.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: denied });
    const link = anchor('https://app.unlocker.io/register');
    dispatch(instance, 'click', link);
    check(link.href === 'https://app.unlocker.io/register', 'explicit advertisement denial: href is left untouched');
  }

  // 3. Consent granted but the stored couple is EXPIRED => href untouched.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord({ expires_at: Date.now() - 1000 }));
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });
    const link = anchor('https://app.unlocker.io/login');
    dispatch(instance, 'click', link);
    check(link.href === 'https://app.unlocker.io/login', 'expired C2c couple: href is left untouched');
  }

  // 3b. Consent granted but the stored couple has an INVALID format => href untouched.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord({ visitor_handle: 'not-a-handle' }));
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });
    const link = anchor('https://app.unlocker.io/login');
    dispatch(instance, 'click', link);
    check(link.href === 'https://app.unlocker.io/login', 'malformed C2c handle: href is left untouched');
  }

  // 4. Links to a look-alike or downgraded origin are never decorated.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });

    const lookalike = anchor('https://app.unlocker.io.evil.com/login');
    dispatch(instance, 'click', lookalike);
    check(lookalike.href === 'https://app.unlocker.io.evil.com/login', 'look-alike subdomain suffix origin is never decorated');

    const downgraded = anchor('http://app.unlocker.io/login');
    dispatch(instance, 'click', downgraded);
    check(downgraded.href === 'http://app.unlocker.io/login', 'plain-HTTP origin (scheme differs) is never decorated');

    const unrelated = anchor('https://unlocker.io/carte-t/');
    dispatch(instance, 'click', unrelated);
    check(unrelated.href === 'https://unlocker.io/carte-t/', 'a same-site but different-origin link is never decorated');
  }

  // 5. Existing hash is preserved and an existing ul_ch is replaced in place.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });
    const link = anchor('https://app.unlocker.io/login#foo=bar&ul_ch=stale_value&baz=1');
    dispatch(instance, 'click', link);
    check(link.href === `https://app.unlocker.io/login#foo=bar&ul_ch=${HANDLE}.${RECEIPT}&baz=1`, 'an existing hash is preserved and a stale ul_ch entry is replaced in place');
  }

  // 6. A link injected into the page AFTER the script ran is still covered
  // (delegation, not a load-time DOM rewrite): the anchor object is created
  // only after run() has already wired up its listeners.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });
    const lateLink = anchor('https://app.unlocker.io/register');
    dispatch(instance, 'click', nestedTarget(lateLink));
    check(lateLink.href === `https://app.unlocker.io/register#ul_ch=${HANDLE}.${RECEIPT}`, 'a link injected after load, clicked via a nested child target, is still decorated through delegation');
  }

  // 6b. auxclick (e.g. middle-click to open in a new tab) is covered too.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });
    const link = anchor('https://app.unlocker.io/login');
    dispatch(instance, 'auxclick', link);
    check(link.href === `https://app.unlocker.io/login#ul_ch=${HANDLE}.${RECEIPT}`, 'auxclick (middle-click) is decorated exactly like a plain click');
  }

  // 7. No C2c handle, but a valid C2d continuity couple is present => used as
  // fallback source.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_continuity_v1_unlocker-web', continuityRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });
    const link = anchor('https://app.unlocker.io/login');
    dispatch(instance, 'click', link);
    check(link.href === `https://app.unlocker.io/login#ul_ch=${OTHER_HANDLE}.${OTHER_RECEIPT}`, 'falls back to the C2d continuity couple when no C2c handle is present');
  }

  // 7b. C2c handle takes priority over the C2d continuity couple when both exist.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    storage.setItem('unlkr_acquisition_continuity_v1_unlocker-web', continuityRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: granted });
    const link = anchor('https://app.unlocker.io/login');
    dispatch(instance, 'click', link);
    check(link.href === `https://app.unlocker.io/login#ul_ch=${HANDLE}.${RECEIPT}`, 'the C2c handle takes priority over the C2d continuity fallback when both are present');
  }

  // 8. No couple at all (neither C2c nor C2d) => href untouched.
  {
    const instance = run({ getCkyConsent: granted });
    const link = anchor('https://app.unlocker.io/login');
    dispatch(instance, 'click', link);
    check(link.href === 'https://app.unlocker.io/login', 'no C2c handle and no C2d continuity couple: href is left untouched');
  }

  // 9. The decorator meta flag disables the whole mechanism, even with
  // everything else valid.
  {
    const storage = memoryStorage();
    storage.setItem('unlkr_acquisition_touches_handle_v1_unlocker-web', handleRecord());
    const instance = run({ sessionStorage: storage, getCkyConsent: granted, attrs: { 'data-link-decorator-enabled': '0' } });
    check(!instance.documentListeners.click, 'link-decorator-enabled=0: no click listener is even registered');
    check(!instance.documentListeners.auxclick, 'link-decorator-enabled=0: no auxclick listener is even registered');
  }

  console.log(`OK - ${assertions} link decorator DOM assertions`);
})().catch(error => {
  console.error(error.message || error);
  process.exit(1);
});
