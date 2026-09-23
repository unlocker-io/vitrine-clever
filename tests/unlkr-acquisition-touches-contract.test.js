const fs = require('fs');
const vm = require('vm');

// Fragment copied from unlocker-rh docs/api/crm-acquisition-web-v1.json, commit
// 3af4c44510e38ee6f6e899a22b4cd10402c6289a, verified current as of origin/main
// 45d4ad7ebfdeea8d663c7851bb20adb9b7327c96 (2026-09-23). Re-copy if the CRM
// contract changes.
const CRM_SCHEMAS = {
  $comment: 'Fragment copied from unlocker-rh docs/api/crm-acquisition-web-v1.json, commit 3af4c44510e38ee6f6e899a22b4cd10402c6289a, verified current as of origin/main 45d4ad7ebfdeea8d663c7851bb20adb9b7327c96 (2026-09-23). Re-copy if the CRM contract changes.',
  TouchBatchInput: {
    type: 'object',
    additionalProperties: false,
    required: ['schema_version', 'site_key', 'visitor_handle', 'consent_receipt', 'touches'],
    properties: {
      schema_version: { type: 'integer', const: 1 },
      site_key: { type: 'string', maxLength: 64 },
      visitor_handle: { type: 'string', pattern: '^av1_[A-Za-z0-9_-]{43}$' },
      consent_receipt: { type: 'string', pattern: '^acr1_[A-Za-z0-9_-]{43}$' },
      touches: {
        type: 'array',
        minItems: 1,
        maxItems: 10,
        items: { $ref: '#/components/schemas/VisitorTouchInput' },
      },
    },
  },
  VisitorTouchInput: {
    type: 'object',
    additionalProperties: false,
    required: ['external_touch_id', 'landing_key', 'occurred_at'],
    properties: {
      external_touch_id: { type: 'string', maxLength: 128 },
      landing_key: { type: 'string', maxLength: 64 },
      occurred_at: { type: 'string', format: 'date-time' },
      campaign_parameters: {
        type: 'object',
        additionalProperties: false,
        properties: {
          utm_source: { type: ['string', 'null'], maxLength: 256 },
          utm_medium: { type: ['string', 'null'], maxLength: 256 },
          utm_campaign: { type: ['string', 'null'], maxLength: 256 },
          utm_content: { type: ['string', 'null'], maxLength: 256 },
          utm_term: { type: ['string', 'null'], maxLength: 256 },
          ad_external_id: { type: ['string', 'null'], maxLength: 256 },
          campaign_external_id: { type: ['string', 'null'], maxLength: 120 },
        },
      },
      click_ids: {
        type: 'object',
        additionalProperties: false,
        properties: {
          fbclid: { type: 'string', maxLength: 256 },
          gclid: { type: 'string', maxLength: 256 },
          gbraid: { type: 'string', maxLength: 256 },
          wbraid: { type: 'string', maxLength: 256 },
        },
      },
    },
  },
};

// Small hand-rolled recursive JSON-Schema-subset validator. Supports exactly
// the keywords this fragment uses: type (incl. ["string","null"] unions),
// const, pattern, maxLength, minItems, maxItems, required,
// additionalProperties:false, properties, items and a single-level $ref to a
// sibling schema in the same fragment. Returns a list of violation strings;
// an empty list means the value is valid.
function validate(schema, value, path, schemas) {
  const violations = [];

  if (schema.$ref) {
    const match = /^#\/components\/schemas\/(.+)$/.exec(schema.$ref);
    if (!match || !schemas[match[1]]) {
      violations.push(`${path}: unresolved $ref ${schema.$ref}`);
      return violations;
    }
    return validate(schemas[match[1]], value, path, schemas);
  }

  if (schema.type) {
    const types = Array.isArray(schema.type) ? schema.type : [schema.type];
    const actualType = value === null ? 'null' : Array.isArray(value) ? 'array' : typeof value;
    const typeMatches = types.some(candidate => (candidate === 'integer' ? Number.isInteger(value) : candidate === actualType));
    if (!typeMatches) {
      violations.push(`${path}: expected type ${types.join('|')}, got ${actualType}`);
      return violations;
    }
  }

  if (Object.prototype.hasOwnProperty.call(schema, 'const') && value !== schema.const) {
    violations.push(`${path}: expected const ${JSON.stringify(schema.const)}, got ${JSON.stringify(value)}`);
  }

  if (typeof value === 'string') {
    if (schema.pattern && !(new RegExp(schema.pattern)).test(value)) {
      violations.push(`${path}: "${value}" does not match pattern ${schema.pattern}`);
    }
    if (typeof schema.maxLength === 'number' && value.length > schema.maxLength) {
      violations.push(`${path}: length ${value.length} exceeds maxLength ${schema.maxLength}`);
    }
  }

  if (Array.isArray(value)) {
    if (typeof schema.minItems === 'number' && value.length < schema.minItems) {
      violations.push(`${path}: ${value.length} items is fewer than minItems ${schema.minItems}`);
    }
    if (typeof schema.maxItems === 'number' && value.length > schema.maxItems) {
      violations.push(`${path}: ${value.length} items exceeds maxItems ${schema.maxItems}`);
    }
    if (schema.items) {
      value.forEach((item, index) => {
        violations.push(...validate(schema.items, item, `${path}[${index}]`, schemas));
      });
    }
  }

  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    if (Array.isArray(schema.required)) {
      schema.required.forEach(key => {
        if (!(key in value)) {
          violations.push(`${path}: missing required property "${key}"`);
        }
      });
    }
    const properties = schema.properties || {};
    Object.keys(value).forEach(key => {
      if (Object.prototype.hasOwnProperty.call(properties, key)) {
        violations.push(...validate(properties[key], value[key], `${path}.${key}`, schemas));
      } else if (schema.additionalProperties === false) {
        violations.push(`${path}: unexpected additional property "${key}"`);
      }
    });
  }

  return violations;
}

