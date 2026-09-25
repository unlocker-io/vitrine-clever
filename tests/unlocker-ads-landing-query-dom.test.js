const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('web/app/mu-plugins/unlocker-landings/assets/ads-landing-query.js', 'utf8');

function check(condition, label) {
  if (!condition) throw new Error(`FAIL: ${label}`);
}

function anchor(href, marked) {
  return {
    href,
    hasAttribute: (name) => marked === true && name === 'data-ul-ads-cta',
  };
}

// Runs the real script against a fake document whose querySelectorAll only
// returns elements actually carrying the data-ul-ads-cta marker -- mirrors
// what a real `document.querySelectorAll('[data-ul-ads-cta]')` call would
// return, without a jsdom dependency.
function run(search, elements) {
  const window = {
    location: { search, href: 'https://unlocker.io/some-landing/' },
    URL,
    URLSearchParams,
  };
  const document = {
    querySelectorAll: (selector) => {
      if (selector !== '[data-ul-ads-cta]') return [];
      return elements.filter((el) => el.hasAttribute('data-ul-ads-cta'));
    },
  };

  vm.runInNewContext(source, { window, document, URL, URLSearchParams });
}

let assertions = 0;
function checked(condition, label) {
  assertions++;
  check(condition, label);
}

// -- tracked params propagate, offre/parcours preserved ----------------------

{
  const cta = anchor('https://unlocker.io/demarrer/?offre=delegation&parcours=demo', true);
  run('?gclid=abc123&utm_source=meta', [cta]);
  const url = new URL(cta.href);
  checked(url.searchParams.get('gclid') === 'abc123', 'gclid propagated with the exact incoming value');
  checked(url.searchParams.get('utm_source') === 'meta', 'utm_source propagated');
  checked(url.searchParams.get('offre') === 'delegation', 'offre is still present and unchanged');
  checked(url.searchParams.get('parcours') === 'demo', 'parcours is still present and unchanged');
}

// -- a non-whitelisted param is never propagated ------------------------------

{
  const cta = anchor('https://unlocker.io/demarrer/?offre=delegation&parcours=demo', true);
  run('?foo=bar', [cta]);
  const url = new URL(cta.href);
  checked(url.searchParams.get('foo') === null, 'a param outside the tracked whitelist (foo) is not propagated');
}

// -- zero tracked params: href left byte-identical ----------------------------

{
  const originalHref = 'https://unlocker.io/demarrer/?offre=delegation&parcours=demo';
  const cta = anchor(originalHref, true);
  run('', [cta]);
  checked(cta.href === originalHref, 'href is left BYTE-IDENTICAL when the incoming URL has zero tracked params');
}

// -- an anchor without the marker is never touched ----------------------------

{
  const originalHref = 'https://app.unlocker.io/login';
  const header = anchor(originalHref, false);
  run('?gclid=abc123&utm_source=meta', [header]);
  checked(header.href === originalHref, 'an anchor without data-ul-ads-cta (e.g. the header Connexion link) is never touched even with tracked params present');
}

// -- offre/parcours structurally never overwritten, even if present upstream -

{
  const cta = anchor('https://unlocker.io/demarrer/?offre=delegation&parcours=demo', true);
  run('?offre=carte-t&parcours=autre&gclid=xyz', [cta]);
  const url = new URL(cta.href);
  checked(url.searchParams.get('offre') === 'delegation', 'offre on the anchor is never overwritten by an incoming offre param');
  checked(url.searchParams.get('parcours') === 'demo', 'parcours on the anchor is never overwritten by an incoming parcours param');
  checked(url.searchParams.get('gclid') === 'xyz', 'gclid still propagates alongside the offre/parcours guard');
}

// -- from=<slug> (added server-side) survives tracked-param propagation ----

{
  const cta = anchor('https://unlocker.io/demarrer/?offre=carte-t&parcours=demo&from=carte-t', true);
  run('?gclid=abc123&utm_source=meta', [cta]);
  const url = new URL(cta.href);
  checked(url.searchParams.get('from') === 'carte-t', 'from=<slug> set server-side survives tracked-param propagation untouched');
  checked(url.searchParams.get('gclid') === 'abc123', 'gclid still propagates alongside from');
}

console.log(`OK - ${assertions} DOM assertions`);
