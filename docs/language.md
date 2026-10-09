# UI-taal — gecontroleerd eenvoudig Nederlands

> **Documentversie:** 1.15 · **Laatste update:** 2026-10-09 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

Status: bron van waarheid voor gebruikersgerichte teksten in de app (UI, mails, templatevragen, flash-/foutmeldingen). Productdocumentatie mag technischer blijven.

## Doel

Schrijf zodat klant en installateur snel begrijpen wat ze moeten doen. Volg de principes van ASD-STE100 en NEN-ISO 24495-1: korte zinnen, één betekenis per zin, gewone woorden, vaste termen.

## Schrijfregels

1. **Korte zinnen.** Streef naar één hoofdboodschap per zin. Splits lange zinnen.
2. **Eén betekenis.** Geen dubbele ontkenningen, geen vage woorden als “eventueel”, “betreffende”, “desgewenst”.
3. **Gewone woorden.** Kies het eenvoudigste Nederlandse woord dat klopt. Vermijd jargon als er een gewoon woord bestaat.
4. **Actieve taal.** “De app vult straat en plaats aan” in plaats van “Straat en plaats worden aangevuld”.
5. **Concrete opdrachten.** Zeg wat de gebruiker moet doen of wat er gebeurt. Geen abstracte producttaal.
6. **Vaste termen.** Gebruik overal dezelfde woorden voor hetzelfde ding (zie woordenlijst).
7. **Geen overbodige woorden.** Schrap “digitale”, “technisch”, “gericht” als die niets toevoegen voor de lezer.
8. **Domeinwoorden mogen blijven** als installateurs ze dagelijks gebruiken: airco, offerte, opname, binnenunit, buitenunit, koelleiding, condensafvoer, multi-split, single-split, meterkast, vrije groep.
9. **Aanspreekvorm: je.** Alle gebruikersgerichte copy (installateur, klant, demo, flash-/foutmeldingen, klantmails, auth) gebruikt **je/jij/jouw**, niet **u/uw**. Gepubliceerde templateversies blijven immutabel (ADR-0001); taalwijzigingen (zoals airco v20/v21/v22) gaan via een nieuwe templateversie.
10. **Vrije groep ≠ lege plek.** Noem een vrije/aparte stroomgroep niet als synoniem van fysieke uitbreidingsruimte in de meterkast. Laat de klant vooral een duidelijke foto maken; stuur niet met een vakdefinitie richting een verkeerd ja/nee.
11. **Geen interne keys/enums naar de klant.** Bedanktscherm en known-summary tonen Nederlandse labels (“Tuin / achtererf”), nooit `outdoor_location` / `wall` / `bedroom`. Aandachtspunten zijn voor de installateur.
12. **Foto-mismatch:** “Kies: foto vervangen of toch doorgaan” (niet de generieke verplichte-vraagtekst).

## Woordenlijst (voorkeur)

| Vermijd / zwaar | Gebruik |
|-----------------|---------|
| technische dossier / installateursdossier | opname |
| beslisgereedheid | klaar voor offerte |
| gerichte aanvulling / gerichte klanttaak | aanvulling / taak voor de klant |
| hybride opname | samen met de klant |
| adresverrijking | adresinvulling |
| woningbronnen | woninggegevens |
| openingszin | korte uitleg bij de aanvraag |
| kandidaatpositie / plekken / posities (installateurs-UI) | binnenunit / buitenunit (of stroomaansluiting / afvoerpunt) |
| opstelling / installatieoptie / optie (installateurs-UI voor de combinatiekeuze) | multi-split of singles; als zelfstandig naamwoord nodig: **keuze** (niet een derde productwoord) |
| haalbaarheidsbeoordeling / feasibility | **haalbaar** / **niet haalbaar** (met korte motivatie) |
| klantconfiguratiekeuze vóór technische check | eerst haalbare keuzes; daarna optioneel **klantvoorkeur** (incl. **Geen voorkeur**) |
| Technische waarneming (plaatsingsveld) | Notitie |
| Technische notitie toevoegen / Technische notities | Notitie toevoegen / Notities |
| Technische constatering | Notitie |
| Herkenbare naam / Korte omschrijving (unitnaam) | Naam |
| Een ruimte is nog geen gekozen binnenunit | (weg; optioneel “Kamers uit de aanvraag.”) |
| Maten L×B×H nog niet ingevuld | Maten nog leeg (of “4,2 × 3,1 m (13,0 m²)” / “16,5 m²”) |
| technische opname (CTA) | opname / Naar opname |
| bijgewerkte werkplek (demo) | bijgewerkte opname |
| Demo · echte werkplek / boost / Voorbeeldroute | Demo (marker); optioneel **Toon voorbeelddossier** |
| AI-constateringen | wat de AI ziet |
| aannemelijk (status) | lijkt te kloppen |
| niet op afstand vast te stellen | alleen te zien op locatie |
| voedingspunt | stroomaansluiting |
| binnenunits (klantvraag) | ruimtes |
| zonbelasting | hoeveel zon krijgt de ruimte |
| adaptief | past zich aan |
| Voorbeeldklant (als vooringevulde demo-naam) | door installateur getypte naam (tip alleen als placeholder) |
| korte klantroute / verkorte demowizard | wat de klant ziet (volledige wizard) |
| crawl space / kruipruimte-instructies | “Is er een kruipruimte?” (geen essay; v14) |
| electrical phase question | 1- of 3-fase (uit meterkastfoto; geen aparte vraag) |
| vrije-groepvraag vóór meterkastfoto | meterkastfoto eerst; ja/nee alleen als de foto free_group niet toont |
| Lengte/Breedte/Hoogte van de ruimte … als u die weet | Lengte (m) / Breedte (m) / Hoogte (m) (v14) |
| length_class `short` / `medium` / `long` | Kort / Middel / Lang (`InstallerDisplayLabels`) |
| bron `derived_lxw` | berekend uit L×B |
| zekerheid `high` / `medium` / `low` | hoge / middelmatige / lage |
| Later invullen (uitkomst, na opslaan zonder minuten) | Opgeslagen · minuten later invullen · tik om te wijzigen |
| Locatiebezoek (resultaat) = uitgevoerd | Nee: resultaat = nodig; checkbox = uitgevoerd |
| Zelf de opname uitvoeren | **Zelf de opname doen** (aanmaakscherm, demokeuze, workflowlabel) |
| Ronde N (klant-vervolgronde) | geen intern “Ronde”; bij meer dan één opdracht **Opdracht 1 van 2** |
| Unit verwijderen (werkplek) | **(type) verwijderen?** / “(naam) en de koppelingen ervan verdwijnen uit de opname.” / flash **(type) verwijderd.** |
| Bedrijfswebsite (bedankt) | knop **Naar de website van (bedrijf)** (alleen echte klant, alleen als ingevuld); daarna “Je kunt dit venster nu sluiten.” |

Productnaam **Digitale Opname** mag als merknaam blijven. In lopende UI-tekst mag “opname” volstaan. Vermijd gemengde branding (“Intake Engine”) in gebruikers-UI.

## Scope

- Wel: klantwizard, follow-up, installateursschermen, demo-coach, auth, e-mails, enum-labels, templatevragen, validatie-/flashmeldingen.
- Niet: code-identifiers, ADR’s, interne comments, dev-admin, logs.

## Templateversies

Gepubliceerde vraagteksten zijn immutabel (ADR-0001). Taalwijzigingen aan klantvragen horen in een nieuwe airco-templateversie.
