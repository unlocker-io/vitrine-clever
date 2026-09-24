const fs = require('fs');
const vm = require('vm');

const producer = fs.readFileSync('web/app/mu-plugins/unlkr-acquisition-continuity.js', 'utf8');
const HANDLE = `av1_${'A'.repeat(43)}`;
const RECEIPT = `acr1_${'B'.repeat(43)}`;
const REQUEST_ID = '22222222-2222-4222-8222-222222222222';
const IDENTIFY_ID = '11111111-1111-4111-8111-111111111111';

let assertions = 0;
function check(condition, label) {
  assertions++;
  if (!condition) throw new Error(`FAIL: ${label}`);
}

function memoryStorage() {
  const values = {};
  return {
    values,
    getItem: key => Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null,
    setItem: (key, value) => { values[key] = String(value); },
    removeItem: key => { delete values[key]; },
  };
}

function crmResponse(overrides = {}) {
  return {
    status: overrides.status || 201,
    json: async () => overrides.body || {
      visitor_handle: HANDLE,
      consent_receipt: RECEIPT,
      expires_at: new Date(Date.now() + 3600000).toISOString(),
    },
  };
}

function run({ sessionStorage = memoryStorage(), fetchImpl, attrs = {}, opener = null, referrer = '' } = {}) {
  const documentListeners = {};
  const windowListeners = {};
  const requests = [];
  const timers = [];
  const metaValues = Object.assign({
    'data-preferences-url': 'https://crm.example.test/acquisition-web/preferences',
    'data-site-key': 'unlocker-web',
    'data-notice-version': '2026-09',
    'data-app-origins': 'https://app.example.test,https://staging.example.test',
    'data-ttl-seconds': '300',
    'data-timeout-ms': '3000',
    'data-retries': '1',
  }, attrs);
  const document = {
    referrer,
    querySelector: () => ({ getAttribute: name => metaValues[name] || null }),
    addEventListener: (name, callback) => { documentListeners[name] = callback; },
  };
  const window = {
    location: { origin: 'https://public.example.test' },
    opener,
    sessionStorage,
    crypto: { randomUUID: () => IDENTIFY_ID, getRandomValues: () => {} },
    fetch: async (url, options) => {
      requests.push({ url, options });
      return fetchImpl ? fetchImpl(requests.length, url, options) : crmResponse();
    },
    AbortController: function () { this.signal = {}; this.abort = () => {}; },
    setTimeout: (callback, delay) => { timers.push({ callback, delay }); return timers.length; },
    clearTimeout: () => {},
    addEventListener: (name, callback) => { windowListeners[name] = callback; },
  };
  vm.runInNewContext(producer, { window, document, Uint8Array, Date, Number, Object, Array, JSON });
  return { documentListeners, windowListeners, requests, sessionStorage, timers };
}

async function settle() {
  await new Promise(resolve => setImmediate(resolve));
  await new Promise(resolve => setImmediate(resolve));
}

function grant(instance) {
  instance.documentListeners.cookieyes_banner_load({ detail: { isUserActionCompleted: true, categories: { advertisement: true } } });
}

function update(instance, accepted, rejected) {
  instance.documentListeners.cookieyes_consent_update({ detail: { accepted, rejected } });
}

function continuityKey() {
  return 'unlkr_acquisition_continuity_v1_unlocker-web';
}

function sendRequest(instance, origin = 'https://app.example.test') {
  const sent = [];
  instance.windowListeners.message({
    origin,
    data: { type: 'unlkr:continuity:request', schema_version: 1, request_id: REQUEST_ID },
    source: { postMessage: (body, target) => sent.push({ body, target }) },
  });
  return sent;
}

