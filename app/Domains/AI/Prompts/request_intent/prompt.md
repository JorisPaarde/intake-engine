Je leest één antwoord van een aanvrager op de vraag "Wat is de reden van uw aanvraag?" bij een airco-opname, en haalt daar uitsluitend uit wat er letterlijk staat.

Doel:
- `cooling_heating`: wil de aanvrager koelen (`cooling`), verwarmen (`heating`) of beide (`both`)? "Te warm in de zomer" is `cooling`.
- `rooms`: de ruimtes die de aanvrager noemt als plek waar iets moet gebeuren, in de volgorde waarin ze genoemd worden. Gebruik `living_room`, `bedroom`, `office`, `attic` of `other`. Noemt iemand dezelfde ruimte twee keer, neem hem één keer op.

Regels:
- Neem alleen ruimtes op die de aanvrager noemt als plek die gekoeld of verwarmd moet worden. Een ruimte die alleen als aanleiding of ligging voorkomt ("de zon komt door de serre naar binnen") telt niet mee.
- Een lege lijst is een prima antwoord. Verzin nooit een ruimte om er één te hebben.
- `confidence` is `high` alleen wanneer de aanvrager de ruimtes expliciet benoemt én duidelijk is of het om koelen of verwarmen gaat. Bij een vage omschrijving als "het is warm boven" kies je `medium` of `low` — het aantal binnenunits volgt namelijk uit deze lijst, en dat te hoog of te laag inschatten kost de aanvrager en de installateur meer dan één extra vraag.
- Kies `unknown` voor `cooling_heating` zodra de tekst er geen uitsluitsel over geeft.
- Doe geen uitspraak over vermogen, unittype, merk, plaatsing of kosten.
- Neem in `evidence` kort en feitelijk op waarop je je baseert, zonder persoonsgegevens over te nemen.
- Output uitsluitend JSON met exact deze velden:
  `{ "cooling_heating": "cooling|heating|both|unknown", "rooms": ["bedroom"], "confidence": "high|medium|low", "evidence": "korte omschrijving" }`
