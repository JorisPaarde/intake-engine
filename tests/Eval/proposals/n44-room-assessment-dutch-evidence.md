# Prompt / schema voorstel — N44 (Engelse room_assessment evidence)

> **Niet toegepast.** Live `app/Domains/AI/Prompts/room_assessment/prompt.md` + `meta.php` blijven **room-assessment-v8**. Voorstel staat naast als `proposed-v9/` (niet geladen door `PromptVersionRepository`). Echte vision/eval op de box vóór live schakelen.

Bron: IE-testronde 12 okt 07:50 (prod v1.4.24, template v28) — rapport “Foto's van de ruimte · Grote slaapkamer”: Engelse evidence; rest NL. Notion N44.

## Oorzaak

| Bevinding | Component | Route |
|-----------|-----------|-------|
| Engelse `evidence` in rapport | **room_assessment** | `DerivePhotoAnswers::assessUpload` → fact value `evidence` → `ExternalFactPresenter` plakt evidence 1:1 in rapportblok |
| Prompt | room_assessment v8 | vraagt “korte omschrijving” zonder expliciet **Nederlands** voor vrije tekst (enums wél Engels) |
| Contrast | installer_photo_observation | wél: “korte Nederlandse constateringen” |
| Zelfde zwakte | outdoor / fusebox / indoor_position / pipe_route / drain / wall_outlet | evidence/retake zonder NL-regel |

Geen productcode die vertaalt of taal detecteert (ADR-0013/0016). Fix = prompt/schema-aanscherping na eval.

## Fixtures (deze branch)

| Id | Rol |
|----|-----|
| `obs-n44-room-evidence-english` | Bug: Engelse evidence (prod-tekst); `english_free_text` = forbidden → stub scoort **wrong** |
| `obs-n44-room-evidence-dutch-control` | Control slaapkamer NL |
| `obs-n44-livingroom-dutch-control` | Control woonkamer NL |
| `obs-n44-outdoor-evidence-english-risk` | Sibling outdoor_assessmentzelfde zwakte |

Foto’s: `tests/Eval/fixtures/photos/` (EXIF-vrij; synthetische slaapkamer + hercodeerde woonkamer). Claims via `ReportClaimsScorer` op evidence-corpus.

## Voorstel room-assessment-v9 (niet live)

Pad: `app/Domains/AI/Prompts/room_assessment/proposed-v9/`

- Expliciete regel: alle vrije tekst (`evidence`, `retake_instruction`) in het **Nederlands**; enum-tokens blijven Engels.
- Schemabeschrijving: `"korte Nederlandse omschrijving"` / `"concrete Nederlandse instructie of null"`.
- NL few-shot in de evidence-regel (geen Engelse voorbeeldzin).

Optioneel later (andere prompts, aparte eval): zelfde NL-regel in outdoor/fusebox/…; geen guard die Engels herschrijft.

## Mag niet (product)

- Regex/keyword-vertaling of language-detect in PHP.
- Live `meta.php` / `prompt.md` bump zonder echte eval-win.

## Meten (box, niet deze agentrun)

```bash
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=5 --kind=photo_observation
# Na copy proposed-v9 → live v9: obs-n44-room-evidence-english moet english_free_text → correct (concept_absent);
# controls gelijk of beter. Vision-pad nog stub; box kan image-refs gebruiken.
```
