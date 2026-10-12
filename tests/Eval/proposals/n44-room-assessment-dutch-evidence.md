# Prompt / schema — N44 (Engelse room_assessment evidence) — APPLIED

> **Applied** as live `room-assessment-v9` (en `outdoor-assessment-v7` / `fusebox-assessment-v6` voor sibling NL-vrije-tekst). `proposed-v9/` blijft een loadable alias van dezelfde inhoud.

Bron: IE-testronde 12 okt 07:50 — rapport Engelse evidence; Notion N44.

## Meten

```bash
AI_API_KEY=… php artisan eval:interpretation --migrate --repeats=5 --kind=photo_observation
# obs-n44-room-evidence-english: english_free_text → correct (concept_absent) bij NL model-output
# controls gelijk of beter
```
