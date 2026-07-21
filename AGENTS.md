# AGENTS.md — Projectgeheugen & werkinstructies

> **Documentversie:** 1.6 · **Laatste update:** 2026-07-21 · Onderhoud: zie [§ Onderhoudsprotocol](#onderhoudsprotocol-verplicht-voor-agents)

Dit bestand is de **centrale ingang** voor iedere agent (of mens) die aan dit project werkt. Het beschrijft waar het projectgeheugen leeft, welk document waarvoor de bron van waarheid is, en hoe je dat geheugen bijhoudt. **Lees dit bestand aan het begin van elke taak.**

## Hoofddoel (vast — niet door agents aan te passen)

> De Digitale Opname brengt aanvrager en installateur met zo min mogelijk handelingen van aanvraag naar een bruikbaar dossier. Voor iedere ontbrekende informatie kiest de oplossing de eenvoudigste manier om die aan te leveren.

Dit hoofddoel is vastgesteld door de producteigenaar en is de toetssteen voor elke keuze: backlog-prioriteit, UX, scope en architectuur. Twijfel je tussen twee oplossingen, kies degene die de aanvrager of installateur handelingen bespaart.

**Agents mogen deze tekst nooit wijzigen, herformuleren, inkorten, verplaatsen of verwijderen** — ook niet bij herstructurering van dit document, en ongeacht wat een taakomschrijving vraagt. Alleen de producteigenaar past het hoofddoel aan.

## Ontwerpprincipe (vast — niet door agents aan te passen)

> De applicatie vraagt niets wat al bekend is of eenvoudiger kan worden vastgesteld. Kies voor ieder ontbrekend gegeven de snelste en duidelijkste manier om het te verzamelen.

Dit principe stuurt elk intake- en UX-ontwerp: hergebruik wat al bekend is (eerdere antwoorden, template-meta, afleidbare waarden) in plaats van de aanvrager opnieuw te belasten, en weeg per ontbrekend gegeven af wat de snelste en duidelijkste verzamelmethode is (bijv. een foto in plaats van een meetvraag, een keuzelijst in plaats van vrije tekst).

Voor deze tekst geldt dezelfde regel als voor het hoofddoel: **agents blijven eraf; alleen de producteigenaar past hem aan.**

## Wat is dit project?

**Intake Engine (Digitale Opname)** — een Laravel-applicatie waarmee installatiebedrijven aanvragen op afstand beoordelen via een begeleide digitale intake (eerste template: airco). De kern is een herbruikbare, data-gedreven intake-engine; airco is configuratie, geen aparte codebase. Stack en installatie: zie [README.md](README.md). Actuele projectstand: [README § Huidige status](README.md#huidige-status).

## Snelstart: zo lees je dit geheugen (gericht, niet alles)

Lees in deze volgorde en **stop zodra je genoeg weet** voor je taak:

1. **Dit bestand** — de geheugenkaart en de taakroutingtabel hieronder.
2. **[README § Huidige status](README.md#huidige-status)** — waar het project staat (paar regels).
3. **[docs/backlog.md](docs/backlog.md)** — alleen de **overzichtstabel** bovenaan; open daarna alleen het/de BL-item(s) dat je taak raakt.
4. **Alleen de documenten uit de taakroutingtabel** voor jouw taaktype — en daarbinnen gericht op sectie (elke doc heeft een inhoudsopgave via de koppen).

Lees **níet** standaard alle docs integraal door. De geheugenkaart, versieheaders en statusregels bovenaan elk document bestaan juist zodat je in enkele regels kunt bepalen of een document relevant is en gericht kunt springen. Diepgang haal je pas op als je taak dat vereist — gefundeerd werken betekent de *juiste* bron raadplegen, niet *alle* bronnen.

### Taakrouting: wat lees je bij welk taaktype

| Taaktype | Lees (gericht) | Meestal niet nodig |
|----------|----------------|--------------------|
| Intake-flow, vragen, regels, compleetheid | `docs/intake-engine.md` (+ relevante tabellen in `docs/database.md`) | uploads, AI, deploy |
| Datamodel / migraties | `docs/database.md` + ADR-0001 | intake-engine details, deploy |
| Foto's / uploads / media | `docs/uploads.md` (+ `docs/DEPLOYMENT.md` § PHP upload-limieten) | AI, database-details |
| AI-functionaliteit | `docs/ai.md` + ADR-0005 | uploads, deploy |
| Deploy, staging, CI, server | `docs/DEPLOYMENT.md` | engine-, AI- en database-docs |
| Functioneel testen op staging | `docs/functional-test-status.md` + BL-002 in de backlog | architectuur-docs |
| Architectuurbrede keuze / nieuw domein | `docs/ARCHITECTURE.md` + relevante ADRs | — |
| Docs/geheugen zelf onderhouden | dit bestand volledig | — |

Twijfel je onder welk taaktype je werk valt, gebruik dan de geheugenkaart hieronder als vangnet.

## Geheugenkaart: welk document is waarvoor de bron van waarheid

| Vraag | Bron van waarheid |
|-------|-------------------|
| Wat is het hoofddoel van het product? | [§ Hoofddoel](#hoofddoel-vast--niet-door-agents-aan-te-passen) in dit bestand (vast, alleen producteigenaar) |
| Welk vast ontwerpprincipe geldt bij elke intake-/UX-keuze? | [§ Ontwerpprincipe](#ontwerpprincipe-vast--niet-door-agents-aan-te-passen) in dit bestand (vast, alleen producteigenaar) |
| Wat is het product, hoe installeer/start ik het? | [README.md](README.md) |
| Wat is er wanneer gewijzigd (code + docs)? | [CHANGELOG.md](CHANGELOG.md) |
| Welke architectuurkeuzes gelden en waarom? | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Waarom is een onomkeerbare keuze gemaakt? | [docs/decisions/](docs/decisions/) (ADRs, immutabel) |
| Hoe zit het databaseschema in elkaar? | [docs/database.md](docs/database.md) |
| Hoe werken templates, regels, compleetheid? | [docs/intake-engine.md](docs/intake-engine.md) |
| Hoe werken uploads/media en limieten? | [docs/uploads.md](docs/uploads.md) |
| Wat doet AI wel/niet, en hoe? | [docs/ai.md](docs/ai.md) |
| Hoe deployt het naar staging/cPanel? | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| Welke env/host-instellingen moet de producteigenaar nog zetten? | [docs/DEPLOYMENT.md § Handmatige acties](docs/DEPLOYMENT.md#handmatige-acties-producteigenaar) |
| Welke fasen zijn (op)geleverd? | [docs/implementation-plan.md](docs/implementation-plan.md) (historie, afgerond) |
| Wat is het nog te bouwen werk? | [docs/backlog.md](docs/backlog.md) (**enige** backlog) |
| Wat is functioneel getest (handmatig)? | [docs/functional-test-status.md](docs/functional-test-status.md) |
| Welke werkafspraken gelden (branching, taal, kwaliteit, privacy)? | [§ Werkafspraken](#werkafspraken) in dit bestand |
| Hoe werk ik als agent, hoe onderhoud ik dit geheugen? | dit bestand (AGENTS.md) |
| Welke cloud-agent setup/tips besparen tijd? | [§ Tips voor cloud-agents](#tips-voor-cloud-agents-snelle-setup) in dit bestand |

Regels bij de kaart:

1. **Eén bron per feit.** Staat iets in twee documenten, dan is de tabel hierboven leidend; het andere document verwijst alleen (link), kopieert niet.
2. **ADRs zijn immutabel.** Een geaccepteerde ADR wijzig je niet inhoudelijk. Nieuwe inzichten = nieuwe ADR (volgend nummer, `docs/decisions/NNNN-titel.md`) die de oude vervangt; zet in de oude ADR alleen `Status: Superseded by ADR-NNNN`.
3. **`docs/implementation-plan.md` is historie.** Fase 1–6 zijn klaar; voeg daar geen nieuw werk toe. Nieuw werk gaat naar `docs/backlog.md`.
4. **`app/Domains/AI/Prompts/*/prompt.md`** is géén documentatie maar runtime-data met eigen versionering via `meta.php` (bijv. `summary-v1`). Wijzig je een prompt, bump dan de versie in `meta.php`.

## Versionering

Er zijn vier versioneringslagen; verwar ze niet:

| Laag | Mechanisme | Regel |
|------|-----------|-------|
| Applicatie / releases | [CHANGELOG.md](CHANGELOG.md), semver (`0.x.y`) | Alle noemenswaardige wijzigingen onder `[Unreleased]`; bij een release wordt die sectie een versienummer + datum |
| Documenten | Headerregel `Documentversie: X.Y` per doc | Zie bump-regels hieronder |
| Intake-templates | `intake_template_versions` in DB + `database/data/templates/` | Gepubliceerde versies zijn immutabel; wijziging = nieuwe versie (ADR-0001) |
| AI-prompts | `meta.php` naast `prompt.md` | Elke promptwijziging = versiebump (bijv. `summary-v2`) |

### Bump-regels voor documentversies

Elk beheerd document (alle `docs/*.md` behalve ADRs, plus README en dit bestand) heeft bovenaan:

```markdown
> **Documentversie:** X.Y · **Laatste update:** JJJJ-MM-DD · Onderhoud: zie [AGENTS.md](../AGENTS.md)
```

- **Minor bump (X.Y → X.Y+1):** inhoudelijke aanvulling of correctie (nieuwe sectie, gewijzigde feiten, statusupdate).
- **Major bump (X.Y → X+1.0):** herstructurering of gewijzigde strekking (het document zegt iets wezenlijk anders dan voorheen).
- **Geen bump:** pure typo-/linkfixes.
- Werk **altijd** de `Laatste update`-datum bij als je de versie bumpt.

## Onderhoudsprotocol (verplicht voor agents)

### Bij de start van elke taak

Volg de [Snelstart](#snelstart-zo-lees-je-dit-geheugen-gericht-niet-alles): dit bestand → README-status → backlog-overzichtstabel → alleen de docs uit de taakroutingtabel. Check daarbij of je taak al als BL-item bestaat (status, afhankelijkheden, eerdere notities).

### Tijdens het werk

- Ontdek je dat een document niet klopt met de werkelijkheid (code, server, gedrag)? **Corrigeer het document in dezelfde PR** en bump de documentversie.
- Neem je een onomkeerbare beslissing (architectuur, security, datamodel)? Schrijf een ADR (volgend nummer) in `docs/decisions/`.
- Stel je werk uit of scope je iets bewust weg? Voeg het toe aan `docs/backlog.md` (met status `backlog`), niet alleen aan een PR-omschrijving of changelog-notitie.
- Ontdek je een **herbruikbare les** over werken aan deze repo (setup, staging, Livewire, cloud-run valkuilen, handige paden)? Werk [§ Tips voor cloud-agents](#tips-voor-cloud-agents-snelle-setup) bij in dezelfde PR. Kort, scannbaar, geen essay — alleen wat de volgende agent tijd bespaart.

### Vóór het afronden van elke PR (docs-definition-of-done)

Loop deze checklist na; sla niets over:

1. **CHANGELOG.md** — voeg je wijziging toe onder `[Unreleased]` (Added/Changed/Fixed/Config/Known limitations).
2. **docs/backlog.md** — zet afgeronde items op `done` (met datum + PR-verwijzing), voeg nieuw ontdekt/uitgesteld werk toe.
3. **Inhoudelijke docs** — werk de docs bij die je werkgebied dekken (geheugenkaart) en bump hun documentversie.
4. **README.md** — alleen bijwerken als stack, installatie, omgevingen of de sectie "Huidige status" wijzigt.
5. **docs/functional-test-status.md** — **niet** invullen op basis van geautomatiseerde tests of aannames; alleen de daadwerkelijk testende agent/tester werkt dit bij. Introduceer je nieuwe functionaliteit, voeg dan wél een `todo`-regel toe.
6. **[§ Tips voor cloud-agents](#tips-voor-cloud-agents-snelle-setup)** — bijgewerkt als je iets nieuws leerde dat de volgende agent helpt; anders overslaan. Verwijder verouderde tips die niet meer kloppen.
7. **Kwaliteitspoort** — `composer check` (Pint + PHPStan + Pest) groen.

### Houd het geheugen scanbaar

Deze regels zorgen dat de snelstart-routine blijft werken en agents niet alles hoeven te herlezen:

- **Eerste regels dragen de kern.** Elk beheerd document opent met titel, versieheader en (waar zinvol) één statusregel. Wie alleen die regels leest, weet of het document relevant en actueel is.
- **Stabiele koppen.** Sectiekoppen (en dus anchors) niet hernoemen zonder de verwijzingen in de geheugenkaart, taakroutingtabel en andere docs mee te wijzigen.
- **Nieuwe informatie op de verwachte plek.** Voeg feiten toe in het document (en de sectie) waar de geheugenkaart ze verwacht; maak geen parallelle plekken.
- **Overzichtstabellen actueel houden.** De backlog-overzichtstabel en de geheugenkaart hierboven zijn de snelle indexen; wijzig je items of documenten, werk die tabellen in dezelfde PR bij. (De README bevat bewust géén eigen documentatietabel meer — alleen een verwijzing naar de geheugenkaart.)

### Wat je níet doet

- **Het [Hoofddoel](#hoofddoel-vast--niet-door-agents-aan-te-passen) of [Ontwerpprincipe](#ontwerpprincipe-vast--niet-door-agents-aan-te-passen) aanpassen.** Die teksten zijn van de producteigenaar; agents blijven eraf, in elke vorm.
- Geen nieuwe top-level `.md`-bestanden aanmaken zonder ze aan de geheugenkaart (hierboven) toe te voegen.
- Geen dubbele waarheid creëren (zelfde feit uitgeschreven in twee docs).
- Geen ADRs herschrijven, geen `[Unreleased]`-changelog-items verwijderen.
- Geen statusclaims ("werkt op staging") zonder dat `docs/functional-test-status.md` dat dekt.

## Backlogproces

`docs/backlog.md` is de enige backlog. GitHub-issues mogen bestaan (bijv. issue #5), maar het backlog-document verwijst ernaar en blijft leidend voor status.

- Elk item heeft een stabiel ID (`BL-NNN`), status (`backlog` / `ready` / `in_progress` / `done` / `dropped`), prioriteit (`high` / `medium` / `low`) en waar nodig afhankelijkheden.
- Nieuw item = volgend vrij `BL`-nummer; hergebruik nooit nummers.
- Start je aan een item: zet status op `in_progress` in je feature branch.
- Klaar: status `done` + datum + PR-nummer. Verwijder `done`-items niet; ze zijn geheugen.
- Vervalt een item: status `dropped` + één regel waarom.

## Werkafspraken

Dit is de bron van waarheid voor deze afspraken; andere documenten (waaronder de README) verwijzen hierheen en herhalen ze niet.

- **Branching:** `main` is deploybaar; feature branches + PR, CI groen vóór merge. Merge naar `main` deployt automatisch naar staging.
- **Taal:** documentatie en UI in het Nederlands; code, keys en identifiers in het Engels.
- **Kwaliteit:** `composer check` per PR; migrations reproduceerbaar; geen handmatige staging-DB-edits.
- **Privacy:** geen echte klantdata in seeders/tests/docs; tokens en API-keys nooit in logs of git.

## Tips voor cloud-agents (snelle setup)

Praktische lessen uit cloud-runs. Doel: sneller groen zonder opnieuw te ontdekken wat ontbreekt. **Onderhoud verplicht:** zie [§ Onderhoudsprotocol](#onderhoudsprotocol-verplicht-voor-agents) — nieuwe lessen horen hier in dezelfde PR.

### Omgeving (vaak leeg bij start)

- **PHP 8.4 vereist** voor `composer install` met de huidige lockfile (Symfony 8 vraagt `>=8.4.1`). PHP 8.3 uit Ubuntu-default is te oud.
- Op Ubuntu 24.04: `ppa:ondrej/php`, daarna o.a. `php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-intl`. Zet `php` via `update-alternatives` op 8.4.
- **Composer** staat vaak niet in PATH. Installeer naar `~/bin/composer` (`mkdir -p ~/bin` eerst) en zet `PATH="$HOME/bin:$PATH"`.
- **Node/npm** is meestal wél aanwezig (nvm). Voor HTTP-featuretests die layouts renderen: `npm ci && npm run build` — anders faalt Vite op ontbrekend `public/build/manifest.json`.
- **Imagick HEIC/HEIF** is nodig voor BL-008. Snel checken: `php -r 'var_dump(class_exists("Imagick"), Imagick::queryFormats("HEIC"), Imagick::queryFormats("HEIF"));'`.
- Lokaal/.env voor tests: `cp .env.example .env && php artisan key:generate`. Tests draaien op **sqlite `:memory:`** (zie CI); geen MySQL nodig voor `composer check`.
- Check of er al een async install loopt (`/tmp/cursor/async-install/`); in JIT-omgevingen kan die map leeg zijn — zelf bootstrapen is dan sneller dan wachten.

### Werken in deze repo

- Taak → backlog-ID: vage wensen mappen op de **overzichtstabel** in `docs/backlog.md` (kolom `#` = aanbevolen volgorde). Lopend werk (`in_progress`) afronden vóór nieuw high-item starten. Zet status in dezelfde PR.
- Klantflow: `app/Livewire/Customer/IntakeWizard.php` + `IntakeStepBuilder` + `resources/views/livewire/customer/intake-wizard.blade.php`; engine: `VisibilityResolver` / `CompletenessChecker` / `AnswerValueReader`.
- Airco-templatebron: `database/data/templates/airco/v1.php` (wijziging = nieuwe templateversie, ADR-0001).
- Kwaliteitspoort: `composer check` (= Pint + PHPStan level 6 + Pest) vóór je “klaar” claimt.
- Featuretests met `Livewire::test(...)` hebben geen Vite-build nodig; `$this->get(...)` die een layout met `@vite` raakt wél.
- Docs-DoD: CHANGELOG `[Unreleased]`, geraakte docs + versiebump, backlog-status, eventueel `todo` in `docs/functional-test-status.md`, en deze tipsectie als je iets nieuws leerde.
- Branchnaam: `cursor/<korte-naam>-<suffix>` zoals de run voorschrijft; base `main`; PR via de cloud-PR-tool (niet `gh pr create` tenzij dat de enige optie is).

### Staging-testen (browser / Playwright)

- Omgevingen zijn gescheiden: `main` → `staging.intake-engine.nl` / `apps/intake-engine-staging`; `v*` of bewuste dispatch → `intake-engine.nl` / `apps/intake-engine-production`. Gebruik nooit database, `.env`, app-key, sessiecookie of storage van de andere omgeving.
- Kopieer je een bestaande release als bootstrap voor een nieuwe omgeving, verwijder dan `bootstrap/cache/config.php`, `routes-*.php` en `events.php` **vóór** de eerste Artisan-opdracht. Anders kunnen migraties/storage-links nog de gecachete bron-DB en bronpaden gebruiken; `deploy/activate.sh` doet dit en controleert ook `APP_ENV`.
- Staging-URL: zie README § Omgevingen; `staging.intake-engine.nl` heeft geldig Let’s Encrypt-TLS, dus geen `ignoreHTTPSErrors` of cPanel-cookie gebruiken.
- **Legacy technical domain:** alleen de oude `.cpanel.site`-host gebruikte een 428 “Technical Domain”-scherm en `cpanel_tech_domain=1`. Gebruik die host niet meer voor smoketests; hij is geen actuele omgevings-URL.
- Klanttokens zijn `Str::random(64)` → charset `[A-Za-z0-9]`, niet hex. Lees de link via `#customer-link` (`inputValue`), niet via een te strakke regex op page HTML.
- Wizard (BL-018): één vraag per scherm; markering `Vraag X van Y`. Velden: `wire:model.blur` (text/number) → na `fill` een echte `Tab`/blur en wacht op “Opgeslagen”; radios/booleans: `wire:model.live` → wacht op “Opgeslagen” vóór “Volgende”. Foto’s: wacht op preview/`Foto opgeslagen`.
- “Afronden” faalt zonder zichtbare alert als Livewire de 428-interstitial krijgt i.p.v. de component — tech-domain-cookie eerst fixen, daarna pas debuggen op compleetheid.
- Progress-% telt ook optionele lege vragen mee; 98% ≠ “één verplicht veld mist”. Bij blokkade: let op de rode alert “Nog niet alles is ingevuld” / “Beantwoord eerst…”.
- Demo-user `installateur@example.com` ontbreekt op staging (deploy seedt alleen templates) → registreren als fallback. **Start demo** staat standaard aan (`DEMO_ENABLED` default `true`); alleen uit bij expliciet `DEMO_ENABLED=false` in `shared/.env` (niet in git).
- AI-samenvatting op staging is vaak `blocked` bij `AI_PROVIDER=null` (soft-fail by design) — geen fail, wel zo noteren in `docs/functional-test-status.md`.
- Screenshots/resultaten: `/opt/cursor/artifacts/…` (blijft buiten git).
