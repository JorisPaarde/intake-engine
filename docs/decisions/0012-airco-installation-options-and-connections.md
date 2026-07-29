# ADR-0012: Airco-installatieopties met drie technische verbindingen

- **Status:** Accepted
- **Datum:** 2026-07-30
- **Vervangt:** ADR-0009

## Context

ADR-0009 introduceerde een nuttige backend voor een begeleide leidingroute, maar ging uit van één route die de klant vanaf een gemarkeerde binnenunitpositie foto voor foto vastlegt. Dat is te vroeg en te smal:

- gewenste ruimtes bepalen nog niet hoeveel binnen- of buitenunits nodig zijn;
- een route heeft pas betekenis bij kandidaatposities en een kandidaatconfiguratie;
- airco-uitvoerbaarheid en meerwerk hangen niet alleen af van koelleidingen, maar ook van condensafvoer en stroomtoevoer;
- bij meerdere slaapkamers kunnen routes per binnenunit verschillen, terwijl buitenunits en stroomvoorziening gedeeld kunnen worden.

## Beslissing

- De opname modelleert eerst **gewenste ruimtes**, los van het uiteindelijke aantal units.
- Per ruimte en buitengebied kunnen meerdere **plaatsingsopties** bestaan voor binnenunit, buitenunit, voedingsbron of afvoerpunt.
- Een **installatieoptie** koppelt plaatsingsopties tot één technisch voorstel, bijvoorbeeld één multi-split of meerdere single-splits.
- Iedere installatieoptie bevat afzonderlijke technische verbindingen:
  - `refrigerant`: koelleidingen tussen een binnenunit en de gekoppelde buitenunit;
  - `condensate`: condensafvoer vanaf iedere binnenunit naar een geschikt punt;
  - `power`: elektrische voeding vanaf een geschikte bron naar het voor het concrete systeem vereiste aansluitpunt.
- Iedere verbinding heeft expliciete eindpunten, segmenten, bewijs, zekerheid, ontbrekende controles en kostenimpact. Een segment kan door foto, antwoord, meting, externe bron, installateurswaarneming of AI-analyse worden onderbouwd.
- Stroomtoevoer omvat zowel groep/capaciteit als kabelroute en aansluitpunt. De klant opent geen afdekkingen en voert geen elektrische controle uit; de installateur blijft verantwoordelijk voor de uiteindelijke geschiktheid.
- Het begeleide foto-voor-foto-routeonderzoek start pas wanneer kandidaatposities bestaan en extra routebewijs een offertebeslissing kan veranderen. Het is een gerichte vervolgmethode, geen verplichte standaardsectie.
- De bestaande `pipe_route_sessions` en `pipe_route_segments` blijven als analysebouwsteen bruikbaar, maar worden in het doelmodel gekoppeld aan één concrete verbinding binnen één installatieoptie. De nog niet gebouwde generieke BL-029-UI wordt niet volgens ADR-0009 voltooid.
- AI mag plaatsingen, configuraties, routes en alternatieven voorstellen en rangschikken. De installateur kiest of corrigeert de installatieoptie als geheel.
- Wanneer een beslissende route veilig niet op afstand kan worden vastgesteld, is **locatiebezoek nodig** een geldige, onderbouwde uitkomst.

## Alternatieven

| Alternatief | Afgewezen omdat |
|-------------|-----------------|
| Eén globale leidingroute per opname | Werkt niet voor meerdere binnenunits/configuraties en mengt verschillende eindpunten. |
| Ruimtes direct gelijkstellen aan binnenunits | Laat de klant impliciet een technische configuratie kiezen voordat bewijs is beoordeeld. |
| Alleen koelleiding modelleren | Condens en stroom kunnen afzonderlijk installatie onmogelijk maken of veel meerwerk veroorzaken. |
| Meterkastfoto als volledige stroombeoordeling | Bewijst geen geschikte kabelroute of juist aansluitpunt bij het gekozen systeem. |
| Foto-voor-fotolus in iedere intake | Belast eenvoudige gevallen en begint zonder voldoende technische context. |

## Gevolgen

- De airco-template herhaalt in het doelmodel per gewenste ruimte, niet per vooraf gekozen binnenunit.
- Er zijn nieuwe domeinobjecten nodig voor ruimtes, plaatsingsopties, installatieopties en verbindingen.
- BL-029 wordt herijkt: de backend blijft behouden, de oorspronkelijke UI-scope vervalt en wordt onderdeel van de nieuwe verbindingenflow.
- Dossier, offertebasis en metrics moeten onzekerheid en kostenimpact per verbinding tonen.
- Functionele tests dekken minimaal single-split, multi-split/twee slaapkamers, gedeelde en afzonderlijke stroomroutes, hybride opname en een terecht locatiebezoek.
