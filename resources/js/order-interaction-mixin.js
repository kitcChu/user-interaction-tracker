/**
 * kitcchu/user-interaction-tracker — Vue mixin for multi-step forms.
 *
 * Import in any Options-API Vue component:
 *
 *   import orderInteractionMixin from '@/js/vendor/user-interaction-tracker/order-interaction-mixin';
 *   export default { mixins: [orderInteractionMixin], data() {...} }
 *
 * Requires the component to expose `order` (reactive) and `currentStep`.
 * Optional: `trackingPageTitle` in data() to label the page in Telegram.
 *
 * Reports: form_opened, step_change, order_submitted, form_abandoned
 */
const ENDPOINT = window.UIT_ENDPOINT || '/api/track-interaction';

function getSessionId() {
    const KEY = 'ui_trk_sid';
    try {
        let sid = sessionStorage.getItem(KEY);
        if (!sid) {
            sid = 's_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
            sessionStorage.setItem(KEY, sid);
        }
        return sid;
    } catch (_) {
        window.__ui_trk_sid = window.__ui_trk_sid || ('s_' + Date.now().toString(36));
        return window.__ui_trk_sid;
    }
}

export default {
    data() {
        return {
            __trk: { sessionId: null, submitted: false, opened: false },
        };
    },

    computed: {
        __trkPageTitle() {
            return this.trackingPageTitle || (window.UIT_PAGE_META && window.UIT_PAGE_META.page_title) || 'Form';
        },
    },

    watch: {
        currentStep(newStep) {
            if (newStep == null) return;
            // A final "completed" step (>= 6) is treated as a submission.
            if (newStep >= 6) {
                this.__trk.submitted = true;
                this.__send({ event: 'order_submitted', current_step: newStep, fields: this.__serializeOrder() });
                return;
            }
            this.__send({ event: 'step_change', current_step: newStep, fields: this.__serializeOrder() });
        },
    },

    mounted() {
        this.__trk.sessionId = getSessionId();
        this.$nextTick(() => {
            if (!this.__trk.opened) {
                this.__trk.opened = true;
                this.__send({ event: 'form_opened', current_step: this.currentStep || 1 });
            }
        });
        window.addEventListener('pagehide', this.__reportAbandonBound);
        document.addEventListener('visibilitychange', this.__onVisibilityBound);
    },

    beforeUnmount() {
        this.__reportAbandon();
        window.removeEventListener('pagehide', this.__reportAbandonBound);
        document.removeEventListener('visibilitychange', this.__onVisibilityBound);
    },

    methods: {
        __reportAbandonBound() { this.__reportAbandon({ useBeacon: true }); },
        __onVisibilityBound() { if (document.visibilityState === 'hidden') this.__reportAbandon({ useBeacon: true }); },

        __reportAbandon(opts = {}) {
            if (this.__trk.submitted) return;
            this.__send({
                event: 'form_abandoned',
                current_step: this.currentStep || null,
                fields: this.__serializeOrder(),
            }, opts);
        },

        __serializeOrder() {
            const o = this.order || {};
            const out = {};
            if (o.service_plan_id != null) {
                const plan = this.selectedPlan || (this.plans || []).find(p => p.id === o.service_plan_id);
                out.service_plan_id = plan ? (plan.name || plan.title || `#${o.service_plan_id}`) : `#${o.service_plan_id}`;
            }
            if (o.pickup_date) out.pickup_date = String(o.pickup_date);
            if (o.pickup_time_slot) out.pickup_time_slot = String(o.pickup_time_slot);
            if (Array.isArray(o.packaging_materials) && o.packaging_materials.length) out.packaging_materials = this.__summarize(o.packaging_materials);
            if (Array.isArray(o.storage_items) && o.storage_items.length) out.storage_items = this.__summarize(o.storage_items);
            if (Array.isArray(o.special_services) && o.special_services.length) out.special_services = this.__summarize(o.special_services);
            if (o.special_instructions && String(o.special_instructions).trim()) out.special_instructions = String(o.special_instructions).trim().slice(0, 200);
            if (o.referral_source && String(o.referral_source).trim()) out.referral_source = String(o.referral_source).trim();
            if (o.total_price != null && Number(o.total_price) > 0) out.total_price = '$' + Number(o.total_price).toFixed(2);
            if (o.address_id != null) out.address_id = `#${o.address_id}`;
            return out;
        },

        __summarize(items) {
            return items.map(i => {
                const name = i.name || i.title || i.label || i.id || 'item';
                const qty = i.quantity ?? i.qty ?? null;
                return qty != null ? `${name} x${qty}` : String(name);
            }).join(', ').slice(0, 200);
        },

        __send(payload, opts = {}) {
            const body = JSON.stringify(Object.assign({
                session_id: this.__trk.sessionId,
                page_id: (window.UIT_PAGE_META && window.UIT_PAGE_META.page_id) || null,
                page_title: this.__trkPageTitle,
                url: location.href,
            }, payload));
            try {
                if (opts.useBeacon && navigator.sendBeacon) {
                    return navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'application/json' }));
                }
                fetch(ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body, keepalive: true,
                }).catch(() => {});
            } catch (_) {}
            return false;
        },
    },
};
