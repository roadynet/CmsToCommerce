# Architektur: CMS to Commerce Hub

## Zielbild

CTC ist kein einzelner Import-Screen, sondern ein kleiner Commerce-Integration-Hub. Produktdaten kommen aus TXT-/Bild-Uploads, CMS-, PIM-, ERP- oder Warenwirtschaftssystemen und werden in einen stabilen Produktstamm überführt. Daraus entstehen kanalbezogene Entwürfe für Amazon, Shopware und weitere Systeme.

Der Kern ist bewusst so gebaut, dass neue Quellsysteme und Zielkanäle ergänzt werden können, ohne den Produktstamm oder die UI jedes Mal umzubauen.

## Systemgrenzen

```text
Quellen                  CTC-Kern                         Zielsysteme
TXT + Bilder      ->     Produktstamm              ->     Shopware
CMS/API           ->     Varianten + Medien        ->     Amazon SP-API
ERP/PIM/Wawi      ->     Listing-Drafts            ->     JTL / Plenty / Xentral
Delta-Sync        ->     Publication Runs          ->     SAP R/3 / Pimcore / Shopify
```

CTC hält die fachliche Wahrheit im eigenen Produktmodell. Externe Systeme werden über Adapter angebunden und liefern entweder Importdaten, Delta-Updates oder Write-back-Ziele.

## Wichtige Bausteine

| Bereich | Verantwortung |
| --- | --- |
| Controller | HTTP, Formulare, Redirects, Flash-Messages |
| Product Services | Import, Bearbeitung, Medienablage, Sectionscode-Zuordnung |
| Listing Services | Titel, Bulletpoints, Beschreibung, Attribute, Qualitätsscore |
| Export Services | kanalbezogene Payloads für Amazon, Shopware und JSON-Preview |
| Publishing | Preview, Live-Sync, Run-Protokollierung, Sicherheitsgates |
| Integration Services | Normalizer, Connectoren, Write-back-Previews, Delta-Sync |
| Configuration Services | Servervariablen, private Config-Dateien, Credential-Portal |

## Leitentscheidungen

### 1. Produktstamm vor Kanalpayload

Rohdaten werden zuerst normalisiert und als Produkt, Quelle, Asset und Variante gespeichert. Amazon- oder Shopware-Payloads entstehen danach aus diesem Modell.

Trade-off: etwas mehr Modellierungsaufwand, dafür weniger Duplikate und sauberere Erweiterbarkeit.

### 2. Preview vor Live-Schreiben

Amazon, Shopware und Warenwirtschaftssysteme bekommen erst eine Preview-/Validation-Stufe. Live-Schreiben ist über Flags wie `AMAZON_ENABLE_LIVE_PUBLISH` oder `PIMCORE_ENABLE_LIVE_WRITEBACK` gesperrt.

Trade-off: ein zusätzlicher Schritt in der Bedienung, dafür deutlich sicherer bei echten Kunden- und Produktdaten.

### 3. Adapter statt Speziallogik im Controller

JTL, plentymarkets, Xentral, SAP R/3, Pimcore und Shopify werden über Normalizer und Connectoren angebunden. Die UI muss nicht wissen, wie jedes Zielsystem intern arbeitet.

Trade-off: mehr Klassen, aber klare Verantwortlichkeiten und einfacheres Testen.

### 4. Secrets außerhalb von GitHub

Produktive Zugangsdaten liegen in Servervariablen oder privaten Dateien außerhalb des Projektordners. Das Credential-Portal schreibt maskierte Werte in private Config-Dateien.

Trade-off: etwas mehr Betriebsdisziplin, dafür kein Secret-Leak im Repository.

### 5. Sichtbare Läufe statt stiller Jobs

Publication- und Sync-Läufe werden protokolliert. Fachanwender sehen, ob ein Entwurf erzeugt, eine Preview geprüft oder ein Live-Sync versucht wurde.

Trade-off: mehr Datenmodell, dafür bessere Nachvollziehbarkeit bei Fehlern.

## Kleine Codebeispiele

Import und Kanalvorbereitung bleiben bewusst getrennt:

```php
$product = $intakeManager->createFromInput($formData, $uploadedFiles);

foreach (ChannelType::cases() as $channel) {
    $publicationOrchestrator->prepare($product, $channel);
}
```

Sectionscode-Dateien werden über Namenslogik gruppiert:

```text
1.1.txt    -> Produkt 1.1
1.1.png    -> Bild zu Produkt 1.1
1.1.1.png  -> weiteres Bild zu Produkt 1.1
1.2.txt    -> nächstes Produkt
```

Kanalpayloads bleiben prüfbar:

```text
GET /products/{id}/export/amazon
GET /products/{id}/export/shopware
```

## Fehler- und Risikobehandlung

| Risiko | Gegenmaßnahme |
| --- | --- |
| falsches Produkt wird live überschrieben | Live-Flags und Preview-Stufe |
| Geheimnisse landen im Repository | Servervariablen/private Config statt Git |
| externe API fällt aus | Connectoren liefern nachvollziehbare Sync-Ergebnisse |
| Medien und Varianten werden falsch zugeordnet | Sectionscode-Vorschau vor Import |
| Datenbank ist nicht migriert | Operations-Checkliste und Migrationsstatus |
| neue Systeme erzwingen UI-Umbau | Adapter-/Normalizer-Struktur |

## Test- und Qualitätsstrategie

- PHPUnit deckt Import, Normalisierung, Listing-Drafts, Connectoren und Credential-Verhalten ab.
- CI prüft Composer-Dateien, Symfony-Container, Twig-Templates und Tests.
- Externe Systeme werden ohne echte Kundenzugänge testbar gehalten.
- Live-Schreibvorgänge bleiben per Default deaktiviert.

## Was bewusst nicht gelöst ist

- kein echtes Bestell-/Payment-System
- kein vollwertiges PIM mit Freigabe-Workflow
- keine produktive Amazon-Live-Veröffentlichung ohne explizite Zugangsdaten und Freigabe
- keine Mandantenverwaltung für mehrere Kunden

Diese Grenzen sind Absicht: Das Projekt demonstriert Integrationsarchitektur, Produktdatenlogik und sichere Publishing-Vorbereitung, nicht den kompletten Betrieb eines Shopsystems.
