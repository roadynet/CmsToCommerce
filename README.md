# CMS to Commerce Hub

[![CI](https://github.com/roadynet/CmsToCommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/roadynet/CmsToCommerce/actions/workflows/ci.yml)

Demo deployed at: [cc.mcmonaco.de/demo](https://cc.mcmonaco.de/demo)

CMS to Commerce Hub ist eine Symfony-Plattform, die Produktdaten aus CMS-, PIM-, ERP- und Datei-Quellen übernimmt und daraus marktplatzfähige Produktseiten für Amazon und Shopware vorbereitet.

## Auf einen Blick

- **Was ist es?** Ein Commerce Integration Hub für Produktimport, Listing-Erzeugung, Medienzuordnung, Kanal-Preview und Sync-/Write-back-Flows.
- **Live-Demo:** [cc.mcmonaco.de/demo](https://cc.mcmonaco.de/demo)
- **Tech-Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, Twig, Asset Mapper, PHPUnit, GitHub Actions.
- **Warum interessant?** Das Projekt verbindet reale Commerce-Probleme: TXT- und Bildimporte, Amazon-SP-API-Vorbereitung, Shopware Admin API, ERP/PIM-Adapter, Secrets-Handling und testbare Integrationslogik.

![Demo-Flow](docs/screenshots/00-demo-flow.gif)

## Kleine Codebeispiele

Sectionscode-Import: gleiche Nummer, gleiches Produkt.

```text
1.1.txt    -> Produktdaten
1.1.png    -> Hauptbild
1.1.1.png  -> Detailbild
```

Nach dem Import werden Kanal-Entwürfe vorbereitet.

```php
$product = $intakeManager->createFromInput($formData, $uploadedFiles);

foreach (ChannelType::cases() as $channel) {
    $publicationOrchestrator->prepare($product, $channel);
}
```

Die fertigen Kanalstrukturen bleiben als kleine JSON-Preview abrufbar.

```text
GET /products/{id}/export/amazon
GET /products/{id}/export/shopware
```

## Senior-Level Review-Pfad

| Frage | Einstieg |
| --- | --- |
| Was macht das Produkt? | [Live-Demo](https://cc.mcmonaco.de/demo) |
| Welche Engineering-Entscheidungen stecken dahinter? | [Architektur](docs/architecture.md) |
| Wie sieht das Projekt für Recruiter aus? | [Recruiter-Überblick](docs/recruiter-overview.md) |
| Welche Praxis ist belegbar? | [Production Evidence](docs/production-evidence.md) |
| Wie werden Betrieb, Datenbank und Secrets behandelt? | [OPERATIONS.md](OPERATIONS.md) |
| Wie argumentiere ich das im Gespräch? | [Interview-Pitch](docs/interview-pitch.md) |
| Wie werden externe Systeme erweitert? | [Integrations-Roadmap](docs/integration-roadmap.md) |

Senior-Signale im Projekt:

- fachliche Logik liegt in Services statt in Controllern
- externe Systeme werden über Adapter, Normalizer und Preview-/Publish-Gates getrennt
- Live-Schreibvorgänge sind per Default deaktiviert und brauchen explizite Serverfreigaben
- CI prüft Composer, Container, Twig und PHPUnit
- produktive Secrets bleiben außerhalb des Repositories
- die [Production Evidence](docs/production-evidence.md) dokumentiert echte Betriebsfälle: 500er-Analyse, Migrationen, Cache/Assets, Secrets und Integrationsgrenzen

## Recruiter / Projektüberblick

Eine kompakte, GitHub-taugliche Projektvorstellung mit Screenshots, Tech-Stack, Architektur-Highlights und Integrationen liegt hier:

- [Recruiter-Überblick](docs/recruiter-overview.md)
- [Operations Runbook](OPERATIONS.md)
- [Interview-Pitch](docs/interview-pitch.md)

## Enthaltene Funktionen

- Admin-Login
- Produkt-Intake per Webformular
- Listenimport mit TXT-Dateien und separaten Produktbildern
- Intake-API (`POST /api/intake`) mit Token-Schutz
- Produktstamm mit Quellen, Assets, Varianten und Channel-Entwürfen
- Amazon-A-Listing-Drafts mit Qualitätsprüfung
- Shopware Admin API inklusive Produkt- und Medienzuordnung
- vorbereitete Amazon-SP-API-Anbindung ohne Live-Testzwang
- JTL-, plentymarkets-, Xentral-, SAP-R/3-, Pimcore- und Shopify-Vorbereitung mit Sync-/Write-back-Flows
- zeitgesteuerte externe Sync-Jobs
- Zugangsdaten-Portal pro Channel/System mit maskierten Secrets
- reduzierte Portal-UX mit maximal drei sichtbaren Hauptaktionen pro Bereich
- Export-Vorschau als JSON pro Channel

## Lokal starten

```bash
composer install && php bin/console doctrine:migrations:migrate && symfony server:start
```

Für lokale Entwicklung nutzt die committed `.env` nur Dummy-/Defaultwerte.

## Secrets und produktive Servervariablen

Produktive Zugangsdaten gehören nicht ins Repository. CTC lädt sensible Werte in dieser Reihenfolge:

1. globale Server-/Umgebungsvariablen, zum Beispiel Hosting-Panel, Apache `SetEnv` oder PHP-FPM-Environment
2. private Dateien außerhalb des Projektordners unter `../private-config/ctc*.env`
3. harmlose Defaults aus der committed `.env`

Im Portal koennen Admins die channel-spezifischen Zugangsdaten unter `/credentials` pflegen.
Die Formulare schreiben in `../private-config/ctc-shopware.env`, `ctc-amazon.env`,
`ctc-shopify.env` usw.; geheime Werte werden maskiert angezeigt und beim Leerlassen
nicht ueberschrieben.

Wichtige Variablen:

- `APP_SECRET`
- `APP_ADMIN_PASSWORD_HASH`
- `APP_IMPORT_API_TOKEN`
- `DATABASE_URL`
- `SHOPWARE_*`
- `AMAZON_*`
- `JTL_*`
- `PLENTY_*`
- `XENTRAL_*`
- `SAP_R3_*`
- `PIMCORE_*`
- `SHOPIFY_*`

Beispiele liegen in:

- [docs/private-config.example.env](docs/private-config.example.env)
- [docs/server-env.example.apache.conf](docs/server-env.example.apache.conf)

## Betrieb und Datenbank

Nach Deployments muss die produktive Datenbank auf dem aktuellen Migrationsstand sein:

```bash
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
php bin/console doctrine:schema:validate --env=prod
```

Wenn im Dashboard "Datenbank noch nicht bereit" erscheint, ist die Datenbankverbindung
oder das Schema nicht synchron. Details und Checkliste:

- [Betrieb, Deployment und Datenbank](docs/operations.md)
