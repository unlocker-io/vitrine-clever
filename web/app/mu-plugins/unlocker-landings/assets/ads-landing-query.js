'use strict';

// Propagates ad-tracking query params from the incoming URL onto the CTA
// buttons marked by inc/ads-landings.php's elementor/widget/render_content
// filter (data-ul-ads-cta), so a click through to /demarrer/ carries the
// same gclid/utm_* the visitor arrived with. Never touches offre/parcours:
// those aren't in the tracked whitelist below. No cookies/storage touched --
// pure navigation -- so cookie consent state is irrelevant here.
(function () {
  if (typeof document === 'undefined') return;

  var TRACKED_PARAMS = [
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_content',
    'utm_term',
    'utm_id',
    'gclid',
    'gbraid',
    'wbraid',
    'fbclid',
  ];

  try {
    var incoming = new URLSearchParams(window.location.search);
    var present = TRACKED_PARAMS.filter(function (key) {
      return incoming.has(key);
    });

    if (present.length === 0) return;

    document.querySelectorAll('[data-ul-ads-cta]').forEach(function (link) {
      try {
        var url = new URL(link.href, window.location.href);
        present.forEach(function (key) {
          url.searchParams.set(key, incoming.get(key));
        });
        link.href = url.href;
      } catch (e) {
        // leave this one link untouched
      }
    });
  } catch (e) {
    // leave every href untouched
  }
})();
