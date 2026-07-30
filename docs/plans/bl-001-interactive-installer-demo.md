# BL-001 — Interactieve installateursdemo

> **Documentversie:** 1.3 · **Laatste update:** 2026-07-30 · Onderhoud: zie [AGENTS.md](../../AGENTS.md)

**Implementatiestatus:** codegereed in deze PR; BL-001 blijft `in_progress` tot de afzonderlijke staging- en mobiele visuele smoke is uitgevoerd.

## Besluit

De publieke demo gebruikt geen aparte nepflow en begint niet langer in de volledige klantvragenlijst. Een bezoeker krijgt een tijdelijk, geïsoleerd demo-installatiebedrijf en opent rechtstreeks de **echte installateurswerkplek** met een fictieve, deels voorbereide airco-opname.

De kennismaking bestaat uit twee lagen:

1. De homepage leidt bezoekers via probleem, werkwijze, voordelen, fictieve productweergaven, FAQ en de pilot-CTA naar passend bewijs of contact (BL-043/045).
2. **Probeer de demo** opent dezelfde routes, policies, modellen, formulieren en dossierlogica als een normale installateur.

## Verkoopverhaal

De voorbeeldopname laat in enkele minuten zien dat:

1. BAG-, luchtfoto-, EP-Online- en 3DBAG-context al bij het dossier staat;
2. twee gewenste ruimtes, kandidaatposities en één multi-splitvoorstel zijn voorbereid;
3. koel-, condens- en stroomverbindingen afzonderlijk zichtbaar en controleerbaar zijn;
4. AI alleen een brongebonden voorstel en één beslissende uitzondering aanlevert;
5. de installateur één gerichte klanttaak kan activeren en de echte klantweergave in een nieuw tabblad kan openen;
6. de installateur het voorstel daarna als geheel beoordeelt.

## Technische aanpak

### Tijdelijke en geïsoleerde opslag

- Iedere publieke start maakt een eigen fictief `Company`- en `User`-record.
- De bezoeker wordt alleen in dat tijdelijke account ingelogd.
- De opname gebruikt `workflow_mode=installer`, `is_demo=true` en de bestaande tenantpolicies.
- Een sessiemiddleware staat alleen dashboard, dit ene dossier, zijn werkplekacties, private foto’s en uitloggen toe; gewone opnameaanmaak, profiel, instellingen, metrics en dev-admin zijn niet beschikbaar.
- `DEMO_TTL_HOURS` is standaard twee uur; de bestaande hourly purge verwijdert dossier, media en daarna uitsluitend veilig herkenbare verweesde demo-user en demo-company.
- Een reguliere installateur en een andere demosessie kunnen het dossier niet openen.

### Eén echte productflow

- De redirect gaat naar `intakes.workspace`, niet naar de oude klantwizard.
- Alle normale werkplekmutaties blijven tijdelijk werken: ruimte, positie, optie, verbinding, vakwaarneming, foto en klanttaak.
- De voorbeeldopname wordt via dezelfde domeinservices opgebouwd als handmatige installateursinvoer.
- De klantweergave wordt pas actief wanneer de bezoeker de voorgestelde klanttaak controleert en activeert.

### Voorspelbaar en zonder externe effecten

- Alle personen, adressen, foto’s en technische waarden zijn expliciet fictieve demo-inhoud.
- Vooraf berekende AI-uitvoer wordt als een normaal, herleidbaar AI-voorstel in het dossier gezet.
- Demo-opnames doen nooit live dossier- of route-AI-calls, ook niet wanneer productie-AI aan staat.
- Demo-opnames versturen geen e-mail, maken geen PDF en veroorzaken geen installateursnotificatie.
- De UI zegt bij iedere gesimuleerde stap wat wel echt gebeurt en wat bewust niet extern wordt uitgevoerd.

### Beeldmateriaal

- De repository bevat uitsluitend synthetische voorbeeldfoto’s zonder personen, adressen, kentekens of metadata.
- Bij het starten gaan die foto’s door de normale dubbele beeldpipeline: dossier-JPEG plus kleinere AI-analysekopie.
- De werkplek toont het beeldbewijs via dezelfde private downloadroute als productie.

## UX

### Homepage

- Hoofd-CTA: **Probeer de demo**.
- Korte belofte: dezelfde installateursomgeving, vooraf gevuld, geen account nodig, automatisch verwijderd.
- Responsieve productweergaven tonen woningcontext, beeldbewijs, installatievoorstel, afzonderlijke verbindingen, uitzonderingsactie en de gerichte mobiele klanttaak met uitsluitend fictieve demo-inhoud.
- De productuitleg beschrijft zowel klant-, installateur- als hybride opname; niet alleen “stuur een link”.
- De zakelijke conversie-CTA is het zelfstandige, rate-limited interesseformulier van BL-043; een inzending start geen demo en maakt geen technische intake.

### Werkplek

- Bovenaan staat een compacte demorondleiding met ankers naar woningcontext, beeldbewijs, installatievoorstel en klanttaak.
- Een vaste demo-indicatie vermeldt vervaltijd, tijdelijke opslag en uitgeschakelde externe acties.
- De voorgestelde klanttaak gebruikt in demo de tekst **Activeer klantweergave** in plaats van “mailen”.
- Na activering verschijnt de normale knop **Klantweergave**; de klant vult uitsluitend die ene opdracht in.

## Acceptatiecriteria

- Een gast start de demo en komt ingelogd op de echte installateurswerkplek.
- Twee gelijktijdige demosessies hebben verschillende companies, users en intakes en krijgen onderling 403/404.
- De tijdelijke gebruiker kan geen tweede of normale intake aanmaken en kan geen profiel-, bedrijfs-, metrics- of dev-route gebruiken.
- De voorbeeldopname bevat woningcontext, synthetisch beeldbewijs, twee ruimtes, één geselecteerde installatieoptie, alle drie verbindingstypen, een AI-synthese en één voorgestelde klanttaak.
- Het activeren van de taak opent klanttoegang zonder mail te versturen.
- Geen demoactie kan een externe AI-call of PDF-job starten.
- Na verstrijken van de TTL verdwijnen intake, beide beeldvarianten, tijdelijke user en tijdelijke company; actieve demo’s blijven bestaan.
- Homepage, werkplek en klanttaak zijn responsive en toetsenbordtoegankelijk.
- Pint, PHPStan/Larastan, Pest en Vite zijn groen.

## Bewust buiten scope

- Een vrij te configureren demo-scenariobouwer voor beheerders.
- Analyse van door bezoekers zelf geüploade foto’s met een betaald extern model.
- Marketinganalytics of leadformulieren.
- Productievrijgave zonder afzonderlijke staging-smoke en visuele mobiele controle.
