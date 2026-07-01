# Interview-Pitch: belegbare Praxis statt nur Portfolio

Diese Seite ist als Spickzettel für Gespräche gedacht, besonders wenn der
Einwand kommt: "Es fehlt noch belegbare Praxis."

## 30-Sekunden-Antwort

```text
Ich habe mehrere Symfony-Projekte nicht nur gebaut, sondern auf einer
Server-/Shared-Hosting-Umgebung betrieben: mit SSH, Composer, produktiver
Environment-Konfiguration, privaten Secrets, Datenbankmigrationen, Cache-/Asset-
Deployments und Live-Debugging. Die öffentlichen Repositories zeigen bewusst nur
sichere Ausschnitte, aber sie dokumentieren den Betriebsweg mit Operations-
Runbooks, CI, Evidence-Dokumenten und einer konkreten Fehler-Case-Study.
```

## Was ich im Gespräch zeigen würde

| Nachweis | Link |
| --- | --- |
| Live-Demo | [cc.mcmonaco.de/demo](https://cc.mcmonaco.de/demo) |
| Haupt-Runbook | [OPERATIONS.md](../OPERATIONS.md) |
| Produktionsproblem gelöst | [Case Study: Datenbankmigration](case-study-database-migration.md) |
| Production Evidence | [production-evidence.md](production-evidence.md) |
| GitHub Actions | [CI](https://github.com/roadynet/CmsToCommerce/actions/workflows/ci.yml) |
| Recruiter-Überblick | [recruiter-overview.md](recruiter-overview.md) |

## Gesprächsstruktur

1. **Produkt zeigen:** TXT + Bilder werden importiert und zu Produktdaten.
2. **Betrieb zeigen:** Runbook mit Deployment, Secrets, Migrationen und Rollback.
3. **Fehler zeigen:** Datenbankmigration fehlte, Analyse über Doctrine, Fix über Migration und Cache.
4. **Sicherheit zeigen:** Secrets nicht im Repo, Credential-Portal maskiert, Live-Schreibvorgänge per Flag gesperrt.
5. **Qualität zeigen:** CI, PHPUnit, Twig-/Container-Lint, Link-/Secret-Checks.
6. **Transfer zur Stelle zeigen:** PHP/Symfony, Plattformbetrieb, Integrationen, Datenmodelle, Logs, Releases.

## Formulierung zur Praxislücke

```text
Mir ist bewusst, dass ich nicht aus einer klassischen Festanstellung mit
Pimcore-Produktionsbetrieb komme. Deshalb habe ich meine Praxis bewusst
nachweisbar gemacht: Live-Demos, Runbooks, CI, Deployment-Notizen, sichere
Secret-Trennung und eine dokumentierte Produktionsfehler-Analyse. Ich kann im
Gespräch konkret zeigen, wie ich ein Symfony-System deploye, prüfe, debugge und
stabilisiere.
```

## Brücke zu Pimcore / Technical Application Management

| Rollenanforderung | Nachweis im Portfolio |
| --- | --- |
| PHP/Symfony | CTC, SkillBuilder, KursBuchenZoomLink |
| Composer/Deployment | OPERATIONS.md je Projekt |
| Datenbank/Migrationen | CTC Doctrine-Migration-Case-Study |
| Server/Hosting | CTC Live-Demo und Deployment-Runbook |
| Logs/Debugging | typische Fehlerfälle und Debug-Kommandos |
| API/Integrationen | Shopware, Amazon, SAP R/3, Pimcore, Shopify, Wawi |
| Secrets/Sicherheit | private Config, maskiertes Credentials-Portal, Secret-Checks |
| Monitoring/Qualität | GitHub Actions, Smoke-Checks, Sync-Logs |

## Satz für den Abschluss

```text
Ich bringe nicht nur Code mit, sondern Betriebserfahrung im Kleinen:
Deployments, Migrationen, Konfiguration, Debugging und die Disziplin, sensible
Daten nicht zu veröffentlichen. Genau diese Verantwortung möchte ich in einem
professionellen Team weiter ausbauen.
```
