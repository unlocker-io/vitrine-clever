'use strict';
function getCampaignContext(search) {
  const params = new URLSearchParams(search);
  const attribution = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'].forEach(key => {
    if (params.has(key)) attribution[key] = params.get(key).slice(0, 200);
  });
  return { offer: params.get('offre') === 'delegation' ? 'delegation' : 'split', demo: params.get('parcours') === 'demo', attribution };
}
if (typeof document !== 'undefined') {
  const campaign = getCampaignContext(window.location.search);
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
    document.querySelector('#ul-back').href = '../' + campaign.offer + '/index.html';
    document.querySelector('#ul-intent').textContent = campaign.demo ? 'Un échange pour poser les bonnes bases.' : 'Votre prochaine saison commence ici.';
    form.querySelector('button[type="submit"]').disabled = false;
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      // Deliberately no request, persistence, analytics, or PII in navigation.
      form.hidden = true;
      const next = document.querySelector('#ul-next');
      next.hidden = false;
      document.querySelector('#ul-next-title').focus();
    });
    document.querySelector('#ul-edit').addEventListener('click', () => {
      document.querySelector('#ul-next').hidden = true;
      form.hidden = false;
      form.querySelector('input').focus();
    });
    if (campaign.demo) document.querySelector('#ul-next-intro').textContent = 'Vous préférez être accompagné : réservez un échange avec l’équipe pour examiner votre activité et vos besoins.';
  }
}
