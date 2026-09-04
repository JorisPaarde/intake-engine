# Browser-testflow (agent-speelboek)

> **Documentversie:** 1.0 · **Laatste update:** 2026-09-04 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

**Status:** dit is het **enige** stapsgewijze speelboek voor visuele browser-QA. [docs/functional-test-status.md](functional-test-status.md) is de uitslagentabel (wat écht gezien is), geen stappenlijst. Pest/Livewire/HTTP-tests tellen **niet** als uitvoering van dit document.

---

## Prompt voor de uitvoerende agent (kopieer dit blok)

```text
Voer docs/browser-test-flow.md uit als een menselijke tester.

Verplicht:
- Echte browser met zichtbaar scherm (Playwright / computer-use / vergelijkbaar).
- Alleen staging: https://staging.intake-engine.nl/ — nooit production (intake-engine.nl).
- Loop Flow A volledig. Daarna Flow B. Flow C alleen als Dicteren zichtbaar is en de browser Web Speech ondersteunt (Chrome).
- Kijk naar wat op het scherm staat, niet naar HTML of netwerk als bewijs van “werkt”.
- Screenshot bij elk checkpoint naar /opt/cursor/artifacts/browser-test-flow/ (blijft buiten git).
- Testdata: fictief, geen echte klantdata. Adres-tip op create: 2037GR + 273 (Haarlem, testadres).
- Desktop 1280×800 én telefoon ~390×844 op Flow A stappen 1–8 en de eerste werkplek/wizard-schermen.
- Na afloop: werk docs/functional-test-status.md bij — alleen stappen die je zelf hebt gezien. Geen aannames, geen groene vinkjes op basis van Pest.
- Commit/PR alleen als de taak dat vraagt; anders lever een rapport: per stap pass/fail + wat je zag + artefactpaden.

Stop en rapporteer als: loginmuur, 5xx, 428 Technical Domain, framework-404 i.p.v. /demo/beeindigd, of als de UI iets anders toont dan het speelboek.
```

---

## Voor de uitvoerende agent: hoe je werkt

