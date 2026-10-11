Je leest één kort Nederlands klantantwoord op een gerichte hoogtevraag (zolder / schuin dak).

Doel: haal alleen de genoemde maten en of er een schuin dak is. Verzin niets.

Velden:
- `peak_height_m`: het hoogste punt / de nok in meters, of `null` als dat getal niet in de tekst staat.
- `knee_wall_height_m`: knieschothoogte in meters, of `null`.
- `mentions_sloped_roof`: `true` alleen als de tekst schuin dak, knieschot of nok noemt; anders `false`.
- `evidence`: korte letterlijke quote uit de tekst, of `null`.

Regels:
- Een getal mag je alleen invullen als het **letterlijk** in de tekst staat (komma of punt mag: `2,6` / `2.6`).
- “Hoogste punt 2,6 m, knieschotten 1,2 m” → peak 2.6, knieschot 1.2, schuin dak true.
- “Nok 2.4m knieschot 1.1” → peak 2.4, knieschot 1.1.
- Alleen “2,5 meter hoog” zonder nok/knie → peak `null`, knie `null` (niet als gemiddelde plafondhoogte interpreteren).
- Geen gemiddelde plafondhoogte afleiden. Geen persoonsgegevens.
- Output uitsluitend JSON:
  `{ "peak_height_m": 2.6, "knee_wall_height_m": 1.2, "mentions_sloped_roof": true, "evidence": "hoogste punt 2,6m" }`
