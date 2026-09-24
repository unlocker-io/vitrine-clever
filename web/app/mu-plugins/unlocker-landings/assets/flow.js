'use strict';
function getCampaignContext(search) {
  const params = new URLSearchParams(search);
  const attribution = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'].forEach(key => {
    if (params.has(key)) attribution[key] = params.get(key).slice(0, 200);
  });
  return { offer: params.get('offre') === 'delegation' ? 'delegation' : 'split', demo: params.get('parcours') === 'demo', attribution };
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
        website: websiteField ? websiteField.value : ''
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
