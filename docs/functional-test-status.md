# Functionele teststatus

Handmatig bijgehouden overzicht van wat functioneel is getest (en wat nog niet).

**Niet** invullen via geautomatiseerde agent-implementatie; bijwerken door de testende agent of tester.

Laatste update: 2026-07-17 (getest op staging via de browser)

| Onderdeel | Status | Getest op | Notities |
|-----------|--------|-----------|----------|
| Deploy-pipeline (push -> Actions -> rsync -> activate -> live) | pass | 2026-07-17 | Atomische symlink-swap werkt; release schoon geserveerd |
| /health (app boot + DB-verbinding) | pass | 2026-07-17 | JSON ok, environment=staging, database=ok |
| /login rendert | pass | 2026-07-17 | Toont loginformulier |
| Auth-beveiliging dashboard/intakes | pass | 2026-07-17 | Middleware auth+verified aanwezig; ingelogd toegang werkt. Uitgelogde redirect niet los getest (browser was ingelogd) - gedekt door CI-tests |
| Dashboard weergave | pass | 2026-07-17 | Lege staat en met opname |
| Opname aanmaken (Airco) | pass | 2026-07-17 | Opgeslagen, verschijnt op dashboard |
| Beveiligde klantlink genereren | pass | 2026-07-17 | Token + geldig tot-datum |
| Klantlink hergenereren | pass | 2026-07-17 | Nieuw token gegenereerd |
| Klantlink intrekken | pass | 2026-07-17 | Opname naar status Geannuleerd |
| Migraties + logs op server | pass | 2026-07-17 | Alle migraties Ran; geen errors in logs |
| Airco-template beschikbaar | pass | 2026-07-17 | Ontbrak eerst (deploy seedde niet); handmatig geseed. Fase 3 voegt template-seeding aan deploy toe |
| Homepage / (producthomepage Fase 3) | todo | - | Bij test nog Laravel-welcome; producthomepage nog niet visueel geverifieerd |
| Registratie /register | todo | - | Niet getest; e-mailverificatie vereist (mail=log op staging) |
| E-mailverificatie flow | todo | - | mail=log op staging; verificatielink alleen via log |
| Klant-intakepagina /o/{token} (Fase 3) | todo | - | Gaf 404 bij test want Fase 3 was nog niet gedeployd; opnieuw testen |
| Foto-uploads (Fase 4) | todo | - | Nog niet getest |
| Queue-worker (cron) | todo | - | Cron draait queue:work; nog geen job end-to-end getest |

## Legenda

| Status | Betekenis |
|--------|-----------|
| `todo` | Nog niet getest |
| `pass` | Functioneel OK |
| `fail` | Fout gevonden |
| `blocked` | Kan niet getest worden (afhankelijkheid/omgeving) |
| `n/a` | Niet van toepassing voor deze omgeving |

## Ruimte voor details

### Sessie 2026-07-17 (staging)

Scope: getest tegen de op dat moment gedeployde staging (Fase 2 interne basis). De end-to-end intakeflow voor de installateur is volledig geverifieerd: opname aanmaken -> beveiligde klantlink -> hergenereren -> intrekken, plus dashboard en /health.

Bevindingen:

- Airco-template werd niet automatisch geseed bij deploy; handmatig gedraaid met IntakeTemplateSeeder. Inmiddels opgelost in Fase 3 (template-seeding bij deploy).
- - Klantlink /o/{token} gaf 404 omdat de klant-facing route toen nog niet bestond; met Fase 3 hoort dit nu te werken en moet opnieuw getest worden.
  - - Nog te testen na Fase 3/4: producthomepage, klantintake via /o/{token}, foto-uploads, registratie + e-mailverificatie, en een end-to-end queue-job.
    - 
