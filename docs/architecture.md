# CMS to Commerce Hub

## Ziel

Eine Symfony-Plattform, die Produktdaten aus CMS-Quellen und Uploads übernimmt, Bilder sammelt, daraus kanalbezogene Amazon- und Shopware-Listings erzeugt und die spätere Veröffentlichung serverseitig orchestriert.

## Was aus SkillBuilder übernommen wurde

- Dünne Controller, fachliche Logik in Services
- Serverseitige Secrets außerhalb des Projektbaums
- Idempotente Brücken zu externen Systemen
- Nachvollziehbare Publication-Runs statt stiller Hintergrundmagie

## Erste Modulstruktur

```text
Dashboard
-> Product master data
-> Source intake
-> Asset intake
-> Variant modeling
-> Listing draft builder
-> Publication orchestrator
-> Amazon connector
-> Shopware connector
-> Publication run log
```

## Nächste sinnvolle Ausbaustufen

1. echtes Upload-Handling mit Dateispeicherung
2. CMS-Importer pro Quellsystem
3. KI-Service für strukturierte Listing-Drafts
4. Rollen und Freigaben
5. echte Amazon SP-API- und Shopware-Authentifizierung
6. Async-Jobs über Messenger Worker
