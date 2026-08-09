/**
 * Alpine component factory for the guided public demo (BL-001).
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
                return;
            }

            if (this.currentStep === 'customer_done' && this.returnUrl) {
                window.location.href = this.returnUrl;
            }
        },

        copyFor(step) {
            const ttl = config.ttlHours || 2;
            const map = {
                welcome: {
                    mode: 'info',
                    meta: 'Stap 1 van 6 · Welkom',
                    title: 'Welkom in de installateursdemo',
                    body: 'Je bent tijdelijk ingelogd als installateur. Je start precies zoals na een echte aanvraag: met een nieuwe opname. De klantnaam is nep. Adresinvulling en AI werken wel.',
                    aside: `Deze demosessie verdwijnt automatisch na ${ttl} uur. E-mail en PDF blijven uit.`,
                    cta: 'Start met nieuwe opname',
                },
                create: {
                    mode: 'info',
                    meta: 'Stap 2 van 6 · Aanmaken',
                    title: 'Zo start elke opname',
                    body: 'Vul zelf postcode en huisnummer in. De app vult straat en plaats aan. Na opslaan vult de app bekende woninggegevens aan en leest de korte uitleg mee.',
                    aside: 'Er staat een tipadres klaar, maar je mag elk bestaand adres proberen. De klantnaam blijft nep.',
                    cta: 'Begrepen',
                },
                branch: {
                    mode: 'branch',
                    meta: 'Stap 3 van 6 · Rolkeuze',
                    title: 'Adresgegevens staan al in de opname',
                    body: 'Bekijk hieronder wat er is opgehaald. In productie mailen we nu de klantlink. Hier kies je of je doorgaat als klant of zelf de opname doet.',
                    aside: 'Beide paden gebruiken dezelfde echte productschermen, inclusief AI waar die aan staat.',
                },
                customer_start: {
                    mode: 'info',
                    meta: 'Stap 4 van 6 · Klantweergave',
                    title: 'Dit ziet je klant',
                    body: 'De klant krijgt simpele stappen, geen technische keuzes. Foto’s kan de app meekijken. Deze demo is korter dan echt.',
                    aside: 'Na afronden ga je terug naar de opname.',
                    cta: 'Begin als klant',
                },
                installer_start: {
                    mode: 'info',
                    meta: 'Stap 4 van 6 · Werkplek',
                    title: 'Jij vult de opname',
                    body: 'Hier zet je ruimtes, plekken, leidingen en foto’s bij elkaar. Upload een foto om te zien wat de AI ziet. De klant ziet niets tot jij een taak stuurt.',
                    aside: 'Wil je snel een rijk eindbeeld? Gebruik “Toon voorbeelddossier” — of bouw het zelf op.',
                    cta: 'Bekijk de werkplek',
                },
                sample_loaded: {
                    mode: 'info',
                    meta: 'Stap 5 van 6 · Voorbeelddossier',
                    title: 'Voorbeeldinhoud geladen',
                    body: 'Je ziet nu een rijke opname met ruimtes, foto’s, voorstel en routes. Je kunt het AI-voorstel opnieuw maken en foto’s laten bekijken wanneer AI in deze omgeving aan staat.',
                    aside: 'Wil je de klantkant zien? Start dan de voorgestelde klanttaak — zonder mail.',
                    cta: 'Verder in de opname',
                },
                customer_done: {
                    mode: 'info',
                    meta: 'Stap 6 van 6 · Terug naar opname',
                    title: 'Klantgedeelte afgerond',
                    body: 'Normaal krijgt de installateur een mail. Hier open je de opname meteen om verder te kijken.',
                    cta: 'Naar de opname',
                },
            };

            return map[step] || null;
        },
    }));
}
