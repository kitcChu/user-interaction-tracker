/**
 * kitcchu/user-interaction-tracker — browser tracker.
 *
 * Framework-agnostic. Auto-detects:
 *   - any form with the CSS class `.uit-track` or id `exportEnquiryForm`
 *   - (optional) any element with [data-uit-page-meta] carries page metadata
 *
 * Events captured:
 *   page_view, form_opened, field_focus, input_snapshot, form_abandoned
 *
 * Page metadata (page_id, page_title) can be supplied either via
 *   window.UIT_PAGE_META = { page_id, page_title }
 * (the Blade directive sets this) or by setting window.UIT_PAGE_META manually.
 *
 * For multi-step Vue forms, import the companion `order-interaction-mixin.js`.
 */
(function () {
    'use strict';
    if (window.__UIT_INIT__) return;
    window.__UIT_INIT__ = true;

    var ENDPOINT = window.UIT_ENDPOINT || '/api/track-interaction';
    var FORM_SELECTOR = 'form.exportEnquiryForm, form.uit-track, form#exportEnquiryForm';
    // Patterns injected by the Blade directive (window.UIT_LINK_PATTERNS = {label:[subs]}).
    // Defaults cover the common social/messaging links.
    var LINK_PATTERNS = window.UIT_LINK_PATTERNS || {
        'WhatsApp': ['wa.me', 'api.whatsapp.com', 'web.whatsapp.com', 'whatsapp.com'],
        'Facebook': ['facebook.com', 'fb.com', 'fb.me/', 'm.me/'],
        'Instagram': ['instagram.com'],
        'Telegram': ['t.me/'],
        'Phone': ['tel:'],
        'Email': ['mailto:'],
    };
    var SNAPSHOT_DEBOUNCE_MS = 1500;
    var IDLE_FLUSH_MS = 4000;

    function getSessionId() {
        var KEY = 'ui_trk_sid';
        try {
            var sid = sessionStorage.getItem(KEY);
            if (!sid) {
                sid = 's_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
                sessionStorage.setItem(KEY, sid);
            }
            return sid;
        } catch (e) {
            window.__ui_trk_sid = window.__ui_trk_sid || ('s_' + Date.now().toString(36));
            return window.__ui_trk_sid;
        }
    }

    function send(payload, useBeacon) {
        var body = JSON.stringify(payload);
        try {
            if (useBeacon && navigator.sendBeacon) {
                return navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'application/json' }));
            }
            fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
                keepalive: true,
            }).catch(function () {});
        } catch (e) { /* never break the page */ }
        return false;
    }

    function meta() {
        var m = window.UIT_PAGE_META || {};
        return {
            session_id: getSessionId(),
            page_id: m.page_id || null,
            page_title: m.page_title || document.title,
            url: location.href,
        };
    }

    // ---- External link-click tracking (delegated, document-level) ----
    // Matches any <a> whose href contains a configured pattern. Fires a
    // `link_click` event (sendBeacon so it survives same-tab navigation).
    function matchLinkType(href) {
        if (!href) return null;
        var h = href.toLowerCase();
        for (var label in LINK_PATTERNS) {
            if (!Object.prototype.hasOwnProperty.call(LINK_PATTERNS, label)) continue;
            var subs = LINK_PATTERNS[label] || [];
            for (var i = 0; i < subs.length; i++) {
                if (h.indexOf(subs[i].toLowerCase()) !== -1) return label;
            }
        }
        return null;
    }

    document.addEventListener('click', function (e) {
        var node = e.target;
        while (node && node.nodeType === 1 && node.tagName !== 'A') node = node.parentNode;
        if (!node || node.tagName !== 'A') return;
        var type = matchLinkType(node.href);
        if (!type) return;
        send(Object.assign({}, meta(), {
            event: 'link_click',
            link_type: type,
            link_text: (node.textContent || node.getAttribute('aria-label') || '').trim().slice(0, 100),
            link_url: node.href,
        }), true);  // beacon — survives navigation / new-tab
    }, true);

    function collectFields(form) {
        // Iterate ALL form elements so any form (incl. radios + checkbox arrays)
        // is captured. Honeypots/internals skipped. Multi-value fields aggregated.
        var out = {};
        var arrayAccum = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            var name = el.name;
            if (!name) return;
            if (name === '_token' || name === 'country' || name === 'date' || name === 'password') return;
            if (el.type === 'radio') {
                if (el.checked) out[name] = String(el.value).trim().slice(0, 500);
                return;
            }
            if (el.type === 'checkbox') {
                var key = name.replace(/\[\]$/, '');
                if (el.checked) {
                    if (name.indexOf('[]', name.length - 2) !== -1) {
                        (arrayAccum[key] = arrayAccum[key] || []).push(String(el.value).trim());
                    } else {
                        out[key] = 'yes';
                    }
                }
                return;
            }
            var v = (el.value || '').trim();
            if (v && !out[name]) out[name] = String(v).slice(0, 500);
        });
        for (var k in arrayAccum) {
            if (Object.prototype.hasOwnProperty.call(arrayAccum, k)) {
                out[k] = arrayAccum[k].join(', ').slice(0, 500);
            }
        }
        return out;
    }

    function bindForm(form) {
        if (form.__uitBound) return;
        form.__uitBound = true;

        var submitted = false;
        var snapTimer = null;
        var idleTimer = null;
        var openedFired = false;

        function fireOpened() {
            if (openedFired) return;
            openedFired = true;
            send(Object.assign({}, meta(), { event: 'form_opened' }));
        }

        // UIkit modal detection (#show-form), else fire if visible.
        var modal = form.closest('#show-form') || document.getElementById('show-form');
        if (modal && window.UIkit && window.UIkit.util) {
            try { window.UIkit.util.on(modal, 'shown', fireOpened); } catch (e) {}
        }
        var r = form.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) fireOpened();

        form.addEventListener('focusin', function (e) {
            var name = e.target && e.target.name;
            if (!name || name === '_token' || name === 'country' || name === 'date') return;
            send(Object.assign({}, meta(), { event: 'field_focus', focus_field: name.replace(/\[\]$/, '') }));
        });

        var schedule = function () {
            clearTimeout(snapTimer); clearTimeout(idleTimer);
            snapTimer = setTimeout(function () {
                send(Object.assign({}, meta(), { event: 'input_snapshot', fields: collectFields(form) }));
            }, SNAPSHOT_DEBOUNCE_MS);
            idleTimer = setTimeout(function () {
                send(Object.assign({}, meta(), { event: 'input_snapshot', fields: collectFields(form) }));
            }, IDLE_FLUSH_MS);
        };
        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        form.addEventListener('submit', function () { submitted = true; }, true);

        form.__uitReportAbandon = function () {
            if (submitted) return;
            send(Object.assign({}, meta(), { event: 'form_abandoned', fields: collectFields(form) }), true);
        };
    }

    function bindAll() {
        Array.prototype.forEach.call(document.querySelectorAll(FORM_SELECTOR), bindForm);
    }

    // page_view
    send(Object.assign({}, meta(), { event: 'page_view' }));

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
    } else {
        bindAll();
    }
    setTimeout(bindAll, 500);
    setTimeout(bindAll, 2000);

    function reportAbandon() {
        Array.prototype.forEach.call(document.querySelectorAll(FORM_SELECTOR), function (form) {
            if (form.__uitReportAbandon) form.__uitReportAbandon();
        });
    }
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') reportAbandon(); });
    window.addEventListener('pagehide', reportAbandon);
    window.addEventListener('beforeunload', reportAbandon);
})();
