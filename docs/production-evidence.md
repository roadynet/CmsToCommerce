# Praxisnachweis: Betrieb, Deployments und Integrationsarbeit

Diese Datei ist als belegbare Praxis-Evidence für technische Interviews gedacht.
Sie fasst zusammen, welche produktnahen Aufgaben an CTC tatsächlich umgesetzt
wurden, ohne Zugangsdaten, private Serverkonfiguration oder Kundendaten zu
veröffentlichen.

Live-Demo: [cc.mcmonaco.de/demo](https://cc.mcmonaco.de/demo)

## Live- und Evidence-URLs

| Evidence | Link |
| --- | --- |
| CTC Live-Demo | [cc.mcmonaco.de/demo](https://cc.mcmonaco.de/demo) |
| CTC GitHub Actions | [CI](https://github.com/roadynet/CmsToCommerce/actions/workflows/ci.yml) |
| Operations Runbook | [OPERATIONS.md](../OPERATIONS.md) |
| Datenbank-Case-Study | [Case Study: Migration fehlte](case-study-database-migration.md) |
| Recruiter-Überblick | [recruiter-overview.md](recruiter-overview.md) |

## Hosting und Betriebsarbeit

CTC läuft als Symfony-Anwendung in einer Shared-Hosting-/Serverumgebung mit SSH,
Composer, produktivem Cache, privaten Environment-Dateien und Doctrine-
Migrationen. Exakte Server-User, DB-URLs und Tokens werden aus Sicherheitsgründen
nicht veröffentlicht.

Selbst umgesetzt:

- Symfony-Projekt auf eigenen Serverpfad deployed
- Domain auf den Projektpfad geschaltet
- Composer-Abhängigkeiten und Prod-Cache geprüft
- Doctrine-Migrationen angewendet und Schema synchronisiert
- private Secrets aus dem Repository herausgezogen
- Credential-Portal mit maskierter Anzeige gebaut
- Shopware-/Amazon-/ERP-/PIM-Adapter mit Live-Sperren vorbereitet
- Fehlerfälle dokumentiert und in Operations-Routinen übersetzt

## Screenshot- und Nachweis-Map

| Nachweis | Öffentlich im Repo | Hinweis |
| --- | --- | --- |
| Live-Demo | [00-public-demo.png](screenshots/00-public-demo.png) | öffentlicher Einstieg ohne Login |
| Demo-Flow | [00-demo-flow.gif](screenshots/00-demo-flow.gif) | TXT + Bilder -> Produkt -> Preview |
| Credential-Portal maskiert | [04-zugangsdaten.png](screenshots/04-zugangsdaten.png) | keine echten Secrets sichtbar |
| Sync-Jobs | [06-sync-jobs.png](screenshots/06-sync-jobs.png) | Delta-Sync/Write-back sichtbar |
| Produktdetail | [05-produktdetail.png](screenshots/05-produktdetail.png) | Rohdaten, Medien, Listings |
| GitHub Actions grün | [CI Badge/Actions](https://github.com/roadynet/CmsToCommerce/actions/workflows/ci.yml) | CI-Lauf öffentlich prüfbar |
| DB-Migration erfolgreich | [Case Study](case-study-database-migration.md) | als sanitized Command-Flow dokumentiert |
| Prod-Cache/Deployment-Check | [OPERATIONS.md](../OPERATIONS.md) | keine Server-Screens mit Secrets veröffentlicht |

Server- und Terminal-Screenshots werden nur bereinigt oder im Gespräch live
gezeigt, weil Pfade, Usernamen, DB-Namen oder Tokens sonst versehentlich
sichtbar werden können.

## Was damit belegbar ist

| Praxisbereich | Nachweis im Projekt | Relevanz |
| --- | --- | --- |
| Symfony-Betrieb | Live-Demo, produktiver Deploy-Pfad, Cache-/Asset-/Migrationsroutine | Web Platform Engineering, Application Management |
| Datenbankbetrieb | Doctrine-Migrationen, Schema-Validation, dokumentierter Fix für fehlende Tabelle `external_sync_job` | sichere Releases und Fehleranalyse |
| Logs und Fehlerdiagnose | dokumentierte 500er-/DB-Fehlerfälle mit Ursache und Fix | Produktionssupport |
| Secrets-Handling | globale Servervariablen, private Config-Dateien, maskiertes Credentials-Portal | sicherer Umgang mit Zugangsdaten |
| API-Integrationen | Shopware, Amazon SP-API vorbereitet, JTL, plentymarkets, Xentral, SAP R/3, Pimcore, Shopify | Integrationsarchitektur |
| Qualitätssicherung | PHPUnit, Container-/Twig-Linting, GitHub Actions | nachvollziehbare Qualität |
| UX im Admin-Kontext | reduzierte 3-Button-Hierarchie, Demo-Flow, Formular-Hubs | fachanwenderfreundliche Plattformarbeit |

## Konkrete Betriebsfälle

### 1. Fehlerbild: 500 auf Produktformular

**Symptom:** Beim Öffnen des neuen Produktformulars erschien ein Symfony-500er.

**Vorgehen:**

- Route und Controller geprüft
- Formular-/Template-Erwartungen mit Entity- und Service-Struktur abgeglichen
- fehlende oder veraltete Datenstruktur korrigiert
- Symfony-Cache geleert und Produktformular erneut geprüft

**Ergebnis:** Das Formular wurde wieder nutzbar und später zum getrennten
TXT-/Bild-Upload mit Sectionscode-Import erweitert.

**Praxis-Signal:** klassischer Application-Manager-Fall: Fehler nicht nur
wegklicken, sondern Route, Template, Datenmodell und Cache gemeinsam betrachten.

### 2. Fehlerbild: "Datenbank noch nicht bereit"

**Symptom:** Das Dashboard zeigte einen Datenbankhinweis, obwohl der Code
bereits deployt war.

**Ursache:** Eine Doctrine-Migration war produktiv noch offen. Dadurch fehlte
die Tabelle `external_sync_job`, während die neue Sync-Job-Funktion sie bereits
verwendete.

**Fix:**

```bash
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
php bin/console doctrine:schema:validate --env=prod --no-interaction
```

**Ergebnis:** Code, Entity-Mapping und Datenbankschema waren wieder synchron.
Die Nachbereitung liegt in [operations.md](operations.md).

**Praxis-Signal:** Release-Probleme zwischen Code-Stand und Datenbankstand
erkennen und reproduzierbar beheben.

### 3. Fehlerbild: interne Demo-/SkillBuilder-Texte im Shopware-Export

**Symptom:** Der exportierte Shopware-Text enthielt noch interne Demo-Hinweise
und machte die Herkunft sichtbar.

**Vorgehen:**

- Exporttexte von internen Workflow-Formulierungen getrennt
- kundentaugliche Beschreibung erzeugt
- Herkunfts-/Demo-Hinweise entfernt
- Shopware-Preview und Produktdetail geprüft

**Ergebnis:** Die Produkttexte sind für Käufer verständlich und enthalten keine
internen Prozesshinweise.

**Praxis-Signal:** technische Integration endet nicht beim API-Call; Daten müssen
fachlich und rechtlich sauber im Zielsystem erscheinen.

### 4. Deployment- und Cache-Disziplin

**Problemklasse:** Änderungen an Twig, Assets und Symfony-Services sind erst
sichtbar, wenn die produktive Umgebung wirklich aktualisiert ist.

**Standardroutine:**

```bash
php bin/console lint:container --env=prod
php bin/console lint:twig templates
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod
```

**Praxis-Signal:** Deployments werden nicht als Kopieren von Dateien verstanden,
sondern als kontrollierter Betriebszustand aus Code, Cache, Assets, Datenbank und
Konfiguration.

## Kurze Case Study: Produktionsproblem gelöst

**Problem:** Nach Deployment stand im Dashboard `Datenbank noch nicht bereit`.

**Analyse:** Doctrine-Migrationsstatus und Schema-Validation zeigten, dass Code
und Datenbank nicht auf demselben Stand waren. Die Tabelle `external_sync_job`
fehlte produktiv.

**Lösung:** Offene Migration angewendet, Schema validiert, Prod-Cache geleert und
gewärmt, danach Live-Routen erneut geprüft.

**Ergebnis:** Dashboard, Produktliste und Sync-Jobs waren wieder stabil. Die
komplette Case Study liegt hier: [case-study-database-migration.md](case-study-database-migration.md).

## Interview-Demo in 5 Minuten

1. Live-Demo öffnen: [cc.mcmonaco.de/demo](https://cc.mcmonaco.de/demo)
2. Sectionscode-Import erklären: `1.1.txt` plus `1.1.png`, `1.1.1.png`, ...
3. Produktdetail zeigen: Rohdaten, Medien, Varianten, Listing-Drafts
4. Shopware-/Amazon-Preview zeigen: sicherer Payload vor Live-Schreibvorgang
5. Zugangsdaten-Portal zeigen: maskierte Secrets, private Config, keine GitHub-Secrets
6. Sync-Jobs zeigen: geplanter Delta-Sync und Write-back-Gates
7. GitHub Actions und diese Evidence-Datei zeigen

## Relevanz für Pimcore-/Platform-Engineer-Rollen

| Anforderung aus typischen Platform-Rollen | CTC-Nachweis |
| --- | --- |
| PHP/Symfony-Anwendungsbetrieb | Symfony-App mit Services, Commands, Doctrine, Twig, Security |
| Composer und Dependencies | versionierte Dependencies, CI, validierbarer Container |
| Datenmodelle und Migrationen | Produkte, Assets, Listings, Sync-Jobs, Doctrine-Migrationen |
| PIM/DAM-Verständnis | Pimcore-Adapter für Localized Fields, Assets und Classification-Attribute |
| API-Integrationen | Shopware Admin API, Amazon SP-API-Payloads, ERP/PIM-/Wawi-Adapter |
| SSH/Hosting/Deployment | dokumentierter Serverpfad, private Config, Cache-/Migrationsroutine |
| Log-/Fehleranalyse | 500er-, Schema- und Sync-Fehler als dokumentierte Fälle |
| Betriebssicherheit | Live-Schreibvorgänge per Default gesperrt, Secrets außerhalb des Repos |

## Bewusst nicht veröffentlicht

- echte Zugangsdaten
- private Servervariablen
- produktive Datenbankinhalte
- Kundendaten oder Bestellungen
- Live-Amazon-Veröffentlichung
- vollständige private Hosting-Konfiguration

Diese Grenze ist Absicht: Das Repository soll Praxis belegen, ohne den Betrieb
oder externe Systeme unnötig offenzulegen.
