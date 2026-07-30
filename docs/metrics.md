# Productmetrics — Digitale Opname

> **Documentversie:** 3.0 · **Laatste update:** 2026-07-30 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: de BL-026-procesmetrics én de BL-042-uitkomstmetrics op de interne route `/metrics` zijn **geïmplementeerd**. Alleen geauthenticeerde, geverifieerde installateurs met `viewAny`-toegang tot opnames kunnen de pagina openen.

## Huidige definities (geïmplementeerd)

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

## Uitkomstmetrics (BL-042, geïmplementeerd)

De installateur legt na offerte, bezoek of plaatsing één actuele `installation_outcomes`-rij vast. Ontbrekende uitkomsten blijven buiten de noemer; afwezigheid wordt nooit als een bespaarde rit geïnterpreteerd.

| Metric | Reproduceerbare definitie |
|--------|---------------------------|
| Op afstand geoffreerd | Uitkomsten zonder locatiebezoek met `result=remote_quote` óf `quote_type=remote`, gedeeld door alle vastgelegde uitkomsten. Een geplaatste installatie zonder bezoek krijgt automatisch `quote_type=remote`. |
| Alleen prijsindicatie | `result=estimate` of `quote_type=estimate`, gedeeld door alle vastgelegde uitkomsten. Deze categorie telt niet mee als definitieve offerte op afstand. |
| Locatiebezoek | `site_visit_occurred=true`, gedeeld door alle vastgelegde uitkomsten. `result=site_visit` zet dit altijd waar. |
| Reden locatiebezoek | Aantallen per gecontroleerde code: onzekere stroom, condens of route; bereikbaarheid; constructie/wandopbouw; klantvoorkeur; anders. Per uitkomst maximaal drie; minimaal één wanneer een bezoek is vastgelegd. |
| Actieve installateurstijd | Handmatig vastgelegde `active_installer_minutes`; mediaan over uitkomsten met een waarde. Het is nadrukkelijk geen wandkloktijd en nog geen automatische sessietimer. |
| Handmatig gemeten klanttijd | `customer_minutes`; mediaan over uitkomsten met een waarde. De bestaande eventgebaseerde klantacties blijven daarnaast zichtbaar. De oude maat **invultijd** wordt alleen voor de volledige klantworkflow berekend, omdat `started_at` in installer-/hybride flows ook installateurswerk of wachttijd kan omvatten. |
| Voorstel aangepast | Uitkomsten waarbij **voorstel vergeleken** is aangevinkt en minimaal één gecontroleerde deltacode staat, gedeeld door alle uitkomsten waarbij het voorstel daadwerkelijk is vergeleken. Codes: configuratie, binnen-/buitenpositie, koel-/condens-/stroomroute en kosten. |
| Montageverrassing | Geplaatste uitkomsten met `minor` of `major`, gedeeld door geplaatste uitkomsten met expliciet `none`, `minor` of `major`. Vrije toelichting wordt nooit als analyticsdimensie gegroepeerd. |
| Gerichte aanvullingsrondes | Bestaand aantal `intake_follow_up_rounds`; totaal en gemiddelde blijven in de procesmetingen zichtbaar. |
| Workflow | Iedere rij toont `customer`, `installer` of `hybrid`, zodat uitkomsten per bijdragevorm te vergelijken zijn. |

Nog niet automatisch gemeten: echte actieve werksessies met idle-timeout, automatisch-vastgesteld aandeel en eerste-ronde-op-afstand-gereed. De UI doet daar geen schijnprecisie over; handmatig vastgelegde minuten zijn als zodanig gelabeld.

### Meetregels

1. Workflow staat op de opname als `customer`, `installer` of `hybrid`.
2. Leg een locatiebezoek en plaatsingsuitkomst expliciet vast; leid “bezoek vermeden” niet af uit een opname zonder uitkomst.
3. Gebruik gecontroleerde codes voor beslisstatus, blokkade, bezoekreden, correctietype en montage-uitkomst.
4. Bewaar geen vrije klanttekst, foto-inhoud, token, adres of modelprompt in analytics-events.
5. Zonder geldige noemer toont `/metrics` `—`; ontbrekende historie wordt niet met aannames gevuld.
6. Vergelijk klant-, installateur- en hybride workflows afzonderlijk voordat productconclusies worden getrokken.

## Privacy

`IntakeMetricsService` leidt procescijfers bij het openen af uit timestamps, relaties en privacyveilige eventtypen; uitkomstcijfers uit de tenantgebonden `installation_outcomes`. `answer_saved` bevat alleen `question_key` en optioneel `section_instance_key`; nooit het antwoord. Het uitkomstevent bevat alleen resultaatstype, bezoekboolean, gecontroleerde codes en ernst. De meetweergave toont geen klantnaam, e-mail, telefoon, token, vrije klanttekst, bestandsnaam, adres of foto-inhoud.

## Nulmeting en verificatie

De reproduceerbare lokale nulmeting in `IntakeMetricsTest` gebruikt vier niet-demo-opnames: 3 gestart, 2 afgerond, 2 beoordeeld en 1 aanvullende ronde. Verwachte uitkomst: **66,7%** afgerond, **1 uur** mediane invultijd, **5** mediane klantacties, **0,3** ronde per gestarte opname, **50,0%** direct genoeg informatie en **2 uur 30 min** mediane tijd tot besluit. Demo-opnames tellen niet mee.

Een aparte BL-042-test gebruikt drie uitkomsten: één op afstand geplaatste installatie, één plaatsing na bezoek en één prijsindicatie. Verwacht: **33,3%** op afstand geoffreerd, **33,3%** prijsindicatie, **33,3%** met locatiebezoek, **20 minuten** mediane installateurstijd, **10 minuten** mediane klanttijd, **50,0%** gewijzigde voorstellen en **50,0%** montageverrassingen. De test controleert ook de exacte bezoek- en deltacodes.

Na iedere wijziging aan lifecycle-events of timestamps moeten de servicetest en de browser-smoke van `/metrics` opnieuw worden uitgevoerd.

Lokale browser-smoke 2026-07-20: **pass** op 1280×720 en 390×844. Auth-redirect/login, actieve periode, wisselen naar Alles, per-opname-link, zes kerncijfers en uitvalweergave werken; documentbreedte op mobiel is exact 390 px (alleen de datatabel scrollt intern) en de browserconsole bleef leeg.

### Staging-smoke na deploy

1. Open als geverifieerde installateur `/metrics`; controleer dat een gast naar `/login` gaat.
2. Vergelijk 30 dagen, 90 dagen en Alles; het aantal opnames mag alleen gelijk blijven of toenemen.
3. Open één regel via `Opname #…` en vergelijk voortgang, rondes en beoordeling met het dossier.
4. Rond een testopname af en beoordeel die; controleer afronding, acties en besluitduur opnieuw.
5. Controleer pagina en HTML-bron op afwezigheid van klantgegevens, vrije antwoorden en tokens.

Status: de BL-026-browser-smoke van 2026-07-20 was **pass**. Herhaal na deploy van deze PR de uitkomstkaarten, bezoekredenen, voorstelafwijkingen en installer-/hybride workflow als **todo** uit [functional-test-status.md](functional-test-status.md).
