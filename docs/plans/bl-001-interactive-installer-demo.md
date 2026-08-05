# BL-001 — Begeleide installateursdemo

> **Documentversie:** 2.3 · **Laatste update:** 2026-08-05 · Onderhoud: zie [AGENTS.md](../../AGENTS.md)

**Implementatiestatus:** begeleidde flow + live verrijking/AI in demo geïmplementeerd; BL-001 blijft `in_progress` tot de afzonderlijke staging- en mobiele visuele smoke is uitgevoerd.

## Besluit

De publieke demo start als een **echte installateursopname**: tijdelijke tenant → dashboard → *Nieuwe opname* → rolkeuze i.p.v. mail → klant- of installateurspad, begeleid met pop-ups. Prospects typen zelf postcode/huisnummer, zien dat woningdata wordt opgehaald, en dat AI foto’s en tekst kan interpreteren — die paden zijn in de demo niet uitgeschakeld.

## Verkoopverhaal

1. Welkom als tijdelijke installateur (geen account).
2. Aanmaken van een opname: zelf postcode/huisnummer invullen (tipadres beschikbaar); klantnaam is fictief.
3. Na opslaan: adresverrijking en openingszin-interpretatie zichtbaar in het dossier; geen e-mail — kies *Doorgaan als klant* of *Zelf de opname doen*.
4. Klantpad: verkorte representatieve wizard (foto-AI mag meedraaien); installateurspad: echte werkplek + optioneel voorbeelddossier.
5. Sample-dossier blijft een snelle boost met fictieve inhoud; live AI-voorstel vernieuwen en foto-analyse blijven beschikbaar.
6. Gerichte klanttaak activeert klantweergave zonder mail.

## Technische aanpak

- `StartDemoIntake` provisiont alleen ephemeral company/user.
- Redirect naar `dashboard`; intake ontstaat via `intakes.create` / `intakes.store` met `is_demo=true`.
- Adresvelden op create zijn leeg; `DEMO_ADDRESS_*` is alleen tiptekst. Enrichment/intent na opslaan volgt het productiepad.
- `RestrictPublicDemoSession` staat create/store/address-suggestions toe tot er één intake is; daarna alleen dat dossier.
- `ChooseDemoContributionPath` + `demo.path.choose` vervangt mailen.
- `LoadDemoSurveyScenario` laadt het bestaande demoscenario op verzoek.
- Coachmarks: Alpine `demoGuide` (installer) + native dialog (klant).
- Verkorte klantroute via `config('intake.demo.short_customer_question_keys')`.
- AI-acties en -jobs short-circuiten niet meer op `is_demo`; klantmail/notificaties wel. PDF alleen via opt-in BL-051 (`RequestDemoReportPdf`). TTL + purge inclusief orphaned demo-workspaces.

## Acceptatiecriteria

- Gast start demo → welkomstpopup op dashboard → *Nieuwe opname* met lege postcode/huisnummer (+ tipadres).
- Na zelf ingevuld adres + opslaan: geen mail; woninggegevens/luchtfoto zichtbaar waar bronnen aan staan; modal met beide vervolgpaden.
- Beide paden hebben zichtbare stappen + uitlegpopups; AI-foto/tekst/synthese niet geblokkeerd door `is_demo`.
- Klantpad is kort maar echt (`/o/{token}`); installateurspad gebruikt echte workspace + optioneel voorbeelddossier.
- Isolatie, TTL-purge, mail/PDF-blokkades blijven intact.
- Pint, PHPStan/Larastan, Pest en Vite zijn groen.
