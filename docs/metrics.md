# Productmetrics — Digitale Opname

> **Documentversie:** 1.2 · **Laatste update:** 2026-07-20 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

BL-026 maakt de productdoelen meetbaar via de interne route `/metrics`. Alleen geauthenticeerde, geverifieerde installateurs met `viewAny`-toegang tot opnames kunnen deze pagina openen. De pagina heeft cohorten van 30 dagen, 90 dagen en alle niet-demo-opnames.

## Definities

De gekozen periode filtert op `intakes.created_at`. Een opname die binnen het cohort is aangemaakt en later wordt afgerond of beoordeeld, blijft dus bij hetzelfde cohort horen.

| Metric | Reproduceerbare definitie |
|--------|---------------------------|
| Gestart | `started_at` is gevuld. Installateur- of PDOK-prefill start een opname niet. |
| Afrondingspercentage | Opnames met `completed_at` gedeeld door gestarte opnames. Nog niet gestarte verstuurde links tellen niet mee in de noemer. |
| Invultijd klant | Seconden tussen `started_at` en de eerste `completed_at`; aggregaat is de mediaan van afgeronde opnames. Een vervolgronde wijzigt `completed_at` niet. |
| Klantacties | `max(aantal answer_saved-events, actuele niet-vooringevulde niet-fotoantwoorden)` plus klant-events voor upload opslaan/verwijderen, vervolgtekst opslaan, vervolgbestand opslaan/verwijderen en hoofd-/vervolgronde afronden. Aggregaat is de mediaan over gestarte opnames. De `max`-fallback houdt oudere opnames van vóór `answer_saved` bruikbaar. |
| Uitvalpunt | Gestart maar nog niet afgerond, gegroepeerd op `current_question_key`; de interne pagina toont het label uit de gepinde templateversie. Ontbrekende cursors worden `Onbekend uitvalpunt`. |
| Aanvullende rondes | Aantal gekoppelde `intake_follow_up_rounds`; totaal en gemiddelde per gestarte opname. |
| Direct genoeg informatie | De **eerste** beoordeling per opname, waarbij `enough_information=true`, gedeeld door alle opnames met een eerste beoordeling. Het event bewaart alleen beslissing + boolean, geen vrije reviewtekst. Een eerste oordeel `need_more_info` blijft dus `false`, ook als dezelfde reviewrij na een succesvolle vervolgronde later `true` wordt. Historische events zonder boolean gebruiken beslissing/review als gedocumenteerde fallback. |
| Tijd tot besluit | Seconden van `intakes.created_at` tot het eerste `intake_reviewed`-event; fallback is `intakes.reviewed_at` voor oudere data. Een gerichte vervolgvraag geldt als een bepaald volgend besluit. Aggregaat is de mediaan. |

Alle medianen sorteren gehele seconden/aantallen. Bij een even aantal metingen is de mediaan het afgeronde gemiddelde van de twee middelste waarden. Zonder geldige noemer toont de pagina `—`, niet `0%`.

## Privacy

Analytics bewaart geen extra dataset. `IntakeMetricsService` leidt cijfers bij het openen af uit bestaande timestamps, identifiers, relaties en expliciete eventtypen. `answer_saved` bevat alleen `question_key` en optioneel `section_instance_key`; nooit het antwoord. De meetweergave toont geen klantnaam, e-mail, telefoon, token, vrije klanttekst, bestandsnaam of foto-inhoud.

## Nulmeting en verificatie

De reproduceerbare lokale nulmeting in `IntakeMetricsTest` gebruikt vier niet-demo-opnames: 3 gestart, 2 afgerond, 2 beoordeeld en 1 aanvullende ronde. Verwachte uitkomst: **66,7%** afgerond, **1 uur** mediane invultijd, **5** mediane klantacties, **0,3** ronde per gestarte opname, **50,0%** direct genoeg informatie en **2 uur 30 min** mediane tijd tot besluit. Demo-opnames tellen niet mee.

Na iedere wijziging aan lifecycle-events of timestamps moeten de servicetest en de browser-smoke van `/metrics` opnieuw worden uitgevoerd.

Lokale browser-smoke 2026-07-20: **pass** op 1280×720 en 390×844. Auth-redirect/login, actieve periode, wisselen naar Alles, per-opname-link, zes kerncijfers en uitvalweergave werken; documentbreedte op mobiel is exact 390 px (alleen de datatabel scrollt intern) en de browserconsole bleef leeg.

### Staging-smoke na deploy

1. Open als geverifieerde installateur `/metrics`; controleer dat een gast naar `/login` gaat.
2. Vergelijk 30 dagen, 90 dagen en Alles; het aantal opnames mag alleen gelijk blijven of toenemen.
3. Open één regel via `Opname #…` en vergelijk voortgang, rondes en beoordeling met het dossier.
4. Rond een testopname af en beoordeel die; controleer afronding, acties en besluitduur opnieuw.
5. Controleer pagina en HTML-bron op afwezigheid van klantgegevens, vrije antwoorden en tokens.

Status staging-smoke: **todo** tot BL-026 is gedeployed.
