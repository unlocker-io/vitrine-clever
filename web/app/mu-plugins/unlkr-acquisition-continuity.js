(function () {
    'use strict';

    var meta = document.querySelector('meta[name="unlkr-acquisition-continuity"]');
    if (!meta || !window.crypto || typeof window.crypto.getRandomValues !== 'function' || typeof window.fetch !== 'function') {
        return;
    }

    var preferencesUrl = meta.getAttribute('data-preferences-url');
    var siteKey = meta.getAttribute('data-site-key');
    var noticeVersion = meta.getAttribute('data-notice-version');
    var allowedOrigins = String(meta.getAttribute('data-app-origins') || '').split(',').filter(Boolean);
    var ttlSeconds = Number(meta.getAttribute('data-ttl-seconds'));
    var timeoutMs = Number(meta.getAttribute('data-timeout-ms'));
    var retries = Number(meta.getAttribute('data-retries'));
    var storageKey = 'unlkr_acquisition_continuity_v1_' + siteKey;
    var handlePattern = /^av1_[A-Za-z0-9_-]{43}$/;
    var receiptPattern = /^acr1_[A-Za-z0-9_-]{43}$/;
    var uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    var consentState = 'unknown';
    var generation = 0;
    var activeRequest = null;
    var expiryTimer = null;
    var spent = false;

    if (!/^https:\/\/[^/?#]+\/acquisition-web\/preferences$/.test(String(preferencesUrl))
        || !siteKey || siteKey.length > 64 || !noticeVersion || noticeVersion.length > 64
        || allowedOrigins.length === 0 || !Number.isInteger(ttlSeconds) || ttlSeconds < 60 || ttlSeconds > 1800
        || !Number.isInteger(timeoutMs) || timeoutMs < 500 || timeoutMs > 10000
        || !Number.isInteger(retries) || retries < 0 || retries > 2) {
        return;
    }

    function storage() {
        try {
            return window.sessionStorage;
        } catch (error) {
            return null;
        }
    }

    function clearExpiryTimer() {
        if (expiryTimer !== null) {
            window.clearTimeout(expiryTimer);
            expiryTimer = null;
        }
    }

    function clearContinuity() {
        clearExpiryTimer();
        var browserStorage = storage();
        if (browserStorage) {
            try {
                browserStorage.removeItem(storageKey);
            } catch (error) {
                // Storage failures keep continuity unavailable.
            }
        }
    }

    function hasSameOriginOpener() {
        if (!window.opener || !window.location || !window.location.origin) {
            return false;
        }
        try {
            if (window.opener.location && window.opener.location.origin === window.location.origin) {
                return true;
            }
        } catch (error) {
            // A cross-origin opener is the expected app handoff boundary.
        }
        var referrer = String(document.referrer || '');
        return referrer === window.location.origin || referrer.indexOf(window.location.origin + '/') === 0;
    }

    // sessionStorage is cloned when a same-origin page opens another tab. Only
    // the original public page may produce/serve continuity; a clone destroys
    // its inherited copy before registering any consent or message listener.
    if (hasSameOriginOpener()) {
        clearContinuity();
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

    function isPayload(payload) {
        return payload && payload.schema_version === 1 && payload.site_key === siteKey
            && handlePattern.test(payload.visitor_handle) && receiptPattern.test(payload.consent_receipt)
            && uuidPattern.test(payload.identify_attempt_id)
            && Object.keys(payload).length === 5;
    }

    function readRecord() {
        var browserStorage = storage();
        if (!browserStorage) {
            return null;
        }
        try {
            var record = JSON.parse(browserStorage.getItem(storageKey));
            if (record && record.v === 1 && isPayload(record.payload) && typeof record.expires_at === 'number' && record.expires_at > Date.now()) {
                return record;
            }
        } catch (error) {
            // Invalid or unavailable state is removed below.
        }
        clearContinuity();
        return null;
    }

    function armExpiry(expiresAt) {
        clearExpiryTimer();
        var delay = Math.max(0, Math.min(expiresAt - Date.now(), 2147483647));
        expiryTimer = window.setTimeout(clearContinuity, delay);
    }

    function writeRecord(payload, expiresAt) {
        var browserStorage = storage();
        if (!browserStorage || !isPayload(payload) || expiresAt <= Date.now()) {
            clearContinuity();
            return false;
        }
        try {
            browserStorage.setItem(storageKey, JSON.stringify({ v: 1, payload: payload, expires_at: expiresAt }));
            armExpiry(expiresAt);
            return true;
        } catch (error) {
            clearContinuity();
            return false;
        }
    }

    async function fetchPreference(choice, handle, receipt) {
        var body = {
            schema_version: 1,
            site_key: siteKey,
            notice_version: noticeVersion,
            preferences: {
                ads_measurement: choice,
                ads_sharing: choice,
                marketing_opt_in: false
            }
        };
        if (handle && receipt) {
            body.visitor_handle = handle;
            body.consent_receipt = receipt;
        }

        for (var attempt = 0; attempt <= retries; attempt++) {
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var timeout = controller ? window.setTimeout(function () { controller.abort(); }, timeoutMs) : null;
            try {
                var response = await window.fetch(preferencesUrl, {
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

    function produce() {
        if (consentState !== 'granted' || spent || readRecord() || activeRequest !== null) {
            return;
        }
        var requestGeneration = generation;
        var request = fetchPreference('granted', null, null);
        activeRequest = request;
        request.then(function (result) {
            if (activeRequest !== request || consentState !== 'granted' || generation !== requestGeneration || !result
                || !handlePattern.test(result.visitor_handle) || !receiptPattern.test(result.consent_receipt)) {
                return;
            }
            var crmExpiry = Date.parse(result.expires_at);
            if (!Number.isFinite(crmExpiry) || crmExpiry <= Date.now()) {
                return;
            }
            var localExpiry = Math.min(crmExpiry, Date.now() + (ttlSeconds * 1000));
            writeRecord({
                schema_version: 1,
                site_key: siteKey,
                visitor_handle: result.visitor_handle,
                consent_receipt: result.consent_receipt,
                identify_attempt_id: uuid()
            }, localExpiry);
        }).finally(function () {
            if (activeRequest === request) {
                activeRequest = null;
            }
        });
    }

    function invalidate(nextState, notifyCrm) {
        generation++;
        activeRequest = null;
        var existing = readRecord();
        clearContinuity();
        consentState = nextState;
        spent = nextState === 'invalid_origin';
        if (notifyCrm && existing) {
            // Withdrawal is fail-closed locally. CRM notification is best-effort
            // and never blocks banner interaction or navigation.
            fetchPreference('revoked', existing.payload.visitor_handle, existing.payload.consent_receipt);
        }
    }

    function applyConsent(nextState) {
        if (nextState === 'granted') {
            if (consentState !== 'granted') {
                generation++;
                if (consentState !== 'consumed') {
                    spent = false;
                }
            }
            consentState = 'granted';
            produce();
            return;
        }
        invalidate(nextState, nextState === 'denied' || nextState === 'revoked');
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

    window.addEventListener('message', function (event) {
        if (allowedOrigins.indexOf(event.origin) === -1) {
            invalidate('invalid_origin', false);
            return;
        }
        var request = event.data;
        if (!request || request.type !== 'unlkr:continuity:request' || request.schema_version !== 1
            || !uuidPattern.test(request.request_id) || Object.keys(request).length !== 3
            || !event.source || typeof event.source.postMessage !== 'function') {
            return;
        }
        var record = consentState === 'granted' ? readRecord() : null;
        if (!record) {
            clearContinuity();
            return;
        }
        // Consume before posting: re-entrant or replayed requests cannot obtain
        // the same handle/receipt, even when postMessage itself fails.
        clearContinuity();
        consentState = 'consumed';
        spent = true;
        event.source.postMessage({
            type: 'unlkr:continuity:response',
            schema_version: 1,
            request_id: request.request_id,
            payload: record.payload
        }, event.origin);
    });

    // Stored state is never usable until CookieYes confirms the current page.
    var existing = readRecord();
    if (existing) {
        armExpiry(existing.expires_at);
    }
}());
