# Fixtures klanttest 2026-10-02

Alleen bestanden die tests echt gebruiken staan hier. Geen kunstmatige vergroting;
breedtes 720 en 1440 zijn echte bronresoluties (downscale van de Funda CDN-bron).

| Bestand | Bron | Resolutie |
|---------|------|-----------|
| `woonkamer-funda-1440.jpg` | Funda CDN (woonkamer) | 1440×960 |
| `woonkamer-funda-720.jpg` | Funda CDN (woonkamer) | 720×480 |

## Bron-URL's (niet gecommit)

Gebruik deze URL's om ontbrekende fixtures opnieuw te downloaden. Commit alleen wat een test echt nodig heeft.

| Onderwerp | URL | Notitie |
|-----------|-----|---------|
| Funda woonkamer (origineel 2160×1440) | `https://cloud.f-static.com/image/farm/img/1/huizenfotos/m204900556/living_room.jpg` (of CDN-pad uit Notion-dossier 2026-10-02) | Downscalen naar 1440/720 breed voor tests |
| Buitenunit VKB | Squarespace CDN WebP uit Notion-dossier (export naar JPEG) | Niet in tests |
| Gevel Green-Home | `https://…` Green-Home 1620×1080 uit Notion-dossier | Niet in tests |
| Meterkast groot (Tweakers) | `XFvR3lO5C5smPNHkrqRvpqMH.jpg` | Download faalde (403) |
| Meterkast klein (Klusidee) | `img_2505-jpg.18974` | Download faalde (404/auth) |
