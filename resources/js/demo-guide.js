/**
 * Alpine component factory for the guided public demo (BL-001 / BL-070).
 * One short welcome layer, then the real workspace. Role choice stays a
 * functional modal (not a tour step). Intermediate coachmarks are no-ops.
 */
export function registerDemoGuide(Alpine) {
    Alpine.data('demoGuide', (config) => ({
        open: false,
        mode: 'info',
        title: '',
        body: '',
        aside: '',
        cta: 'Begrepen',
        metaLabel: '',
        dismissible: true,
        currentStep: null,
        pathChooseUrl: config.pathChooseUrl,
        csrf: config.csrf,
        createUrl: config.createUrl || null,
        returnUrl: config.returnUrl || null,
        storageKey: 'intake-demo-guide-dismissed',

        init() {
            const step = config.initialStep;
            if (!step) {
                return;
            }
            // BL-070: only welcome + branch (role choice) open a layer.
            if (!['welcome', 'branch'].includes(step)) {
                return;
            }
            if (this.wasDismissed(step) && step !== 'branch') {
                return;
            }
            this.show(step);
        },

        wasDismissed(step) {
            try {
                const raw = sessionStorage.getItem(this.storageKey);
                const list = raw ? JSON.parse(raw) : [];
                return Array.isArray(list) && list.includes(step);
            } catch {
                return false;
            }
        },

        markDismissed(step) {
            try {
                const raw = sessionStorage.getItem(this.storageKey);
                const list = raw ? JSON.parse(raw) : [];
                const next = Array.isArray(list) ? list : [];
                if (!next.includes(step)) {
                    next.push(step);
                }
                sessionStorage.setItem(this.storageKey, JSON.stringify(next));
            } catch {
                // sessionStorage may be unavailable; ignore.
            }
        },

        show(step) {
            const copy = this.copyFor(step);
            if (!copy) {
                return;
            }
            this.mode = copy.mode;
            this.title = copy.title;
            this.body = copy.body;
            this.aside = copy.aside || '';
            this.cta = copy.cta || 'Begrepen';
            this.metaLabel = copy.meta;
            this.dismissible = copy.mode !== 'branch';
            this.currentStep = step;
            this.open = true;
            document.body.classList.add('overflow-y-hidden');
        },

        close() {
            this.open = false;
            document.body.classList.remove('overflow-y-hidden');
        },

        acknowledge() {
            if (this.currentStep) {
                this.markDismissed(this.currentStep);
            }
            this.close();

            if (this.currentStep === 'welcome' && !config.hasIntake && this.createUrl) {
                window.location.href = this.createUrl;
            }
        },

        copyFor(step) {
            const ttl = config.ttlHours || 2;
            const map = {
                welcome: {
                    mode: 'info',
                    meta: 'Welkom',
                    title: 'Welkom in de installateursdemo',
                    body: 'U bent tijdelijk ingelogd als installateur. U start precies zoals na een echte aanvraag: met een nieuwe opname. De klantnaam is nep. Adresinvulling en AI werken wel.',
                    aside: `Deze demosessie verdwijnt automatisch na ${ttl} uur. E-mail blijft uit. PDF kunt u later op aanvraag ontvangen.`,
                    cta: 'Start met nieuwe opname',
                },
                branch: {
                    mode: 'branch',
                    meta: 'Hoe wilt u verder?',
                    title: 'Adresgegevens staan al in de opname',
                    body: 'Bekijk hieronder wat er is opgehaald. Doe de opname zelf — net als in de praktijk. Of bekijk kort wat de klant ziet.',
                    aside: 'Beide paden gebruiken dezelfde echte productschermen. Er gaat geen e-mail uit in de demo.',
                },
            };

            return map[step] || null;
        },
    }));
}
