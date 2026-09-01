# UI-taal — gecontroleerd eenvoudig Nederlands

> **Documentversie:** 1.2 · **Laatste update:** 2026-09-01 · Onderhoud: zie [AGENTS.md](../AGENTS.md)

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
9. **Aanspreekvorm: je.** Nieuwe UI-copy (installateur, demo, flash-/foutmeldingen) gebruikt **je/jij/jouw**, niet **u/uw**. Gepubliceerde klanttemplatevragen blijven immutabel (ADR-0001); wijzig die alleen via een nieuwe templateversie.

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
| kandidaatpositie | mogelijke plek |
| AI-constateringen | wat de AI ziet |
| aannemelijk (status) | lijkt te kloppen |
| niet op afstand vast te stellen | alleen te zien op locatie |
| voedingspunt | stroomaansluiting |
| binnenunits (klantvraag) | ruimtes |
| zonbelasting | hoeveel zon krijgt de ruimte |
| adaptief | past zich aan |
| Voorbeeldklant (als vooringevulde demo-naam) | door installateur getypte naam (tip alleen als placeholder) |
| crawl space / kruipruimte-instructies | zeg of er een kruipruimte is; ga er niet in |
| electrical phase question | 1- of 3-fase (uit meterkastfoto; geen aparte vraag) |

Productnaam **Digitale Opname** mag als merknaam blijven. In lopende UI-tekst mag “opname” volstaan. Vermijd gemengde branding (“Intake Engine”) in gebruikers-UI.

## Scope

- Wel: klantwizard, follow-up, installateursschermen, demo-coach, auth, e-mails, enum-labels, templatevragen, validatie-/flashmeldingen.
- Niet: code-identifiers, ADR’s, interne comments, dev-admin, logs.

## Templateversies

Gepubliceerde vraagteksten zijn immutabel (ADR-0001). Taalwijzigingen aan klantvragen horen in een nieuwe airco-templateversie.
