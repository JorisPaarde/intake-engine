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
                    body: 'Je bent tijdelijk ingelogd als installateur. Je start precies zoals na een echte aanvraag: met een nieuwe opname. Alle gegevens zijn fictief.',
                    aside: `Deze demosessie verdwijnt automatisch na ${ttl} uur. Geen echte e-mail, PDF of live AI.`,
                    cta: 'Start met nieuwe opname',
                },
                create: {
                    mode: 'info',
                    meta: 'Stap 2 van 6 · Aanmaken',
                    title: 'Zo start elke opname',
                    body: 'Vul klant en adres in — hier al vooringevuld zodat je snel verder kunt. In productie kun je ook kiezen wie de opname doet; die keuze volgt hier na het opslaan.',
                    aside: 'Adresverrijking (BAG e.d.) gebeurt in productie direct na aanmaken. In de demo zie je dat later in het voorbeelddossier.',
                    cta: 'Begrepen',
                },
                branch: {
                    mode: 'branch',
                    meta: 'Stap 3 van 6 · Rolkeuze',
                    title: 'In productie mailen we nu de klantlink',
                    body: 'Hier sturen we geen e-mail. Kies hoe je verder wilt kijken: als klant de begeleidde opdrachten, of zelf als installateur in de technische werkplek.',
                    aside: 'Beide paden gebruiken dezelfde echte productschermen.',
                },
                customer_start: {
                    mode: 'info',
                    meta: 'Stap 4 van 6 · Klantweergave',
                    title: 'Dit ziet je klant',
                    body: 'Begeleide opdrachten zonder technische ontwerpkeuzes. Deze demoroute is verkort tot een paar representatieve stappen; in productie is de lijst langer en adaptief.',
                    aside: 'Na afronden ga je terug naar het installateursdossier.',
                    cta: 'Begin als klant',
                },
                installer_start: {
                    mode: 'info',
                    meta: 'Stap 4 van 6 · Werkplek',
                    title: 'Jij vult het technische dossier',
                    body: 'Ruimtes, posities, opties, routes en foto’s komen hier samen. Klanttoegang blijft uit totdat jij een gerichte taak activeert.',
                    aside: 'Wil je snel het eindbeeld zien? Gebruik “Toon voorbeelddossier”.',
                    cta: 'Bekijk de werkplek',
                },
                sample_loaded: {
                    mode: 'info',
                    meta: 'Stap 5 van 6 · Voorbeelddossier',
                    title: 'Vooraf gevuld demoscenario',
                    body: 'Woningcontext, foto’s, multi-splitvoorstel en routes zijn fictief en vooraf berekend. Live AI, e-mail en PDF blijven uitgeschakeld.',
                    aside: 'Activeer desgewenst de voorgestelde klanttaak om de echte klantweergave te openen — zonder mail.',
                    cta: 'Verder in het dossier',
                },
                customer_done: {
                    mode: 'info',
                    meta: 'Stap 6 van 6 · Terug naar dossier',
                    title: 'Klantgedeelte afgerond',
                    body: 'In productie krijgt de installateur een afrondingsmail. Hier open je het dossier direct om verder te beoordelen.',
                    cta: 'Naar installateursdossier',
                },
            };

            return map[step] || null;
        },
    }));
}
