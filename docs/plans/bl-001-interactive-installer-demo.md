# BL-001 — Begeleide installateursdemo

> **Documentversie:** 2.0 · **Laatste update:** 2026-08-05 · Onderhoud: zie [AGENTS.md](../../AGENTS.md)

**Implementatiestatus:** begeleidde flow geïmplementeerd; BL-001 blijft `in_progress` tot de afzonderlijke staging- en mobiele visuele smoke is uitgevoerd.

## Besluit

De publieke demo start als een **echte installateursopname**: tijdelijke tenant → dashboard → *Nieuwe opname* (vooringevuld) → rolkeuze i.p.v. mail → klant- of installateurspad, begeleid met pop-ups.

## Verkoopverhaal

1. Welkom als tijdelijke installateur (geen account).
2. Aanmaken van een opname zoals na een bestaande aanvraag.
3. Geen e-mail: kies *Doorgaan als klant* of *Zelf de opname doen*.
4. Klantpad: verkorte representatieve wizard; installateurspad: echte werkplek + optioneel voorbeelddossier.
5. Sample-dossier toont BAG-/foto-/AI-context als vooraf berekende fictieve inhoud.
6. Gerichte klanttaak activeert klantweergave zonder mail.

## Technische aanpak

- `StartDemoIntake` provisiont alleen ephemeral company/user.
- Redirect naar `dashboard`; intake ontstaat via `intakes.create` / `intakes.store` met `is_demo=true`.
- `RestrictPublicDemoSession` staat create/store/address-suggestions toe tot er één intake is; daarna alleen dat dossier.
- `ChooseDemoContributionPath` + `demo.path.choose` vervangt mailen.
- `LoadDemoSurveyScenario` laadt het bestaande demoscenario op verzoek.
- Coachmarks: Alpine `demoGuide` (installer) + native dialog (klant).
- Verkorte klantroute via `config('intake.demo.short_customer_question_keys')`.
- Geen live AI, mail of PDF; TTL + purge inclusief orphaned demo-workspaces.

## Acceptatiecriteria

- Gast start demo → welkomstpopup op dashboard → vooringevulde *Nieuwe opname*.
- Na opslaan: geen mail; modal met beide vervolgpaden.
- Beide paden hebben zichtbare stappen + uitlegpopups.
- Klantpad is kort maar echt (`/o/{token}`); installateurspad gebruikt echte workspace + optioneel voorbeelddossier.
- Isolatie, TTL-purge, AI/mail/PDF-blokkades blijven intact.
- Pint, PHPStan/Larastan, Pest en Vite zijn groen.
