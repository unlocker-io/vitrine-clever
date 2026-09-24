(function () {
    'use strict';

    var meta = document.querySelector('meta[name="unlkr-acquisition-touches"]');
    if (!meta || !window.crypto || typeof window.crypto.getRandomValues !== 'function' || typeof window.fetch !== 'function') {
        return;
    }

    var preferencesUrl = meta.getAttribute('data-preferences-url');
    var touchesUrl = meta.getAttribute('data-touches-url');
    var siteKey = meta.getAttribute('data-site-key');
    var noticeVersion = meta.getAttribute('data-notice-version');
    var landingKey = meta.getAttribute('data-landing-key');
    var ttlSeconds = Number(meta.getAttribute('data-ttl-seconds'));
    var timeoutMs = Number(meta.getAttribute('data-timeout-ms'));
    var retries = Number(meta.getAttribute('data-retries'));
    var handleStorageKey = 'unlkr_acquisition_touches_handle_v1_' + siteKey;
    var arrivalStorageKey = 'unlkr_acquisition_touches_arrival_v1_' + siteKey;
    var handlePattern = /^av1_[A-Za-z0-9_-]{43}$/;
    var receiptPattern = /^acr1_[A-Za-z0-9_-]{43}$/;
    var clickIdPattern = /^[A-Za-z0-9._~-]+$/;
    var endpointPattern = /^https:\/\/[^/?#]+\/acquisition-web\/(preferences|touches)$/;
    var consentState = 'unknown';
    var generation = 0;
    var activeRequest = null;

    if (!endpointPattern.test(String(preferencesUrl)) || !endpointPattern.test(String(touchesUrl))
        || !siteKey || siteKey.length > 64
        || !noticeVersion || noticeVersion.length > 64
        || !landingKey || landingKey.length > 64
        || !Number.isInteger(ttlSeconds) || ttlSeconds < 60 || ttlSeconds > 1800
        || !Number.isInteger(timeoutMs) || timeoutMs < 500 || timeoutMs > 10000
        || !Number.isInteger(retries) || retries < 0 || retries > 2) {
        return;
    }

    var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    var CLICK_ID_KEYS = ['gclid', 'fbclid', 'gbraid', 'wbraid'];

    // No third-party dependency: a small manual parser avoids assuming
    // URLSearchParams exists in every execution context.
    function parseQueryString(search) {
        var params = {};
        var raw = String(search || '');
        if (raw.charAt(0) === '?') {
            raw = raw.slice(1);
        }
        if (raw === '') {
            return params;
        }
        var pairs = raw.split('&');
        for (var index = 0; index < pairs.length; index++) {
            var pair = pairs[index];
            if (pair === '') {
                continue;
            }
            var equalsIndex = pair.indexOf('=');
            var rawKey = equalsIndex === -1 ? pair : pair.slice(0, equalsIndex);
            var rawValue = equalsIndex === -1 ? '' : pair.slice(equalsIndex + 1);
            try {
                var key = decodeURIComponent(rawKey.replace(/\+/g, ' '));
                var value = decodeURIComponent(rawValue.replace(/\+/g, ' '));
                params[key] = value;
            } catch (error) {
                // A malformed percent-escape on this pair must never crash parsing.
            }
        }
        return params;
    }

    function canonicalJson(object) {
        var keys = Object.keys(object).sort();
        var sorted = {};
        for (var index = 0; index < keys.length; index++) {
            sorted[keys[index]] = object[keys[index]];
        }
        return JSON.stringify(sorted);
    }

    function buildCampaignSignal(search) {
        var params = parseQueryString(search);
        var campaignParameters = {};
        var clickIds = {};

        for (var utmIndex = 0; utmIndex < UTM_KEYS.length; utmIndex++) {
            var utmKey = UTM_KEYS[utmIndex];
            if (typeof params[utmKey] !== 'string') {
                continue;
            }
            var utmValue = params[utmKey].trim();
            if (utmValue === '') {
                continue;
            }
            campaignParameters[utmKey] = utmValue.length > 256 ? utmValue.slice(0, 256) : utmValue;
        }

        if (typeof params.utm_id === 'string') {
            var campaignExternalId = params.utm_id.trim();
            if (campaignExternalId !== '') {
                campaignParameters.campaign_external_id = campaignExternalId.length > 120 ? campaignExternalId.slice(0, 120) : campaignExternalId;
            }
        }

        for (var clickIndex = 0; clickIndex < CLICK_ID_KEYS.length; clickIndex++) {
            var clickKey = CLICK_ID_KEYS[clickIndex];
            if (typeof params[clickKey] !== 'string') {
                continue;
            }
            var clickValue = params[clickKey].trim();
            // A click id is never truncated: a truncated id is not the real id,
            // so it is dropped rather than sent corrupted.
            if (clickValue !== '' && clickValue.length <= 256 && clickIdPattern.test(clickValue)) {
                clickIds[clickKey] = clickValue;
            }
        }

        var union = {};
        var unionKey;
        for (unionKey in campaignParameters) {
            if (Object.prototype.hasOwnProperty.call(campaignParameters, unionKey)) {
                union[unionKey] = campaignParameters[unionKey];
            }
        }
        for (unionKey in clickIds) {
            if (Object.prototype.hasOwnProperty.call(clickIds, unionKey)) {
                union[unionKey] = clickIds[unionKey];
            }
        }

        return {
            campaignParameters: campaignParameters,
            clickIds: clickIds,
            arrivalKey: canonicalJson(union)
        };
    }

    var campaignSignal = buildCampaignSignal(window.location.search);

    // No recognized campaign or click-id signal on this page load: never send,
    // and never even wire up consent listeners.
    if (Object.keys(campaignSignal.campaignParameters).length === 0 && Object.keys(campaignSignal.clickIds).length === 0) {
        return;
    }

    function uuid() {
        if (typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = [];
        for (var index = 0; index < bytes.length; index++) {
            hex.push(('0' + bytes[index].toString(16)).slice(-2));
        }
        return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-' + hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-' + hex.slice(10, 16).join('');
    }

    function storage() {
        try {
            return window.sessionStorage;
        } catch (error) {
            return null;
        }
    }

    function clearHandleRecord() {
        var browserStorage = storage();
        if (browserStorage) {
            try {
                browserStorage.removeItem(handleStorageKey);
            } catch (error) {
                // Storage failures keep the cache unavailable, never throw.
            }
        }
    }

    function clearArrivalRecord() {
        var browserStorage = storage();
        if (browserStorage) {
            try {
                browserStorage.removeItem(arrivalStorageKey);
            } catch (error) {
                // Storage failures keep the cache unavailable, never throw.
            }
        }
    }

    function readHandle() {
        var browserStorage = storage();
        if (!browserStorage) {
            return null;
        }
        try {
            var record = JSON.parse(browserStorage.getItem(handleStorageKey));
            if (record && record.v === 1 && handlePattern.test(record.visitor_handle) && receiptPattern.test(record.consent_receipt)
                && typeof record.expires_at === 'number' && record.expires_at > Date.now()) {
                return { visitor_handle: record.visitor_handle, consent_receipt: record.consent_receipt };
            }
        } catch (error) {
            // Invalid or unavailable state is cleared below.
        }
        clearHandleRecord();
        return null;
    }

    function writeHandle(visitorHandle, consentReceipt, expiresAt) {
        var browserStorage = storage();
        if (!browserStorage) {
            return;
        }
        try {
            browserStorage.setItem(handleStorageKey, JSON.stringify({
                v: 1,
                visitor_handle: visitorHandle,
                consent_receipt: consentReceipt,
                expires_at: expiresAt
            }));
        } catch (error) {
            // A storage failure just means the next produce() mints again.
        }
    }

    function readArrivalRecord() {
        var browserStorage = storage();
        if (!browserStorage) {
            return null;
        }
        try {
            var record = JSON.parse(browserStorage.getItem(arrivalStorageKey));
            if (record && record.v === 1 && typeof record.key === 'string' && typeof record.external_touch_id === 'string' && typeof record.sent === 'boolean') {
                return record;
            }
        } catch (error) {
            // Invalid or unavailable state is treated as no record.
        }
        return null;
    }

    function writeArrivalRecord(key, externalTouchId, sent) {
        var browserStorage = storage();
        if (!browserStorage) {
            return;
        }
        try {
            browserStorage.setItem(arrivalStorageKey, JSON.stringify({ v: 1, key: key, external_touch_id: externalTouchId, sent: sent }));
        } catch (error) {
            // A storage failure only risks a duplicate attempt, never a crash.
        }
    }

    function markArrivalSent(key, externalTouchId) {
        var record = readArrivalRecord();
        if (record && record.key === key && record.external_touch_id === externalTouchId) {
            writeArrivalRecord(key, externalTouchId, true);
        }
    }

    async function fetchJson(url, body) {
        for (var attempt = 0; attempt <= retries; attempt++) {
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var timeout = controller ? window.setTimeout(function () { controller.abort(); }, timeoutMs) : null;
            try {
                var response = await window.fetch(url, {
                    method: 'POST',
                    mode: 'cors',
                    credentials: 'omit',
                    cache: 'no-store',
                    redirect: 'error',
                    referrerPolicy: 'no-referrer',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                    signal: controller ? controller.signal : undefined
                });
                if (timeout !== null) {
                    window.clearTimeout(timeout);
                }
                if ((response.status === 429 || response.status >= 500) && attempt < retries) {
                    continue;
                }
                if (response.status !== 200 && response.status !== 201) {
                    return null;
                }
                return await response.json();
            } catch (error) {
                if (timeout !== null) {
                    window.clearTimeout(timeout);
                }
                if (attempt >= retries) {
                    return null;
                }
            }
        }
        return null;
    }

    // Fire-and-forget delivery: any HTTP response is terminal (200/409/410/412/
    // 413 alike), so a reload never re-hammers a request the server already
    // answered. Only a network/abort failure with no response leaves room for
    // the configured retry, then a future retry via a later reload.
    async function postTouch(url, body) {
        for (var attempt = 0; attempt <= retries; attempt++) {
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var timeout = controller ? window.setTimeout(function () { controller.abort(); }, timeoutMs) : null;
            try {
                await window.fetch(url, {
                    method: 'POST',
                    mode: 'cors',
                    credentials: 'omit',
                    cache: 'no-store',
                    redirect: 'error',
                    referrerPolicy: 'no-referrer',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(body),
                    signal: controller ? controller.signal : undefined
                });
                if (timeout !== null) {
                    window.clearTimeout(timeout);
                }
                return true;
            } catch (error) {
                if (timeout !== null) {
                    window.clearTimeout(timeout);
                }
                if (attempt >= retries) {
                    return false;
                }
            }
        }
        return false;
    }

    function mintHandle() {
        var body = {
            schema_version: 1,
            site_key: siteKey,
            notice_version: noticeVersion,
            preferences: {
                ads_measurement: 'granted',
                ads_sharing: 'granted',
                marketing_opt_in: false
            }
        };
        return fetchJson(preferencesUrl, body).then(function (result) {
            if (!result || !handlePattern.test(result.visitor_handle) || !receiptPattern.test(result.consent_receipt)) {
                return null;
            }
            var crmExpiry = Date.parse(result.expires_at);
            if (!Number.isFinite(crmExpiry) || crmExpiry <= Date.now()) {
                return null;
            }
            var localExpiry = Math.min(crmExpiry, Date.now() + (ttlSeconds * 1000));
            writeHandle(result.visitor_handle, result.consent_receipt, localExpiry);
            return { visitor_handle: result.visitor_handle, consent_receipt: result.consent_receipt };
        });
    }

    function ensureHandle() {
        var existing = readHandle();
        if (existing) {
            return Promise.resolve(existing);
        }
        return mintHandle();
    }

    function sendTouch(identity, touchId) {
        var touch = {
            external_touch_id: touchId,
            landing_key: landingKey,
            occurred_at: new Date().toISOString()
        };
        if (Object.keys(campaignSignal.campaignParameters).length > 0) {
            touch.campaign_parameters = campaignSignal.campaignParameters;
        }
        if (Object.keys(campaignSignal.clickIds).length > 0) {
            touch.click_ids = campaignSignal.clickIds;
        }
        var body = {
            schema_version: 1,
            site_key: siteKey,
            visitor_handle: identity.visitor_handle,
            consent_receipt: identity.consent_receipt,
            touches: [touch]
        };
        return postTouch(touchesUrl, body);
    }

    function produce() {
        if (consentState !== 'granted' || activeRequest !== null) {
            return;
        }
        var record = readArrivalRecord();
        if (record && record.key === campaignSignal.arrivalKey && record.sent === true) {
            // Already delivered this exact arrival this session: zero requests.
            return;
        }
        var touchId = record && record.key === campaignSignal.arrivalKey ? record.external_touch_id : ('awt1_' + uuid());
        // Persist before awaiting anything so a reload mid-flight sees the same
        // pending id instead of minting a second one.
        writeArrivalRecord(campaignSignal.arrivalKey, touchId, false);

        var requestGeneration = generation;
        var request = ensureHandle().then(function (identity) {
            return identity ? sendTouch(identity, touchId) : false;
        });
        activeRequest = request;
        request.then(function (delivered) {
            if (activeRequest !== request || generation !== requestGeneration) {
                return;
            }
            if (delivered) {
                markArrivalSent(campaignSignal.arrivalKey, touchId);
            }
        }).catch(function () {
            // Never surface a rejection: fetchJson/postTouch already swallow
            // every failure, this is a last-resort net.
        }).then(function () {
            if (activeRequest === request) {
                activeRequest = null;
            }
        });
    }

    function invalidate(nextState) {
        generation++;
        activeRequest = null;
        clearHandleRecord();
        clearArrivalRecord();
        consentState = nextState;
    }

    function applyConsent(nextState) {
        if (nextState === 'granted') {
            consentState = 'granted';
            produce();
            return;
        }
        if (nextState === 'denied' || nextState === 'revoked') {
            invalidate(nextState);
            return;
        }
        consentState = nextState;
    }

    function bannerConsent(detail) {
        if (!detail || detail.isUserActionCompleted !== true || !detail.categories || typeof detail.categories.advertisement !== 'boolean') {
            return 'unknown';
        }
        return detail.categories.advertisement ? 'granted' : 'denied';
    }

    function updatedConsent(detail) {
        if (!detail || !Array.isArray(detail.accepted) || !Array.isArray(detail.rejected)) {
            return 'unknown';
        }
        if (detail.accepted.indexOf('advertisement') !== -1) {
            return 'granted';
        }
        return detail.rejected.indexOf('advertisement') !== -1 ? 'revoked' : 'unknown';
    }

    document.addEventListener('cookieyes_banner_load', function (event) {
        applyConsent(bannerConsent(event.detail));
    });
    document.addEventListener('cookieyes_consent_update', function (event) {
        applyConsent(updatedConsent(event.detail));
    });
    // Recover safely if CookieYes loaded just before this same-origin asset.
    if (typeof window.getCkyConsent === 'function') {
        try {
            applyConsent(bannerConsent(window.getCkyConsent()));
        } catch (error) {
            applyConsent('unknown');
        }
    }
}());