1. Lees [README § Omgevingen](../README.md#omgevingen) en [AGENTS.md § Staging-testen](../AGENTS.md#staging-testen-browser--playwright) als je vastloopt — niet als vervanging van de stappen hier.
2. Open een **echte browser**. Curl, `php artisan test`, Livewire::test en “screenshot van de eerste pagina” zijn onvoldoende.
3. Handel als een mens: klik, typ, wacht tot de UI reageert, lees de tekst op het scherm.
4. **Wachten (Livewire):**
   - Tekst/number (`wire:model.blur`): na typen **Tab** of blur, wacht tot **Opgeslagen** (of gelijkwaardige bevestiging) zichtbaar is.
   - Radio/ja-nee (`wire:model.live`): wacht **Opgeslagen** vóór **Volgende**.
   - Foto: wacht tot preview of **Foto opgeslagen**.
5. Eén mislukte checkpoint = die stap `fail` + screenshot + verder alleen als de rest nog zinvol is.
6. Geen echte PII. Geen comments op Slack/GitHub tenzij de taak dat vraagt.

### Omgeving

| | |
|---|---|
| URL | `https://staging.intake-engine.nl/` |
| TLS | geldig Let’s Encrypt — geen `ignoreHTTPSErrors` |
| Productie | verboden voor deze flow |
| Demo-user | `installateur@example.com` bestaat **niet** op staging; de publieke demo logt je zelf in |
| Demo | **Probeer de demo** staat aan tenzij `DEMO_ENABLED=false` (niet in git) |

---

## Flow A — Publieke demo, pad installateur (hoofdflow)

Doel: van marketing-landing tot werkplek met een echte opname, zoals een installateur de demo zou doen.

Testdata (fictief):

- Postcode **2037GR**, huisnummer **273** (tip op het formulier; Haarlem-testadres, geen productieklant).
- **Naam klant:** **Testklant Demo**.
- E-mail: leeg laten (demo mint intern een adres; geen `@demo.invalid` in het formulier).
- Wat de klant wil, plak dit in **Beschrijf wat de klant wil**:

> Airco in de slaapkamer op de eerste verdieping, binnenunit aan de muur, buitenunit in de tuin. Split, koelen. Nieuwbouwwoning, spouwmuur.

### A1. Landing

- [ ] Open `https://staging.intake-engine.nl/` (uitgelogd; zo nodig incognito).
- [ ] Zichtbaar: marketing-landing, geen app-dashboard.
- [ ] Knop **Probeer de demo** is zichtbaar.
- [ ] Geen inlogscherm als enige inhoud.
- Screenshot: `A1-landing.png`

**Fail als:** 5xx, timeout, alleen login, of productie-host.

### A2. Demo starten

- [ ] Klik **Probeer de demo**.
- [ ] Je komt op een **dashboard** (app-schil), niet op create en niet op een 404.
- [ ] Welkomstpopup **Zo werkt de Digitale Opname** (kop exact of zo goed als).
- [ ] Meta-regel **Hoe het werkt**.
- [ ] Body in gewone taal: aanvraag binnen → in eigen woorden invullen → AI haalt woninggegevens op en vult aan → jij beoordeelt / offert sneller, vaak zonder voorbezoek.
- [ ] **Geen** genummerde 6-staps coach en **geen** featuresheet “Wel aan / Bewust uitgeschakeld”.
- [ ] Primaire knop: **Start met nieuwe opname**. Geen verplichte tweede knop; klikken op de grijze achtergrond mag de popup sluiten (dan blijf je op het dashboard — noteer het, geen fail). Voor deze flow klik je de primaire knop.
- Screenshot: `A2-welkom.png`

**Fail als:** popup ontbreekt, je landt op create zonder welkom, of 6-staps stack.

### A3. Door naar nieuwe opname

- [ ] Klik **Start met nieuwe opname**.
- [ ] Popup is weg.
- [ ] Pagina **Nieuwe demo-opname** (of gelijke kop).
- [ ] Geen tweede welkomstpopup over de create-pagina.
- Screenshot: `A3-create.png`

### A4. Create-formulier — lege start + tip

- [ ] **Postcode** is leeg (geen `value` 2037GR). Placeholder/tip telt niet als vooringevuld.
- [ ] **Huisnummer** is leeg.
- [ ] **Naam klant** is leeg. Placeholder **Bijv. Familie de Vries** mag; dat is géén vooringevulde naam.
- [ ] Tipkader: **Tip om te proberen:** **2037GR** + **273** (Bernadottelaan 273, Haarlem).
- [ ] **E-mailadres** mag leeg (in de demo geen mail; HTML-`required` staat uit).
- [ ] Sectie **AI vult de vragen in (optioneel)** met veld **Beschrijf wat de klant wil**.
- [ ] Hulptekst: “Schrijf of dicteer wat de klant wil. De AI vult in wat zeker genoeg is. Alleen open vragen blijven over.”
- [ ] Geen aparte keuzelijst Merk / type woning / binnen-unit als hoofdinvoer.
- [ ] Knop **Dicteren** mag verschijnen na paginaload (Chrome/Edge, Web Speech); in Firefox blijft hij `hidden` — geen fail van Flow A.
- Screenshot: `A4-create-leeg.png`

**Fail als:** adres of klantnaam in het `value` van het veld staat, of oude multi-keuze-intentvelden i.p.v. één beschrijvingsveld.

### A5. Invullen en opslaan

- [ ] Vul **Postcode** `2037GR` en **Huisnummer** `273`. Tab/blur. Wacht tot **Straat en huisnummer** en **Plaats** automatisch gaan (verwacht iets als Bernadottelaan 273 / Haarlem). Als lookup faalt: noteer het; je mag straat/plaats zelf invullen met die waarden.
- [ ] Vul **Naam klant** `Testklant Demo`. E-mail leeg laten.
- [ ] Plak de testdata-zin in **Beschrijf wat de klant wil**.
- [ ] Klik **Opname aanmaken** (niet “Opslaan en link mailen” — die tekst hoort niet in de demo).
- [ ] Wacht; adresverrijking kan enkele seconden duren. Geen kale LiteSpeed-pagina “temporarily busy” (503).
- [ ] Geen validatiefout op velden die je wél vulde.
- Screenshot: `A5-na-submit.png` (of eerste scherm ná submit)

**Fail als:** submit doet niets, 422/5xx, of je blijft op create zonder foutmelding.

### A6. Rolkeuze

- [ ] Modal: kop in de trant van **Adresgegevens staan al in de opname** / **Hoe wil je verder?**
- [ ] **Zelf de opname doen** is de primaire (indigo) knop.
- [ ] **Bekijk wat de klant ziet** is de tweede (outline) knop.
- [ ] Klikken buiten de modal sluit hem **niet** (rolkeuze is verplicht).
- Screenshot: `A6-rolkeuze.png`

**Fail als:** je skip’t dit scherm automatisch, of alleen klantpad zonder keuze.

### A7. Werkplek installateur

- [ ] Kies **Zelf de opname doen**.
- [ ] Werkplek/dossier van déze opname (niet opnieuw create, niet marketing-landing).
- [ ] Adres of klantnaam van A5 is herkenbaar.
- [ ] Geen klantwizard als hoofdscherm.
- [ ] Demo-banner mag; géén lange “Wel aan / uit”-lijst.
- Screenshot: `A7-werkplek.png`

### A8. Voorbeelddossier (optioneel op dit scherm)

- [ ] Als **Toon voorbeelddossier** (of gelijke CTA) zichtbaar is: klikken.
- [ ] Dossier krijgt voorbeeldinhoud (plekken/foto’s/opstelling) zonder de pagina te slopen.
- [ ] Als de CTA ontbreekt omdat er al inhoud is: noteer `n.v.t.` — geen fail.
- Screenshot: `A8-voorbeeld.png` of `A8-nvt.png`

### A9. Luchtfoto / kaart (als zichtbaar)

- [ ] Als een luchtfoto/kaart van de locatie zichtbaar is: het is een echte kaart/foto, geen kapotte image-icon.
- [ ] Geen harde eis dat PDOK altijd laadt (externe dienst); wél noteren of hij laadt of een nette fallback toont.
- Screenshot: `A9-kaart.png`

### A10. Klaar-voor-offerte / sticky (niet blokkeren)

- [ ] Kijk of er een sticky of status is over dossier/offerte.
- [ ] Sticky mag inhoud tonen ook als het dossier nog niet “klaar voor offerte” is — dat is verwacht.
- [ ] Niet proberen de hele airco-opstelling af te maken tenzij je tijd hebt (dan optioneel A11).
- Screenshot: `A10-status.png`

### A11. Optioneel — één plek in de werkplek

Alleen als A7 stabiel is en je nog ~10 minuten hebt:

- [ ] Open of voeg een plek/ruimte toe als de UI dat toelaat zonder wizard-gedoe.
- [ ] Sla een veld op; bevestiging zichtbaar.
- [ ] Terug naar dossieroverzicht: wijziging nog zichtbaar.
- Screenshot: `A11-plek.png`

### A12. Demo beëindigen

- [ ] Klik **Demo beëindigen** in de app-nav (niet het gewone `/logout` van een vast account).
- [ ] Bevestig de browser-`confirm`: “Weet je zeker dat je de demo wilt beëindigen? Demogegevens verdwijnen.”
- [ ] Land op `/demo/beeindigd` met kop **Demo beëindigd** (of **Deze demo is verlopen**). CTA’s: **Naar de homepage** / **Nieuwe demo starten**.
- [ ] **Geen** Laravel-404, **geen** echt `/login` als eindscherm.
- Screenshot: `A12-beeindigd.png`

**Fail als:** framework-404, hangende sessie op een dode demo-URL, of production-login.

### A13. Telefoon (viewport ~390×844)

Herhaal A1–A7 (nieuw incognito of nieuwe demo). Niet heel A11 nodig.

- [ ] **Probeer de demo** tiktbaar, niet afgesneden.
- [ ] Welkomstpopup: titel + primaire knop in beeld zonder horizontale scroll-nachtmerrie.
- [ ] Create: postcode, huisnummer, **Naam klant**, beschrijvingsveld en **Opname aanmaken** bereikbaar.
- [ ] Rolkeuze-knoppen stapelen netjes.
- [ ] Werkplek: hoofdacties bereikbaar.
- Screenshots: `A13-landing-mobile.png`, `A13-welkom-mobile.png`, `A13-create-mobile.png`, `A13-werkplek-mobile.png`

---

## Flow B — Publieke demo, pad klant (tweede pad)

Doel: dezelfde demo, maar **Bekijk wat de klant ziet** — volledige airco-klantwizard, geen ingekorte demo-vragenlijst.

Start: nieuwe demo via A1–A6 (niet verdergaan in de oude installateur-sessie).

### B1. Klantpad kiezen

- [ ] Op rolkeuze: **Bekijk wat de klant ziet**.
- [ ] Banner in de trant van **Demo — wat de klant ziet** (installateurstaal, kort).
- [ ] **Geen** featuresheet “Wel aan / Bewust uitgeschakeld”.
- [ ] Je zit in de **klantwizard** (één vraag per scherm, markering **Vraag X van Y** of gelijkwaardig).
- Screenshot: `B1-klant-start.png`

**Fail als:** ingekorte “short customer”-lijst, of installateurswerkplek i.p.v. wizard.

### B2. Eerste vragen als een klant

- [ ] Beantwoord 3–5 zichtbare stappen met fictieve, onschuldige antwoorden.
- [ ] Na elk antwoord: wacht **Opgeslagen** (of preview bij foto) vóór **Volgende**.
- [ ] Geen technische ontwerpvragen die een installateur zou moeten beantwoorden als enige pad (merk/type als verplichte expertkeuze is verdacht — noteer het).
- [ ] Voortgang **Vraag X van Y** loopt mee.
- Screenshots: `B2-vraag-1.png` … (minstens drie)

**Fail als:** “Afronden” zonder foutmelding stil faalt; rode alert “Nog niet alles is ingevuld” terwijl je verplicht veld overslaat is juist gedrag — noteer de tekst.

### B3. Foto-stap (als de wizard er een toont)

- [ ] Upload een kleine testhuis-foto (geen gezichten, geen echte meter met naam).
- [ ] Preview of **Foto opgeslagen**.
- [ ] Volgende stap bereikbaar.
- Screenshot: `B3-foto.png`
- Geen foto-stap in de eerste 5 schermen: `n.v.t.`, geen fail.

### B4. Terug naar installateur / demo-einde

- [ ] Als de UI een weg terug naar de werkplek biedt: gebruik die; dossier toont klantantwoorden of voortgang.
- [ ] Anders: demo beëindigen zoals A12.
- Screenshot: `B4-terug-of-einde.png`

---

## Flow C — Dicteren (alleen Chrome / Web Speech)

Niet verplicht. Alleen als **Dicteren** op create zichtbaar is.

- [ ] Klik **Dicteren**; microfoon-toestemming mag je weigeren in een headless/cloud-VM — noteer dan `blocked by environment`.
- [ ] Als toestemming lukt: kort iets inspreken; tekst landt in **Beschrijf wat de klant wil**.
- [ ] Opslaan werkt daarna zoals A5.
- Screenshot: `C1-dicteren.png`

Cloud-agents zonder microfoon: markeer C als `skipped — geen mic`, geen fail van het speelboek.

---

## Flow D — Negatieve checks (kort)

Alleen op staging, na A of in een verse demo.

- [ ] Uitgelogde gebruiker: `/login` is een loginpagina, geen demo-dashboard.
- [ ] Na **Demo beëindigen** opnieuw een oude demo-URL plakken (werkplek-URL uit A7 indien nog in history): nette **beeindigd**-pagina of veilige landing, geen 500, geen andermans dossier.
- [ ] **Niet** naar `intake-engine.nl` (productie) gaan “om te vergelijken” in dezelfde sessie.

---

## Rapportage

Lever dit af (in de PR, het agentrapport, of beide):

```text
Datum / agent:
Omgeving: staging.intake-engine.nl
Viewport: desktop … / mobile …
Browser:

Flow A: pass / fail / partial
Flow B: pass / fail / partial / skipped
Flow C: pass / skipped
Flow D: pass / fail / skipped

Afwijkingen (schermtekst vs. speelboek):
- …

Artefacten: /opt/cursor/artifacts/browser-test-flow/…

Daarna: docs/functional-test-status.md bijgewerkt voor de stappen die ik zelf zag (ja/nee).
```

Regels voor de statusdoc: geen “werkt op staging” op basis van dit speelboek alleen — alleen na echte uitvoering. Nieuwe UI zonder testrun: voeg daar een `todo`-regel toe, vink niets groen.

---

## Wat dit speelboek bewust niet is

- Geen vervanging van `composer check` / Pest.
- Geen productie-testdraaiboek.
- Geen volledige airco-offerte tot in de details (dat is een latere, langere ronde; zie backlog als je zo’n flow toevoegt).
- Geen Dev-admin (`/dev`) tenzij een aparte taak dat vraagt.
