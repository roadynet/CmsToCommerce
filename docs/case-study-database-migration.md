# Case Study: Produktionsproblem durch fehlende Datenbankmigration gelöst

Diese kurze Case Study ist für Bewerbungsgespräche gedacht. Sie zeigt den Weg
von Fehlerbild über Analyse bis zur stabilen Live-Demo.

## Ausgangslage

Nach einem Deployment zeigte das CTC-Dashboard den Hinweis:

```text
Datenbank noch nicht bereit
```

Der Code enthielt bereits die neue Sync-Job-Funktion. Die produktive Datenbank
war aber noch nicht auf demselben Migrationsstand.

## Analyse

Geprüft wurden:

```bash
php bin/console doctrine:migrations:status --env=prod --no-interaction
php bin/console doctrine:schema:validate --env=prod --no-interaction
```

Die Ursache war eine noch nicht angewendete Doctrine-Migration. Dadurch fehlte
die Tabelle `external_sync_job`, während der neue Code diese Tabelle bereits
erwartete.

## Lösung

Die offene Migration wurde produktiv angewendet:

```bash
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
php bin/console doctrine:schema:validate --env=prod --no-interaction
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod
```

Danach wurden Dashboard, Produktliste und Sync-Job-Ansicht erneut geprüft.

## Ergebnis

- Datenbankschema und Doctrine-Entities waren wieder synchron.
- Der Hinweis `Datenbank noch nicht bereit` verschwand.
- Die Live-Demo war wieder stabil nutzbar.
- Der Vorfall wurde in [OPERATIONS.md](../OPERATIONS.md) und
  [Production Evidence](production-evidence.md) dokumentiert.

## Was ich daraus ableite

Ein Deployment ist nicht nur Code auf den Server kopieren. Für stabile
Symfony-Projekte müssen Code, Composer-Abhängigkeiten, Environment, Cache,
Assets und Datenbankmigrationen zusammenpassen.

Gute Interview-Formulierung:

```text
Ich habe mehrere Symfony-Projekte auf Shared Hosting/Serverumgebung deployed,
mit SSH, Composer, Environment-Konfiguration, privaten Secrets,
Datenbankmigrationen und Live-Debugging. Die öffentlichen Repositories
dokumentieren bewusst nur sichere Ausschnitte, aber ich kann den Betriebsweg im
Gespräch zeigen.
```