let assertions = 0;
function check(condition, label) {
  assertions++;
  if (!condition) throw new Error(`FAIL: ${label}`);
}

// --- Validator self-check: prove it is not trivially returning [] always. ---
const validExample = {
  schema_version: 1,
  site_key: 'unlocker-web',
  visitor_handle: `av1_${'A'.repeat(43)}`,
  consent_receipt: `acr1_${'B'.repeat(43)}`,
  touches: [{ external_touch_id: 'awt1_x', landing_key: 'vitrine_web', occurred_at: new Date().toISOString() }],
};
check(validate(CRM_SCHEMAS.TouchBatchInput, validExample, '$', CRM_SCHEMAS).length === 0, 'a hand-built valid example produces zero violations');

const invalidExample = Object.assign({}, validExample, { unexpected_top_level_field: 'nope' });
const invalidViolations = validate(CRM_SCHEMAS.TouchBatchInput, invalidExample, '$', CRM_SCHEMAS);
check(invalidViolations.length > 0, 'a deliberately invalid payload (unknown top-level property) is reported by the validator');

// --- Duplicated minimal DOM harness (kept independent from the DOM test file
// on purpose, matching this repo's existing style of fully self-contained
// test files). ---
const producer = fs.readFileSync('web/app/mu-plugins/unlkr-acquisition-touches.js', 'utf8');
const HANDLE = `av1_${'A'.repeat(43)}`;
const RECEIPT = `acr1_${'B'.repeat(43)}`;

function memoryStorage() {
  const values = {};
  return {
    values,
    getItem: key => Object.prototype.hasOwnProperty.call(values, key) ? values[key] : null,
    setItem: (key, value) => { values[key] = String(value); },
    removeItem: key => { delete values[key]; },
  };
}

function defaultFetch(callIndex, url) {
  if (url.indexOf('/acquisition-web/preferences') !== -1) {
    return {
      status: 201,
      json: async () => ({
        visitor_handle: HANDLE,
        consent_receipt: RECEIPT,
        expires_at: new Date(Date.now() + 3600000).toISOString(),
      }),
    };
  }
  return { status: 202, json: async () => ({}) };
}

function run(search) {
  const documentListeners = {};
  const requests = [];
  const metaValues = {
    'data-preferences-url': 'https://crm.example.test/acquisition-web/preferences',
    'data-touches-url': 'https://crm.example.test/acquisition-web/touches',
    'data-site-key': 'unlocker-web',
    'data-notice-version': '2026-09',
    'data-landing-key': 'vitrine_web',
    'data-ttl-seconds': '300',
    'data-timeout-ms': '3000',
    'data-retries': '1',
  };
  const document = {
    querySelector: () => ({ getAttribute: name => (name in metaValues ? metaValues[name] : null) }),
    addEventListener: (name, callback) => { documentListeners[name] = callback; },
  };
  const window = {
    location: { search },
    sessionStorage: memoryStorage(),
    crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111', getRandomValues: () => {} },
    fetch: async (url, options) => {
      requests.push({ url, options });
      return defaultFetch(requests.length, url);
    },
    AbortController: function () { this.signal = {}; this.abort = () => {}; },
    setTimeout: () => 1,
    clearTimeout: () => {},
  };
  vm.runInNewContext(producer, { window, document, Uint8Array, Date, Number, Object, Array, JSON, decodeURIComponent });
  return { documentListeners, requests };
}

async function settle() {
  for (let round = 0; round < 6; round++) {
    await new Promise(resolve => setImmediate(resolve));
  }
}

(async () => {
  // Real Brevo campaign link fixture from the ticket (unaccented encoding).
  const instance = run('?utm_source=sendinblue&utm_medium=email&utm_campaign=Prsente%20dlgation%20Carte%20G&utm_id=201');
  instance.documentListeners.cookieyes_banner_load({ detail: { isUserActionCompleted: true, categories: { advertisement: true } } });
  await settle();

  const touchesRequest = instance.requests.find(request => request.url.indexOf('/acquisition-web/touches') !== -1);
  check(!!touchesRequest, 'the Brevo fixture reached the touches endpoint');
  const body = JSON.parse(touchesRequest.options.body);

  const violations = validate(CRM_SCHEMAS.TouchBatchInput, body, '$', CRM_SCHEMAS);
  check(violations.length === 0, `real producer payload validates against the CRM contract fragment (violations: ${JSON.stringify(violations)})`);

  console.log(`OK - ${assertions} touches contract assertions`);
})().catch(error => {
  console.error(error.message || error);
  process.exit(1);
});
