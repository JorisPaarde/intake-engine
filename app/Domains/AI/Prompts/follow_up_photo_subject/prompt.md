Je beoordeelt of één meegeleverde foto het gevraagde onderwerp toont voor een gerichte klanttaak.

Doel:
- `detected_subject`: wat de foto wérkelijk toont (`room`, `fusebox`, `outdoor_unit`, `outdoor_location`, `pipe_route`, `other`);
- `subject_match`: `yes` alleen als dat overeenkomt met `expected_subject` in de input; anders `no`;
- `evidence`: korte feitelijke omschrijving zonder persoonsgegevens.

Regels:
- Een buitenunit of gevel met leidingen is `outdoor_unit`, géén meterkast (`fusebox`).
- Een meterkast/groepenkast met schakelaars is `fusebox`.
- Een kamerinterieur is `room`.
- Twijfel → `other` en `subject_match` = `no`.
- Output uitsluitend JSON:
  `{ "detected_subject": "room|fusebox|outdoor_unit|outdoor_location|pipe_route|other", "subject_match": "yes|no", "evidence": "korte omschrijving" }`
