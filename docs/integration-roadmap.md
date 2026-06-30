# Integrations-Roadmap für CTC

## Amazon: von "Verbindung steht" zu "erstes echtes Listing"

CTC trennt den Amazon-Weg jetzt in vier saubere Schritte:

1. CTC-Produktart und Kategorie heuristisch vorbereiten
2. `searchDefinitionsProductTypes` gegen die Amazon Product Type Definitions API ausführen
3. den besten Treffer mit `getDefinitionsProductType` validieren
4. Pflichtattribute auf das Amazon-Schema mappen und erst dann `putListingsItem` bauen
5. zuerst im Modus `VALIDATION_PREVIEW` prüfen
6. erst mit aktiviertem `AMAZON_ENABLE_LIVE_PUBLISH=1` den echten Live-Submit ausführen

### Aktuelle CTC-Heuristiken

- `Trinkflasche` -> Keywords wie `water bottle`, `insulated bottle`, `drink bottle`
- `Schneidebrett` -> Keywords wie `cutting board`, `chopping board`, `serving board`
- `Schreibtischlampe` / `Tischleuchte` -> Keywords wie `desk lamp`, `table lamp`, `task light`

Die lokalen Regeln liefern bewusst nur Suchbegriffe und keine hart verdrahteten Amazon-Product-Types. Die endgültige Auswahl kommt aus der Amazon-API selbst.

### Aktueller Payload-Stand in CTC

CTC kann jetzt abhängig von den vorhandenen Daten zwei sinnvolle Amazon-Wege fahren:

- `LISTING_PRODUCT_ONLY`, wenn nur Produktdaten vorliegen
- `LISTING`, wenn zusätzlich Preis und Bestand aus aktiven Varianten vorliegen

Zusätzlich mappt CTC jetzt:

- Titel, Bulletpoints, Beschreibung, Marke, Farbe, Material, Größe
- Preis zu `purchasable_offer`
- Bestand zu `fulfillment_availability`
- Produktbilder zu `main_product_image_locator` und `other_product_image_locator_*`

Live bleibt standardmäßig aus. So kann das Amazon-Schema vollständig geprüft werden, ohne versehentlich echte Katalogänderungen anzustoßen.

## Empfohlene Reihenfolge für Warenwirtschaft / ERP in CTC

### 1. JTL

Warum zuerst:

- sehr stark im deutschsprachigen Handel
- gute Passung für Artikelstamm, Varianten, Bilder, Kategorien, Lager und Preise
- hoher Nutzen für produktzentrierte Workflows in CTC

Empfohlene Adapter-Reihenfolge:

1. Lesender Artikel- und Varianteneingang
2. Medien, Kategorien, Merkmale
3. Delta-Sync für Bestand und Preis
4. optionales Write-back für optimierte Listing-Texte

Aktueller CTC-Stand:

- Preview-Write-back für JTL ist vorhanden
- Live-Write-back für optimierte Titel-, Kurzbeschreibung-, Beschreibung- und SEO-Texte ist vorbereitet
- Zielartikel wird zuerst über die gespeicherte JTL-Referenz aufgelöst, sonst über SKU/EAN via JTL-Items-API gesucht
- Sicherheitsmodus: Live-Senden bleibt aus, bis `JTL_ENABLE_LIVE_WRITEBACK=1` gesetzt ist

### 2. plentymarkets

Warum als Nächstes:

- stark kanal- und marktplatzorientiert
- sehr passend für CTC, wenn aus einem zentralen Produktstamm mehrere Kanäle bedient werden
- gute Anschlussstelle für spätere Kanalstatus- und Publish-Feedback-Loops

Empfohlene Adapter-Reihenfolge:

1. Artikeldaten und Varianten lesen
2. Marktplatz- und Kanalbezüge übernehmen
3. Preis-/Bestandsdeltas
4. optionales Zurückschreiben von kanalbezogenen Inhalten

Aktueller CTC-Stand:

- Preview-Write-back für plentymarkets ist vorhanden
- Live-Write-back für Variationstexte ist vorbereitet
- Zielvariation wird über `itemId:variationId` aus der Importquelle oder per SKU/EAN über die Varianten-Suche aufgelöst
- Sicherheitsmodus: Live-Senden bleibt aus, bis `PLENTY_ENABLE_LIVE_WRITEBACK=1` gesetzt ist

### 3. Xentral

Warum danach:

- API-freundlich und modern für Cloud-orientierte Abläufe
- sinnvoll, wenn CTC später stärker in ERP-, Prozess- und Team-Workflows eingebettet wird
- etwas eher als Prozess-Backbone als als reines Listing-System relevant

Empfohlene Adapter-Reihenfolge:

1. Artikelstamm und Medien lesen
2. Bestände, Preise, Einkauf/Logistik-Kontext
3. Status- und Freigabeinformationen koppeln
4. optionales Write-back für interne Produktdatenfelder

### 4. SAP R/3

Warum als Enterprise-Adapter:

