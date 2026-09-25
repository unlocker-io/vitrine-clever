const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('web/app/mu-plugins/unlocker-landings/assets/flow.js', 'utf8');

let assertions = 0;
function check(condition, label) {
  assertions++;
  if (!condition) throw new Error(`FAIL: ${label}`);
}

async function settle() {
  for (let round = 0; round < 6; round++) {
    await new Promise(resolve => setImmediate(resolve));
  }
}

function el(overrides) {
  return Object.assign({ textContent: '', value: '', href: '', hidden: false, disabled: false, addEventListener() {}, focus() {} }, overrides);
}

function buildInstance({ search = '', fields = {}, fetchImpl } = {}) {
  const offerNodes = [el(), el()];
  const submitButton = el();
  const form = {
    hidden: false,
    listeners: {},
    addEventListener(type, callback) { this.listeners[type] = callback; },
    reportValidity: () => true,
    querySelector(selector) {
      if (selector === 'button[type="submit"]') return submitButton;
      if (selector === 'input') return el();
      return null;
    },
  };
  const elements = {
    '#ul-lead-form': form,
    '#ul-back': el(),
    '#ul-intent': el(),
    '#ul-form-error': el(),
    '#ul-website': el({ value: fields.website || '' }),
    '#ul-email': el({ value: fields.email || '' }),
    '#ul-company': el({ value: fields.company || '' }),
    '#ul-country': el({ value: fields.country || '' }),
    '#ul-area': el({ value: fields.area || '' }),
    '#ul-size': el({ value: fields.size || '' }),
    '#ul-phone': el({ value: fields.phone || '' }),
    '#ul-next': el(),
    '#ul-next-title': el(),
    '#ul-edit': el(),
    '#ul-next-intro': el(),
  };
  const document = {
    cookie: '',
    querySelector(selector) { return elements[selector] || null; },
    querySelectorAll(selector) {
      if (selector === '[data-ul-offer]') return offerNodes;
      return [];
    },
  };
  const sessionStorageValues = {};
  const sessionStorage = {
    getItem: key => Object.prototype.hasOwnProperty.call(sessionStorageValues, key) ? sessionStorageValues[key] : null,
    setItem: (key, value) => { sessionStorageValues[key] = String(value); },
  };
  const requests = [];
  const fetchMock = (url, options) => {
    requests.push({ url, options });
    return fetchImpl ? fetchImpl(url, options) : Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true }) });
  };
  const gtagCalls = [];
  const gtagMock = (...args) => { gtagCalls.push(args); };
  const window = {
    location: { search, href: `https://example.test/demarrer/${search}` },
    sessionStorage,
    unlockerLanding: { endpoint: 'https://example.test/api/lead', siteKey: 'test-site', delegation: '/delegation-page', split: '/split-page' },
    dataLayer: [],
  };
  const context = { window, document, fetch: fetchMock, URL, URLSearchParams, gtag: gtagMock };
  vm.runInNewContext(source, context);
  return { window, document, form, submitButton, offerNodes, elements, requests, gtagCalls };
}

function submit(instance) {
  const handler = instance.form.listeners.submit;
  if (typeof handler !== 'function') throw new Error('submit listener was not registered');
  handler({ preventDefault() {} });
}

(async () => {
  // 1. carte-g-t: fine-grained offer flows to submitted body, dataLayer and gtag.
  const cgt = buildInstance({ search: '?offre=carte-g-t&parcours=demo', fields: { email: 'a@b.test' } });
  submit(cgt);
  await settle();
  check(cgt.requests.length === 1, 'carte-g-t: exactly one fetch call on submit');
  const cgtBody = JSON.parse(cgt.requests[0].options.body);
  check(cgtBody.offer === 'carte-g-t', 'carte-g-t: submitted body carries the fine-grained offer, not delegation_g/split');
  check(cgt.window.dataLayer.some(entry => entry.event === 'unlocker_lead' && entry.offer === 'carte-g-t' && entry.parcours === 'demo'), 'carte-g-t: dataLayer receives the fine-grained offer after a successful submit');
  check(cgt.gtagCalls.some(args => args[0] === 'event' && args[1] === 'generate_lead' && args[2].offer === 'carte-g-t' && args[2].parcours === 'demo'), 'carte-g-t: gtag generate_lead receives the fine-grained offer');

  // 2. carte-t: same shape.
  const ct = buildInstance({ search: '?offre=carte-t&parcours=demo' });
  submit(ct);
  await settle();
  const ctBody = JSON.parse(ct.requests[0].options.body);
  check(ctBody.offer === 'carte-t', 'carte-t: submitted body carries the fine-grained offer');
  check(ct.window.dataLayer.some(entry => entry.event === 'unlocker_lead' && entry.offer === 'carte-t' && entry.parcours === 'demo'), 'carte-t: dataLayer receives the fine-grained offer');
  check(ct.gtagCalls.some(args => args[0] === 'event' && args[1] === 'generate_lead' && args[2].offer === 'carte-t' && args[2].parcours === 'demo'), 'carte-t: gtag generate_lead receives the fine-grained offer');

  // 3. delegation: regression, unchanged.
  const delegation = buildInstance({ search: '?offre=delegation&parcours=demo' });
  submit(delegation);
  await settle();
  const delegationBody = JSON.parse(delegation.requests[0].options.body);
  check(delegationBody.offer === 'delegation', 'delegation: still resolves to delegation (regression)');

  // 4. absent/unrecognized offre => split fallback (regression, now through resolveOffer).
  const absent = buildInstance({ search: '?parcours=demo' });
  submit(absent);
  await settle();
  check(JSON.parse(absent.requests[0].options.body).offer === 'split', 'absent offre falls back to split');

  const bogus = buildInstance({ search: '?offre=bogus&parcours=demo' });
  submit(bogus);
  await settle();
  check(JSON.parse(bogus.requests[0].options.body).offer === 'split', 'unrecognized offre value falls back to split');

  // 5. attribution whitelist extension reaches the actual POST body.
  const withClickIds = buildInstance({ search: '?offre=carte-g-t&gclid=abc&gbraid=xyz&wbraid=uvw' });
  submit(withClickIds);
  await settle();
  const clickIdsBody = JSON.parse(withClickIds.requests[0].options.body);
  check(clickIdsBody.gclid === 'abc' && clickIdsBody.gbraid === 'xyz' && clickIdsBody.wbraid === 'uvw', 'gclid/gbraid/wbraid all reach the submitted body');

  // 6. absence of gbraid/wbraid: keys are entirely absent, not present as empty strings.
  const withoutClickIds = buildInstance({ search: '?offre=carte-g-t' });
  submit(withoutClickIds);
  await settle();
  const withoutClickIdsBody = JSON.parse(withoutClickIds.requests[0].options.body);
  check(!('gbraid' in withoutClickIdsBody) && !('wbraid' in withoutClickIdsBody), 'gbraid/wbraid are absent from the body when absent from the URL');

  console.log(`OK - ${assertions} flow offer DOM assertions`);
})().catch(error => {
  console.error(error.message || error);
  process.exit(1);
});
