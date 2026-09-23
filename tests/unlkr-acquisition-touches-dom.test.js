const fs = require('fs');
const vm = require('vm');

const producer = fs.readFileSync('web/app/mu-plugins/unlkr-acquisition-touches.js', 'utf8');
const HANDLE = `av1_${'A'.repeat(43)}`;
const RECEIPT = `acr1_${'B'.repeat(43)}`;

let assertions = 0;
function check(condition, label) {
  assertions++;
  if (!condition) throw new Error(`FAIL: ${label}`);
}

let uuidCounter = 0;
function nextUuid() {
  uuidCounter++;
  return `11111111-1111-4111-8111-${String(uuidCounter).padStart(12, '0')}`;
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

function preferencesResponse(overrides = {}) {
  return {
    status: overrides.status || 201,
    json: async () => overrides.body || {
      visitor_handle: HANDLE,
      consent_receipt: RECEIPT,
      expires_at: new Date(Date.now() + 3600000).toISOString(),
    },
  };
}

function touchesResponse(overrides = {}) {
  return {
    status: overrides.status || 202,
    json: async () => overrides.body || {},
  };
}

function defaultFetch(callIndex, url) {
  return url.indexOf('/acquisition-web/preferences') !== -1 ? preferencesResponse() : touchesResponse();
}

function run({ sessionStorage = memoryStorage(), search = '', attrs = {}, fetchImpl } = {}) {
  const documentListeners = {};
  const requests = [];
  const timers = [];
  const metaValues = Object.assign({
    'data-preferences-url': 'https://crm.example.test/acquisition-web/preferences',
    'data-touches-url': 'https://crm.example.test/acquisition-web/touches',
    'data-site-key': 'unlocker-web',
    'data-notice-version': '2026-09',
    'data-landing-key': 'vitrine_web',
    'data-ttl-seconds': '300',
    'data-timeout-ms': '3000',
    'data-retries': '1',
  }, attrs);
  const document = {
    querySelector: () => ({ getAttribute: name => (name in metaValues ? metaValues[name] : null) }),
    addEventListener: (name, callback) => { documentListeners[name] = callback; },
  };
  const window = {
    location: { search },
    sessionStorage,
    crypto: { randomUUID: nextUuid, getRandomValues: () => {} },
    fetch: async (url, options) => {
      requests.push({ url, options });
      return fetchImpl ? fetchImpl(requests.length, url, options) : defaultFetch(requests.length, url);
    },
    AbortController: function () { this.signal = {}; this.abort = () => {}; },
    setTimeout: (callback, delay) => { timers.push({ callback, delay }); return timers.length; },
    clearTimeout: () => {},
  };
  vm.runInNewContext(producer, { window, document, Uint8Array, Date, Number, Object, Array, JSON, decodeURIComponent });
  return { documentListeners, requests, sessionStorage, timers, window };
}

async function settle() {
  for (let round = 0; round < 6; round++) {
    await new Promise(resolve => setImmediate(resolve));
  }
}

function grant(instance) {
  if (typeof instance.documentListeners.cookieyes_banner_load !== 'function') {
    throw new Error('cookieyes_banner_load listener was not registered');
  }
  instance.documentListeners.cookieyes_banner_load({ detail: { isUserActionCompleted: true, categories: { advertisement: true } } });
}

function reject(instance) {
  instance.documentListeners.cookieyes_consent_update({ detail: { accepted: [], rejected: ['advertisement'] } });
}

function arrivalKey() {
  return 'unlkr_acquisition_touches_arrival_v1_unlocker-web';
}

function handleKey() {
  return 'unlkr_acquisition_touches_handle_v1_unlocker-web';
}

(async () => {
  // 1. No consent => no send, even with recognized campaign parameters.
  const noConsent = run({ search: '?utm_source=a&utm_medium=b&utm_campaign=c' });
  await settle();
  check(noConsent.requests.length === 0, 'no consent granted: zero requests despite recognized UTM parameters');
  // An explicit denial/unknown consent event must never let produce() run.
  noConsent.documentListeners.cookieyes_banner_load({ detail: { isUserActionCompleted: true, categories: { advertisement: false } } });
  await settle();
  check(noConsent.requests.length === 0, 'explicit denial event still sends zero requests');
  reject(noConsent);
  await settle();
  check(noConsent.requests.length === 0, 'explicit revocation event still sends zero requests');

  // 2. No campaign params at all => the producer bails before wiring any listener.
  const noSignal = run({ search: '?ref=newsletter&page=2' });
  await settle();
  check(!noSignal.documentListeners.cookieyes_banner_load, 'absence of any recognized signal registers no consent listener at all');
  check(noSignal.requests.length === 0, 'absence of any recognized signal sends nothing even conceptually after consent');

  // 3. Consent + UTM => exact payload, preferences then touches, no click_ids/campaign_external_id.
  const basic = run({ search: '?utm_source=google&utm_medium=cpc&utm_campaign=test' });
  grant(basic);
  await settle();
  check(basic.requests.length === 2, 'granted consent with UTM parameters sends exactly two requests');
  check(basic.requests[0].url.indexOf('/acquisition-web/preferences') !== -1, 'first request mints a handle/receipt via preferences');
  check(basic.requests[1].url.indexOf('/acquisition-web/touches') !== -1, 'second request delivers the touch');
  const basicBody = JSON.parse(basic.requests[1].options.body);
  check(basicBody.schema_version === 1 && basicBody.site_key === 'unlocker-web'
    && basicBody.visitor_handle === HANDLE && basicBody.consent_receipt === RECEIPT, 'touch envelope carries schema_version, site_key and the minted identity');
  check(basicBody.touches.length === 1, 'exactly one touch per delivery');
  const basicTouch = basicBody.touches[0];
  check(basicTouch.landing_key === 'vitrine_web', 'touch carries the fixed server-configured landing key');
  check(!('click_ids' in basicTouch), 'no click_ids key when no click id was present on the query string');
  check(Object.keys(basicTouch.campaign_parameters).sort().join(',') === 'utm_campaign,utm_medium,utm_source', 'campaign_parameters contains exactly the recognized UTM keys');
  check(basicTouch.campaign_parameters.utm_source === 'google' && basicTouch.campaign_parameters.utm_medium === 'cpc' && basicTouch.campaign_parameters.utm_campaign === 'test', 'UTM values are mapped 1:1');
  check(!('campaign_external_id' in basicTouch.campaign_parameters), 'no campaign_external_id when utm_id is absent');
  const occurredAt = Date.parse(basicTouch.occurred_at);
  check(Number.isFinite(occurredAt) && Math.abs(Date.now() - occurredAt) < 60000, 'occurred_at is a valid recent ISO date');
  check(basic.requests.every(request => request.options.credentials === 'omit'), 'every fetch omits credentials');

  // 4. utm_id maps to campaign_external_id and never leaks the raw key.
  const withUtmId = run({ search: '?utm_source=x&utm_medium=y&utm_id=42' });
  grant(withUtmId);
  await settle();
  const utmIdTouch = JSON.parse(withUtmId.requests[1].options.body).touches[0];
  check(utmIdTouch.campaign_parameters.campaign_external_id === '42', 'utm_id is mapped into campaign_parameters.campaign_external_id');
  check(JSON.stringify(withUtmId.requests[1].options.body).indexOf('utm_id') === -1, 'the raw utm_id key never appears in the sent body');

  // 5. Real Brevo campaign link (ticket fixture, unaccented encoding).
  const brevo = run({ search: '?utm_source=sendinblue&utm_medium=email&utm_campaign=Prsente%20dlgation%20Carte%20G&utm_id=201' });
  grant(brevo);
  await settle();
  const brevoTouch = JSON.parse(brevo.requests[1].options.body).touches[0];
  check(brevoTouch.campaign_parameters.utm_source === 'sendinblue'
    && brevoTouch.campaign_parameters.utm_medium === 'email'
    && brevoTouch.campaign_parameters.utm_campaign === 'Prsente dlgation Carte G'
    && brevoTouch.campaign_parameters.campaign_external_id === '201', 'real Brevo landing link decodes to the exact expected campaign parameters');

  // 6. Truncation to the contract's maxLength bounds.
  const longCampaign = 'x'.repeat(300);
  const longUtmId = '9'.repeat(200);
  const truncation = run({ search: `?utm_source=a&utm_medium=b&utm_campaign=${longCampaign}&utm_id=${longUtmId}` });
  grant(truncation);
  await settle();
  const truncatedTouch = JSON.parse(truncation.requests[1].options.body).touches[0];
  check(truncatedTouch.campaign_parameters.utm_campaign.length === 256, 'utm_campaign is truncated to exactly 256 characters');
  check(truncatedTouch.campaign_parameters.campaign_external_id.length === 120, 'utm_id is truncated to exactly 120 characters in campaign_external_id');

  // 7. Stable external_touch_id across a reload of the very same arrival.
  const sharedStorage = memoryStorage();
  const firstLoad = run({ sessionStorage: sharedStorage, search: '?utm_source=a&utm_medium=b' });
  grant(firstLoad);
  await settle();
  check(firstLoad.requests.length === 2, 'first load of a new arrival mints a handle and sends one touch');
  const firstTouchId = JSON.parse(firstLoad.requests[1].options.body).touches[0].external_touch_id;
  const reload = run({ sessionStorage: sharedStorage, search: '?utm_source=a&utm_medium=b' });
  grant(reload);
  await settle();
  check(reload.requests.length === 0, 'reloading the exact same arrival triggers zero new fetch calls (fast dedup path)');
  const arrivalAfterReload = JSON.parse(sharedStorage.values[arrivalKey()]);
  check(arrivalAfterReload.external_touch_id === firstTouchId && arrivalAfterReload.sent === true, 'the stored arrival record keeps the same external_touch_id and stays marked sent');

  // 8. A different arrival on the same shared storage gets a fresh id, and reuses the cached handle.
  const newArrival = run({ sessionStorage: sharedStorage, search: '?utm_source=c&utm_medium=d' });
  grant(newArrival);
  await settle();
  check(newArrival.requests.length === 1 && newArrival.requests[0].url.indexOf('/acquisition-web/touches') !== -1, 'a new arrival reuses the cached handle: only the touches request is sent');
  const newTouchId = JSON.parse(newArrival.requests[0].options.body).touches[0].external_touch_id;
  check(newTouchId !== firstTouchId, 'a different arrival mints a different external_touch_id');
  check(sharedStorage.values[handleKey()] !== undefined, 'the handle/receipt cache is still present and was not re-minted');

  // 9. A silent, total network failure never throws and never marks the arrival as sent.
  const failing = run({ search: '?utm_source=a&utm_medium=b', fetchImpl: async () => { throw new Error('network down'); } });
  grant(failing);
  await settle();
  const failedArrival = JSON.parse(failing.sessionStorage.values[arrivalKey()]);
  check(failedArrival.sent === false, 'a total network failure leaves the arrival record unsent so a later reload can retry');

  // 10. No PII, bearer or cookie/dataLayer channel anywhere in the producer source.
  check(!/dataLayer|document\.cookie|X-Unlocker-Access-Token|Authorization/i.test(producer), 'producer source has no bearer, cookie or dataLayer channel');
  check(!/console\.(log|warn|error)/.test(producer), 'producer never logs to the console');

  console.log(`OK - ${assertions} touches DOM assertions`);
})().catch(error => {
  console.error(error.message || error);
  process.exit(1);
});