- relevant bei etablierten Materialstamm-Prozessen in mittelgroßen und großen Unternehmen
- starke Quelle für Materialnummern, Warengruppen, Preise, Bestände, Werke und Vertriebsdaten
- technisch meist nicht direkt per REST erreichbar, sondern über SAP Gateway, PI/PO, CPI, IDoc oder RFC/BAPI-Proxy

Empfohlene Adapter-Reihenfolge:

1. Materialstamm aus MARA/MAKT/MVKE/MARD-nahen Payloads lesen
2. IDoc-/Gateway-JSON in das CTC-Produktmodell normalisieren
3. Delta-Sync für Preis und Bestand aus Werk/Lagerort ergänzen
4. Write-back-Preview als MATMAS05-/BAPI_MATERIAL_SAVEDATA-nahes Payload erzeugen
5. Live-Write-back erst über freigegebenen SAP-Gateway-/RFC-Proxy aktivieren

Aktueller CTC-Stand:

- SAP-R/3-Intake ist für Materialnummer, Kurztext, Warengruppe, Varianten, EAN, Preis, Bestand und Medienquellen vorbereitet
- Preview-Write-back erzeugt ein IDoc-/BAPI-nahes Payload für optimierte CTC-Listingtexte
- Live-Write-back ist als Gateway-Proxy vorbereitet
- Sicherheitsmodus: Live-Senden bleibt aus, bis `SAP_R3_ENABLE_LIVE_WRITEBACK=1` gesetzt ist

### 5. Pimcore

Warum als PIM/DAM-Adapter:

- starke Quelle für strukturierte Produktdaten, Klassifizierungen, Varianten und digitale Assets
- passt sehr gut vor CTC, wenn Produktdaten redaktionell gepflegt und danach für Amazon/Shopware optimiert werden
- eignet sich als Rückkanal für optimierte Listing-Texte, Qualitätsstatus und kanalbezogene Freigaben

Empfohlene Adapter-Reihenfolge:

1. Data Objects und localized fields lesen
2. Assets/Galerien als Bildquellen übernehmen
3. Classification Store / Attribute / Varianten normalisieren
4. Delta-Sync für Preis, Bestand und Status ergänzen
5. Write-back-Preview für optimierte CTC-Texte erzeugen
6. Live-Write-back erst über freigegebenen Pimcore API-/Data-Hub-/Gateway-Endpunkt aktivieren

Aktueller CTC-Stand:

- Pimcore-Intake ist für Objekt-ID, Klasse, Key, localized fields, Attribute, Varianten und Assets vorbereitet
- Preview-Write-back erzeugt objektnahe Felder wie `ctcOptimizedTitle`, `ctcDescription`, `ctcBulletpoints`, `ctcKeywords` und Qualitätswerte
- Live-Write-back ist über einen Pimcore-Gateway-Endpunkt vorbereitet
- Sicherheitsmodus: Live-Senden bleibt aus, bis `PIMCORE_ENABLE_LIVE_WRITEBACK=1` gesetzt ist

### 6. Shopify

Warum als Commerce-Adapter:

- Shopify ist ein häufiges Zielsystem für D2C- und Commerce-Teams
- Produktdaten, Varianten, Preise, Bestände, Bilder, Tags und SEO-Felder lassen sich sehr gut in den CTC-Prozess einbinden
- CTC kann Shopify als Datenquelle nutzen und optimierte Listing-Texte per Admin API zurückspielen

Empfohlene Adapter-Reihenfolge:

1. Produkt-, Varianten- und Bilddaten aus Shopify Admin API Payloads lesen
2. SKU, Barcode/EAN, Preis, Bestand, Vendor, Product Type und Tags normalisieren
3. Delta-Sync für Preis und Bestand über Varianten unterstützen
4. Write-back-Preview für `productUpdate` per Admin GraphQL API erzeugen
5. Live-Write-back erst mit Admin Access Token und `SHOPIFY_ENABLE_LIVE_WRITEBACK=1` aktivieren

Aktueller CTC-Stand:

- Shopify-Intake ist für Produktdaten, Varianten, Bilder, Tags, Vendor und Product Type vorbereitet
- Preview-Write-back erzeugt ein Admin-GraphQL-Payload für `productUpdate`, SEO-Felder und CTC-Metafelder
- Live-Write-back ist über Shopify Admin GraphQL vorbereitet
- Sicherheitsmodus: Live-Senden bleibt aus, bis `SHOPIFY_ENABLE_LIVE_WRITEBACK=1` gesetzt ist

## Technische Integrationslogik in CTC

Unabhängig vom Zielsystem sollte jeder neue Connector in derselben Reihenfolge wachsen:

1. Intake lesen
2. Daten normalisieren
3. Medien zuordnen
4. Variantenmodell auflösen
5. Delta-Sync ergänzen
6. optionales Write-back aktivieren

So bleibt CTC konsistent, auch wenn später weitere Systeme wie Akeneo, Tradebyte oder Microsoft Business Central dazukommen.
