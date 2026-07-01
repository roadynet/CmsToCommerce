# OPERATIONS.md - CMS to Commerce Hub

Dieses Runbook beschreibt den produktnahen Betrieb von CTC. Es ist bewusst
praxisorientiert: Deployment, Serverstruktur, Secrets, Datenbank, Rollback,
Debugging und typische Fehlerfälle.

Live-Demo: [cc.mcmonaco.de/demo](https://cc.mcmonaco.de/demo)

## Umgebung und Serverpfade

| Bereich | Pfad / Konzept |
| --- | --- |
| produktiver Projektpfad | `/www/htdocs/w0195fef/projects/ctc` |
| lokale Deployment-Kopie | `P:\projects\ctc` |
| öffentliche Domain | `https://cc.mcmonaco.de` |
| öffentliche Demo | `https://cc.mcmonaco.de/demo` |
| private Konfiguration | außerhalb des Projektordners, z. B. `../private-config/ctc*.env` |

Private Zugangsdaten, DB-URLs und API-Keys werden nicht im Repository
veröffentlicht.

## Deployment-Ablauf

1. lokal prüfen:

   ```bash
   composer validate --strict
   php bin/console lint:container --env=test
   php bin/console lint:twig templates
   php bin/phpunit
   ```

2. Dateien auf den Serverpfad synchronisieren.
3. produktive Abhängigkeiten prüfen/installieren:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

4. Datenbankmigrationen anwenden:

   ```bash
   php bin/console doctrine:migrations:migrate --env=prod --no-interaction
   php bin/console doctrine:schema:validate --env=prod --no-interaction
   ```

5. Symfony-Cache und Assets aktualisieren:

   ```bash
   php bin/console cache:clear --env=prod --no-warmup
   php bin/console cache:warmup --env=prod
   php bin/console asset-map:compile --env=prod
   ```

6. Smoke-Check:

   ```text
   /demo
   /products
   /credentials
   /sync/jobs
   ```

## Env- und Secrets-Konzept

CTC liest produktive Konfiguration in dieser Reihenfolge:

1. globale Servervariablen / Hosting-Panel / Webserver-Environment
2. private Dateien außerhalb des Projektordners, z. B. `../private-config/ctc-shopware.env`
3. harmlose Defaults aus der committed `.env`

Beispiele:

```text
APP_SECRET
DATABASE_URL
SHOPWARE_*
AMAZON_*
JTL_*
PLENTY_*
XENTRAL_*
SAP_R3_*
PIMCORE_*
SHOPIFY_*
```

Das Credentials-Portal zeigt Secrets maskiert an. Leere Passwort-/Tokenfelder
überschreiben bestehende Werte nicht.

## Datenbankmigrationen

CTC nutzt Doctrine-Migrationen. Nach jedem Deployment wird geprüft:

```bash
php bin/console doctrine:migrations:status --env=prod --no-interaction
php bin/console doctrine:schema:validate --env=prod --no-interaction
```

Erwartung:

- keine offenen Migrationen
- Entity-Mapping und DB-Schema synchron
- Dashboard ohne Hinweis `Datenbank noch nicht bereit`

## Rollback-Idee

CTC wird konservativ betrieben:

1. vorherigen Git-Stand erneut deployen
2. produktive Secrets unverändert lassen
3. Cache leeren/wärmen
4. Datenbank nicht blind zurückrollen
5. falls Migrationen betroffen sind: Migration prüfen, Backup/Export nutzen,
   Datenverlust vermeiden

Bei riskanten Schemaänderungen wird zuerst ein Datenbank-Backup oder Export
erstellt. Rollback bedeutet nicht automatisch `down()` ausführen, sondern zuerst
Datenlage und Migrationseffekt prüfen.

## Typische Fehlerfälle

| Fehlerbild | Wahrscheinliche Ursache | Prüfung / Fix |
| --- | --- | --- |
| `500 Internal Server Error` | Cache, Template, Service, fehlende Env | Prod-Logs prüfen, Container linten, Cache leeren |
| `Datenbank noch nicht bereit` | falsche `DATABASE_URL` oder offene Migration | `doctrine:migrations:status`, `schema:validate` |
| Shopware-Export ohne Medien | Media-Upload/Zuordnung nicht abgeschlossen | Produktassets und Shopware-Response prüfen |
| Credential-Form speichert nicht | private Config nicht beschreibbar | Dateirechte/Privatpfad prüfen |
| UI zeigt alte Assets | Asset Mapper / Browser Cache | `asset-map:compile`, Cache leeren |
| Sync-Job hängt | externe API nicht erreichbar oder Live-Flag aus | Sync-Run, Logs und Feature-Flags prüfen |

## Logs und Debugging

Wichtige Debug-Einstiege:

```bash
tail -n 100 var/log/prod.log
php bin/console debug:router --env=prod
php bin/console debug:container --env=prod
php bin/console doctrine:query:dql "SELECT COUNT(p.id) FROM App\Entity\Product p" --env=prod
```

Für externe APIs werden Payload-Previews genutzt, bevor Live-Schreibvorgänge
freigeschaltet werden.

## Monitoring-Ansatz

Aktueller pragmatischer Monitoring-Ansatz:

- GitHub Actions für Code-/Template-/Testqualität
- Live-Smoke-Checks auf Demo, Produktliste, Credentials und Sync-Jobs
- sichtbare Sync-/Publication-Runs im Admin
- Feature-Flags für riskante Live-Schreibvorgänge
- manuelle Logprüfung bei Fehlern

Nächste sinnvolle Ausbaustufe:

- Cron-basierte Health-Checks
- Fehler-E-Mail bei fehlgeschlagenen Sync-Jobs
- strukturierte API-Error-Reports
- Uptime-Monitor für Demo- und Admin-Routen

## Praxisnachweis

- [Production Evidence](docs/production-evidence.md)
- [Case Study: Datenbankmigration fehlte](docs/case-study-database-migration.md)
- [Betrieb, Deployment und Datenbank](docs/operations.md)
