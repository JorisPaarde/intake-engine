# Productmetrics — Digitale Opname

> **Documentversie:** 2.0 · **Laatste update:** 2026-07-30 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: de BL-026-metrics en interne route `/metrics` zijn **geïmplementeerd**. De uitkomstmetrics voor het nieuwe productmodel zijn **gedefinieerd maar nog niet geïnstrumenteerd** (BL-042). Alleen geauthenticeerde, geverifieerde installateurs met `viewAny`-toegang tot opnames kunnen de pagina openen.

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

## Doelmetrics (BL-042, nog niet geïmplementeerd)

Het nieuwe hoofddoel gaat over totale opnamearbeid, op afstand offeren en montagezekerheid. De huidige funnel blijft nuttig, maar kan niet aantonen hoeveel installateurstijd of locatiebezoeken zijn bespaard.

| Metric | Besloten definitie / benodigde instrumentatie |
|--------|-----------------------------------------------|
| Op afstand offerbaar | Opnames met eindbesluit **Offerte op afstand voorbereiden** en zonder locatiebezoek, gedeeld door alle opnames met een technisch eindbesluit. |
| Alleen prijsindicatie | Opnames met **Prijsindicatie; technische controle volgt**, apart van definitief op afstand offerbaar. Nooit bij de eerste categorie optellen. |
| Zonder locatiebezoek | Technisch besloten opnames zonder geregistreerd locatiebezoek gedeeld door alle technisch besloten opnames. Dit is meetbaar; noem het niet automatisch “vermeden” zonder nulmeting of expliciete installateursvraag. |
| Reden locatiebezoek | Eén of meer gecontroleerde redencodes per besluit, bv. onzekere stroomroute, onzichtbare condensroute, bereikbaarheid, constructie of klantvoorkeur; vrije toelichting telt niet als analyticsdimensie. |
| Actieve installateurstijd | Som van expliciete actieve werksessies aan opname/review, met idle-timeout; **niet** de wandkloktijd tussen aanmaak en besluit. Mediaan per workflow en uitkomst. |
| Klantinspanning | Bestaande klantacties plus actieve klanttijd, uitgesplitst naar initiële taakset en gerichte aanvullingen. |
| Eerste ronde op afstand gereed | Dossiers die vóór een aanvullende klanttaak al op afstand offerbaar zijn, gedeeld door dossiers met een eerste technische beoordeling. |
| Gerichte aanvullingsrondes | Aantal bijdrageopdrachten en rondes na eerste analyse, uitgesplitst naar beslisgebied en uitkomst. |
| Automatisch vastgesteld aandeel | Aantal gebruikte dossierconclusies uit aanvraag/register/AI zonder handmatige bevestigingsactie, gedeeld door alle gebruikte conclusies; alleen zinvol na BL-035-provenance. |
| AI-voorstelafwijking | Verschil tussen voorgestelde en uiteindelijk geselecteerde plaatsingen, installatieoptie en verbindingen; rapporteer acceptatie én type correctie, niet alleen een succespercentage. |
| Montageverrassing | Na plaatsing: onverwacht meerwerk, andere route/configuratie of onjuiste aanname die niet als dossieronzekerheid stond. Teller per uitgevoerde installatie en ernstklasse. |
| Totale doorlooptijd | Splits wachttijd, klanttijd, automatische verwerking en installateurstijd; één samengestelde “tijd tot besluit” mag alleen als overkoepelende kalenderduur worden getoond. |

### Meetregels

1. Leg workflow vast: `customer`, `installer` of `hybrid`.
2. Leg een locatiebezoek en plaatsingsuitkomst expliciet vast; leid “bezoek vermeden” niet af uit afwezigheid van een event zolang de opname nog open is.
3. Gebruik gecontroleerde codes voor beslisstatus, blokkade, bezoekreden, correctietype en montage-uitkomst.
4. Bewaar geen vrije klanttekst, foto-inhoud, token, adres of modelprompt in analytics-events.
5. Toon op `/metrics` welke cijfers op volledige instrumentatie zijn gebaseerd en welke nog `—` zijn; vul ontbrekende historie niet met aannames.
6. Vergelijk klant-, installateur- en hybride workflows afzonderlijk voordat productconclusies worden getrokken.

## Privacy

De huidige analytics bewaart geen extra dataset. `IntakeMetricsService` leidt cijfers bij het openen af uit bestaande timestamps, identifiers, relaties en expliciete eventtypen. `answer_saved` bevat alleen `question_key` en optioneel `section_instance_key`; nooit het antwoord. De meetweergave toont geen klantnaam, e-mail, telefoon, token, vrije klanttekst, bestandsnaam of foto-inhoud.

BL-042 mag gecontroleerde werksessie-, besluit-, bezoek- en montage-uitkomstevents toevoegen wanneer bestaande records onvoldoende zijn. Die events bevatten uitsluitend tenant/intake-referentie, type/code, duur/aantal en tijdstip; geen inhoudelijk dossierbewijs.

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
