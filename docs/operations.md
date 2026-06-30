# Betrieb, Deployment und Datenbank

Diese Datei beschreibt die wichtigsten Betriebschecks fuer CTC nach einem Deployment.
Sie ist bewusst praxisnah gehalten: Ziel ist, schnell zu erkennen, ob Portal, Cache,
Datenbank und private Konfiguration zusammenpassen.

## Serverpfad

Produktiver Projektpfad:

```text
/www/htdocs/w0195fef/projects/ctc
```

Lokale Deployment-Kopie:

```text
P:\projects\ctc
```

Private Konfiguration liegt ausserhalb des Projektordners:

```text
/www/htdocs/w0195fef/projects/private-config
P:\projects\private-config
```

## Standard-Checks nach Deployments

```bash
php bin/console lint:container --env=prod
php bin/console lint:twig templates
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod
```

## Datenbank-Migrationen

CTC nutzt Doctrine-Migrationen. Nach Code-Deployments muessen offene Migrationen
gegen die produktive Datenbank ausgefuehrt werden:

```bash
php bin/console doctrine:migrations:status --env=prod --no-interaction
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
php bin/console doctrine:schema:validate --env=prod --no-interaction
```

Erwartung:

- `New` ist `0`
- `Already at latest version`
- `The database schema is in sync with the mapping files`

## Hinweis "Datenbank noch nicht bereit"

Das Dashboard faengt Datenbankfehler bewusst ab. Wenn oben der Hinweis
`Datenbank noch nicht bereit` erscheint, ist meist eines davon der Grund:

1. `DATABASE_URL` fehlt oder zeigt auf die falsche Datenbank.
2. Migrationen wurden nach einem Deployment noch nicht ausgefuehrt.
3. Das Schema ist nicht synchron mit den Doctrine-Entities.
4. Die Datenbank ist kurzfristig nicht erreichbar.

Schnellpruefung:

```bash
php bin/console doctrine:migrations:status --env=prod --no-interaction
php bin/console doctrine:schema:validate --env=prod --no-interaction
php bin/console doctrine:query:dql "SELECT COUNT(p.id) FROM App\Entity\Product p" --env=prod --no-interaction
```

## Aktueller Fix vom 30.06.2026

Auf dem Server war die Migration `DoctrineMigrations\Version20260626143000`
noch offen. Dadurch fehlte die Tabelle `external_sync_job`, obwohl der Code
die Sync-Job-Funktion bereits erwartete.

Ausgefuehrt wurde:

```bash
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
```

Danach waren Migrationen und Schema wieder synchron.

## Zugangsdaten-Portal

Admin-Zugang:

```text
/credentials
```

Das Portal schreibt channel-spezifische Zugangsdaten in private Dateien:

- `ctc-shopware.env`
- `ctc-amazon.env`
- `ctc-shopify.env`
- `ctc-jtl.env`
- `ctc-plentymarkets.env`
- `ctc-xentral.env`
- `ctc-sap-r3.env`
- `ctc-pimcore.env`

Secrets werden im Formular maskiert. Leere Passwort- oder Tokenfelder behalten
den bestehenden Wert.

## UX-Regel

Die Portal-UX folgt einer einfachen Aktionshierarchie:

1. Ebene 1: maximal 3 sichtbare Hauptaktionen pro Bereich.
2. Ebene 2: bis ca. 6 Aktionen in Menues oder Gruppen.
3. Ebene 3: technische Detailaktionen nur in Kontextmenues oder Detailbereichen.

Dadurch bleiben Dashboard, Produktdetail und Zugangsdaten-Portal bewusst ruhiger.
