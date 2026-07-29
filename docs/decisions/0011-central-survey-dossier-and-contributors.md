# ADR-0011: Centrale opname met meerdere bijdragers en uitzonderingsbeoordeling

- **Status:** Accepted
- **Datum:** 2026-07-30

## Context

De applicatie is gebouwd rond `template → sectie → vraag → antwoord` en een klantlink. Inmiddels leveren ook aanvraaggegevens, BAG/PDOK, EP-Online, 3DBAG, uploads, AI en installateursreviews technische informatie. De vragenlijst bepaalt daardoor impliciet nog steeds de vorm van het dossier, terwijl zij slechts één manier is om informatie te verzamelen.

De Digitale Opname start na een bestaande aanvraag. Sommige bedrijven willen de klant de opname laten uitvoeren, andere willen haar volledig zelf op locatie doen, en veel dossiers worden hybride. Een verplichte klantlink en één lineaire klantworkflow passen daar niet bij.

Daarnaast kost het apart laten bevestigen van ieder sterk afgeleid gegeven onnodig klant- en installateurswerk. Alleen tegenstrijdigheden en onzekerheden die een technische, veiligheids- of offertebeslissing kunnen veranderen verdienen expliciete aandacht.

## Beslissing

- De **opname** is het centrale technische dossier van een bestaande aanvraag. De vragenlijst-/takenengine is een invoerkanaal, niet het productmodel.
- Aanvraaggegevens, externe feiten, klantbijdragen, installateurswaarnemingen en AI-afleidingen worden in hetzelfde dossier gebruikt en houden bron, actor, tijdstip, zekerheid en bewijsreferenties.
- Een opname ondersteunt vanaf de start drie gelijkwaardige werkwijzen:
  - klant voert uit;
  - installateur voert volledig zelf uit, zonder klantlink;
  - hybride, met op ieder moment afgebakende bijdragen van klant en installateur.
- Het technische informatievereiste staat los van de bijdrager. Dezelfde dossierobjecten en besliscriteria gelden voor alle workflows.
- De applicatie neemt een gegeven automatisch over wanneer het met aan zekerheid grenzende waarschijnlijkheid is vastgesteld. Zij vraagt geen veld-voor-veld-bevestiging van brondata of sterke afleidingen.
- Die grens wordt per conclusietype door serverregels bepaald uit toegestane bron/evidence, volledigheid, consistentie en impact; een door een model zelf gerapporteerde `high`-confidence is op zichzelf onvoldoende.
- Alleen conflicten, lage zekerheid en onzekerheden met mogelijke invloed op oplossing, prijs, veiligheid of uitvoerbaarheid worden als uitzondering voorgelegd.
- Automatische conclusies blijven corrigeerbaar en herleidbaar. De installateur beoordeelt het complete installatievoorstel plus uitzonderingen; dat akkoord vervangt losse akkoordhandelingen per veld.
- Vragenlijstcompleetheid geldt alleen voor de toegewezen taakset. Technische beslisgereedheid wordt afzonderlijk per beslisgebied vastgelegd.
- Een onbekend gegeven is toegestaan. Het dossier legt vast welke beslissing daardoor wordt tegengehouden en of een gerichte aanvulling of locatiebezoek de juiste vervolgstap is.
- Bestaande tabellen en flows blijven tijdens de migratie functioneren. Nieuwe dossierobjecten worden eerst naast de huidige vraagkoppelingen geïntroduceerd; documentatie maakt steeds onderscheid tussen huidige implementatie en doelmodel.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Klantvragenlijst als blijvende kern | Kan installateursopname en hybride bijdragen alleen als uitzonderingen modelleren en koppelt technische structuur aan UI-stappen. |
| Aparte dossiers voor klant en installateur | Creëert dubbele waarheid, synchronisatieproblemen en onduidelijke herkomst. |
| Altijd een klantlink genereren | Is onnodig en verwarrend wanneer de installateur de opname zelf uitvoert. |
| Ieder automatisch gegeven laten bevestigen | Verplaatst administratief werk naar klant/installateur zonder besliswaarde. |
| Eén globale compleetheidsstatus behouden | Verbergt dat een taak klaar kan zijn terwijl stroom, condens of offertebasis nog onzeker is. |

## Gevolgen

- Er komt een expliciet dossier-/bewijs-/takenmodel naast de huidige template-engine.
- Klant- en installateurs-UX worden verschillende vensters op dezelfde opname.
- Installateursinvoer moet mobiel, camera-first, niet-lineair en technisch direct zijn.
- Klanttoegang wordt taakgebonden en optioneel; bestaande veilige tokenprincipes blijven gelden.
- Open-data- en AI-functionaliteit wordt hergebruikt als automatische dossierbron, niet als losse wizardoptimalisatie.
- Reviews en metrics verschuiven van algemene compleetheid naar beslisgereedheid, installateurstijd en op afstand offerbare dossiers.