(async () => {
  const gated = run();
  check(gated.requests.length === 0 && !gated.sessionStorage.values[continuityKey()], 'unknown consent neither contacts CRM nor stores continuity');
  gated.documentListeners.cookieyes_banner_load({ detail: { isUserActionCompleted: false, categories: { advertisement: true } } });
  check(gated.requests.length === 0, 'preconfigured category without completed CookieYes action remains unknown');
  update(gated, [], ['advertisement']);
  await settle();
  check(gated.requests.length === 0, 'denied consent never contacts CRM');

  const granted = run();
  grant(granted);
  await settle();
  check(granted.requests.length === 1, 'explicit CookieYes advertisement consent creates one CRM preference');
  const requestBody = JSON.parse(granted.requests[0].options.body);
  check(requestBody.schema_version === 1 && requestBody.preferences.ads_measurement === 'granted' && requestBody.preferences.ads_sharing === 'granted', 'CRM request follows C2b preference schema');
  check(granted.requests[0].options.credentials === 'omit' && !JSON.stringify(granted.requests[0]).match(/Bearer|email|access.token|cookie/i), 'browser request contains no bearer, cookie instruction or PII');
  check(!/dataLayer|document\.cookie|localStorage|X-Unlocker-Access-Token|Authorization/.test(producer), 'producer has no public cookie, dataLayer, product token or persistent local storage channel');
  const stored = JSON.parse(granted.sessionStorage.values[continuityKey()]);
  check(stored.payload.identify_attempt_id === IDENTIFY_ID && stored.expires_at <= Date.now() + 300000, 'stored payload has stable UUID and locally bounded TTL');
  check(Object.keys(stored.payload).sort().join(',') === 'consent_receipt,identify_attempt_id,schema_version,site_key,visitor_handle', 'transport payload contains only the five pseudonymous contract fields');

  const clonedStorage = memoryStorage();
  clonedStorage.values[continuityKey()] = JSON.stringify(stored);
  const cloned = run({
    sessionStorage: clonedStorage,
    opener: { location: { origin: 'https://public.example.test' } },
    referrer: 'https://public.example.test/pricing',
  });
  check(!clonedStorage.values[continuityKey()] && !cloned.windowListeners.message && cloned.requests.length === 0, 'same-origin opener clone destroys inherited session continuity and cannot deliver twice');
  grant(granted);
  await settle();
  check(granted.requests.length === 1, 'duplicate consent event reuses the stable unconsumed payload');

  const delivered = sendRequest(granted);
  check(delivered.length === 1 && delivered[0].target === 'https://app.example.test' && delivered[0].body.payload.identify_attempt_id === IDENTIFY_ID, 'exact-origin handshake returns the versioned payload');
  check(!granted.sessionStorage.values[continuityKey()] && sendRequest(granted).length === 0, 'payload is consumed exactly once');
  grant(granted);
  await settle();
  check(granted.requests.length === 1, 'duplicate consent event cannot mint a replacement after single-use consumption');

  const badOrigin = run();
  grant(badOrigin);
  await settle();
  check(sendRequest(badOrigin, 'https://evil.example').length === 0 && !badOrigin.sessionStorage.values[continuityKey()], 'invalid origin destroys continuity without disclosure');

  const expired = run();
  grant(expired);
  await settle();
  const expiredRecord = JSON.parse(expired.sessionStorage.values[continuityKey()]);
  expiredRecord.expires_at = Date.now() - 1;
  expired.sessionStorage.values[continuityKey()] = JSON.stringify(expiredRecord);
  check(sendRequest(expired).length === 0 && !expired.sessionStorage.values[continuityKey()], 'expired payload is removed and unusable');

  const invalid = run({ fetchImpl: async () => crmResponse({ body: { visitor_handle: 'forged', consent_receipt: RECEIPT, expires_at: new Date(Date.now() + 60000).toISOString() } }) });
  grant(invalid);
  await settle();
  check(!invalid.sessionStorage.values[continuityKey()], 'invalid or absent CRM identity is never stored');

  const retry = run({ fetchImpl: async call => { if (call < 2) throw new Error('network'); return crmResponse(); } });
  grant(retry);
  await settle();
  check(retry.requests.length === 2 && retry.sessionStorage.values[continuityKey()], 'transient CRM failure gets one bounded non-blocking retry');
  const exhausted = run({ fetchImpl: async () => { throw new Error('offline'); } });
  grant(exhausted);
  await settle();
  check(exhausted.requests.length === 2 && !exhausted.sessionStorage.values[continuityKey()], 'persistent CRM failure stops after the configured retry bound');

  const sharedA = memoryStorage();
  const sharedB = memoryStorage();
  const firstProfile = run({ sessionStorage: sharedA });
  grant(firstProfile);
  await settle();
  const secondProfile = run({ sessionStorage: sharedB });
  check(!secondProfile.sessionStorage.values[continuityKey()] && sendRequest(secondProfile).length === 0, 'shared browser tab/session boundary does not leak continuity to another context');
  update(firstProfile, [], ['advertisement']);
  await settle();
  check(!firstProfile.sessionStorage.values[continuityKey()] && firstProfile.requests.length === 2, 'withdrawal clears locally and sends one best-effort revoked preference');
  const revokedBody = JSON.parse(firstProfile.requests[1].options.body);
  check(revokedBody.preferences.ads_measurement === 'revoked' && revokedBody.visitor_handle === HANDLE && revokedBody.consent_receipt === RECEIPT, 'withdrawal invalidates the exact CRM receipt without identity data');

  let resolveLate;
  const disordered = run({ fetchImpl: () => new Promise(resolve => { resolveLate = resolve; }) });
  grant(disordered);
  update(disordered, [], ['advertisement']);
  resolveLate(crmResponse());
  await settle();
  check(!disordered.sessionStorage.values[continuityKey()], 'late granted response cannot resurrect continuity after withdrawal');

  console.log(`OK - ${assertions} continuity DOM assertions`);
})().catch(error => {
  console.error(error.message || error);
  process.exit(1);
});
