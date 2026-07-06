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

    function collectFields(form) {
        var out = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            var name = el.name;
            if (!name || name.charAt(0) === '_' || name === 'country' || name === 'date' || name === 'password') return;
            var v;
            if (el.type === 'checkbox') v = el.checked ? 'yes' : '';
            else v = (el.value || '').trim();
            if (v) out[name] = String(v).slice(0, 500);
        });
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
            if (!name) return;
            send(Object.assign({}, meta(), { event: 'field_focus', focus_field: name }));
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
