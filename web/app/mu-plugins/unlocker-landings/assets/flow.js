'use strict';
function getCampaignContext(search) {
  const params = new URLSearchParams(search);
  const attribution = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'fbclid', 'gclid'].forEach(key => {
    if (params.has(key)) attribution[key] = params.get(key).slice(0, 200);
  });
  return { offer: params.get('offre') === 'delegation' ? 'delegation' : 'split', demo: params.get('parcours') === 'demo', attribution };
}
// Remembers the ISO timestamp of the FIRST arrival on any of the three
// landing pages for the current browser session, so /demarrer/ can report
// it even when the visitor reached it through split/delegation first.
// sessionStorage can legitimately throw (private mode, blocked storage);
// falling back to "now" just means no attribution window is lost, only
// its exact start.
function readOrStoreLandedAt() {
  var key = 'ul_landed_at';
  try {
    var stored = window.sessionStorage.getItem(key);
    if (stored) return stored;
    var now = new Date().toISOString();
    window.sessionStorage.setItem(key, now);
    return now;
  } catch (e) {
    return new Date().toISOString();
  }
}
// Reads CookieYes' own cookie directly (format:
// "consentid:…,consent:yes,action:yes,necessary:yes,functional:no,
// analytics:no,performance:no,advertisement:yes,other:no") rather than its
// JS API, since this only needs a one-off snapshot at submit time, not a
// live subscription to consent changes.
function readConsentAdsFromCookie() {
  try {
    var match = document.cookie.match(/(?:^|;\s*)cookieyes-consent=([^;]*)/);
    if (!match) return 'unknown';
    var pairs = decodeURIComponent(match[1]).split(',');
    for (var i = 0; i < pairs.length; i++) {
      var pair = pairs[i].split(':');
      if (pair[0] === 'advertisement') {
        if (pair[1] === 'yes') return 'granted';
        if (pair[1] === 'no') return 'denied';
        return 'unknown';
      }
    }
    return 'unknown';
  } catch (e) {
    return 'unknown';
  }
}
function trackLeadConversion(offer, parcours) {
  try {
    if (typeof fbq === 'function') fbq('track', 'Lead', { content_name: offer });
  } catch (e) { /* conversion pixels are best-effort */ }
  try {
    if (typeof gtag === 'function') gtag('event', 'generate_lead', { offer: offer, parcours: parcours });
  } catch (e) { /* conversion pixels are best-effort */ }
  try {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event: 'unlocker_lead', offer: offer, parcours: parcours });
  } catch (e) { /* conversion pixels are best-effort */ }
}
if (typeof document !== 'undefined') {
  readOrStoreLandedAt();
  const campaign = getCampaignContext(window.location.search);
  const config = (typeof window !== 'undefined' && window.unlockerLanding) || {};
  document.querySelectorAll('[data-ul-start]').forEach(link => {
    const url = new URL(link.href);
    Object.entries(campaign.attribution).forEach(([key, value]) => url.searchParams.set(key, value));
    link.href = url.href;
  });
  const form = document.querySelector('#ul-lead-form');
  if (form) {
    const delegation = campaign.offer === 'delegation';
    const label = delegation ? 'La délégation' : 'Le split de paiement';
    document.querySelectorAll('[data-ul-offer]').forEach(node => { node.textContent = label; });
    const backLink = document.querySelector('#ul-back');
    if (backLink) {
      const backHref = delegation ? config.delegation : config.split;
      if (backHref) backLink.href = backHref;
    }
    document.querySelector('#ul-intent').textContent = campaign.demo ? 'Un échange pour poser les bonnes bases.' : 'Votre prochaine saison commence ici.';
    const submitButton = form.querySelector('button[type="submit"]');
    const errorBox = document.querySelector('#ul-form-error');
    submitButton.disabled = false;
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      submitButton.disabled = true;
      if (errorBox) errorBox.hidden = true;
      const parcours = campaign.demo ? 'demo' : 'start';
      const websiteField = document.querySelector('#ul-website');
      const body = Object.assign({
        email: document.querySelector('#ul-email').value,
        company: document.querySelector('#ul-company').value,
        country: document.querySelector('#ul-country').value,
        area: document.querySelector('#ul-area').value,
        size: document.querySelector('#ul-size').value,
        phone: document.querySelector('#ul-phone').value,
        offer: campaign.offer,
        parcours: parcours,
        page_url: window.location.href.split('#')[0],
        website: websiteField ? websiteField.value : '',
        consent_ads: readConsentAdsFromCookie(),
        landed_at: readOrStoreLandedAt()
      }, campaign.attribution);
      fetch(config.endpoint || '', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      }).then(response => {
        if (!response.ok) throw new Error('http_' + response.status);
        return response.json();
      }).then(data => {
        if (!data || data.ok !== true) throw new Error('not_ok');
        form.hidden = true;
        const next = document.querySelector('#ul-next');
        next.hidden = false;
        document.querySelector('#ul-next-title').focus();
        trackLeadConversion(campaign.offer, parcours);
      }).catch(() => {
        submitButton.disabled = false;
        if (errorBox) errorBox.hidden = false;
      });
    });
    document.querySelector('#ul-edit').addEventListener('click', () => {
      document.querySelector('#ul-next').hidden = true;
      form.hidden = false;
      form.querySelector('input').focus();
    });
    if (campaign.demo) document.querySelector('#ul-next-intro').textContent = 'Vous préférez être accompagné : réservez un échange avec l’équipe pour examiner votre activité et vos besoins.';
  }
}
