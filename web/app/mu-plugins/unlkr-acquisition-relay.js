(function () {
    'use strict';

    var config = document.querySelector('meta[name="unlkr-acquisition-relay"]');
    if (!config || !window.crypto || typeof window.crypto.getRandomValues !== 'function') {
        return;
    }

    var formId = config.getAttribute('data-form-id');
    var fieldName = config.getAttribute('data-attempt-field');
    var retentionSeconds = config.getAttribute('data-attempt-retention-seconds');
    var valid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    var storageKey = 'unlkr_acquisition_attempt_v1_' + formId;
    var observer = null;
    var timer = null;

    if (!/^\d+$/.test(String(formId)) || !/^\d+$/.test(String(retentionSeconds)) || Number(retentionSeconds) <= 0) {
        return;
    }

    function storage() {
        try {
            return window.localStorage;
        } catch (error) {
            return null;
        }
    }

    function stopObserver() {
        if (observer) {
            observer.disconnect();
            observer = null;
        }
        if (timer) {
            window.clearTimeout(timer);
            timer = null;
        }
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
        for (var i = 0; i < bytes.length; i++) {
            hex.push(('0' + bytes[i].toString(16)).slice(-2));
        }
        return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-' + hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-' + hex.slice(10, 16).join('');
    }

    function storedAttempt() {
        var browserStorage = storage();
        if (!browserStorage) {
            return null;
        }
        try {
            var record = JSON.parse(browserStorage.getItem(storageKey));
            if (record && record.v === 1 && valid.test(record.id) && typeof record.expires_at === 'number' && record.expires_at > Date.now()) {
                return record.id;
            }
            browserStorage.removeItem(storageKey);
        } catch (error) {
            return null;
        }
        return null;
    }

    function stableAttempt() {
        var existing = storedAttempt();
        if (existing) {
            return existing;
        }
        var browserStorage = storage();
        if (!browserStorage) {
            return null;
        }
        var generated = uuid();
        try {
            browserStorage.setItem(storageKey, JSON.stringify({
                v: 1,
                id: generated,
                expires_at: Date.now() + (Number(retentionSeconds) * 1000)
            }));
            return generated;
        } catch (error) {
            return null;
        }
    }

    function fill(input) {
        var attempt = stableAttempt();
        if (attempt) {
            input.value = attempt;
        } else {
            // Without durable first-party storage, a reload would create a new
            // CRM request. Keep the field empty so the server fails closed.
            input.value = '';
        }
    }

    function bindReset(input, wrapper) {
        var form = input.form || (wrapper.closest ? wrapper.closest('form') : null);
        if (!form || form.__unlkrAcquisitionResetBound) {
            return;
        }
        form.__unlkrAcquisitionResetBound = true;
        form.addEventListener('reset', function () {
            window.setTimeout(function () {
                var inputs = wrapper.querySelectorAll('input[type="hidden"]');
                for (var index = 0; index < inputs.length; index++) {
                    if (inputs[index].name === fieldName) {
                        fill(inputs[index]);
                    }
                }
            }, 0);
        });
    }

    function attach() {
        var found = false;
        var wrappers = document.querySelectorAll('[data-form-id]');
        for (var i = 0; i < wrappers.length; i++) {
            if (String(wrappers[i].getAttribute('data-form-id')) !== String(formId)) {
                continue;
            }
            var inputs = wrappers[i].querySelectorAll('input[type="hidden"]');
            for (var j = 0; j < inputs.length; j++) {
                if (inputs[j].name === fieldName) {
                    found = true;
                    fill(inputs[j]);
                    bindReset(inputs[j], wrappers[i]);
                }
            }
        }
        return found;
    }

    function start() {
        if (attach() || !window.MutationObserver || !document.documentElement) {
            return;
        }
        observer = new window.MutationObserver(function () {
            if (attach()) {
                stopObserver();
            }
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
        timer = window.setTimeout(stopObserver, 10000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
}());
