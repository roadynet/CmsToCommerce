<?php

declare(strict_types=1);

namespace App\Service\Platform;

use App\Integration\Amazon\AmazonSpApiConnector;
use App\Integration\Shopware\ShopwareAdminApiConnector;
use App\Repository\ChannelListingRepository;
use App\Repository\ProductRepository;
use App\Repository\PublicationRunRepository;
use App\Service\Integration\ExternalSystemIntakeRegistry;
use Throwable;

final class PlatformOverviewBuilder
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly ChannelListingRepository $channelListingRepository,
        private readonly PublicationRunRepository $publicationRunRepository,
        private readonly AmazonSpApiConnector $amazonConnector,
        private readonly ShopwareAdminApiConnector $shopwareConnector,
        private readonly ExternalSystemIntakeRegistry $externalSystemIntakeRegistry,
        private readonly string $platformName,
    ) {
    }

    /**
     * @return array{
     *     title: string,
     *     stats: array<string, mixed>,
     *     capabilities: list<array{title: string, body: string}>,
     *     principles: list<array{title: string, body: string}>,
     *     integrations: list<array{title: string, body: string, ready: bool}>,
     *     source_systems: list<array{code: string, label: string, status: string, summary: string, next_step: string, example_keys: list<string>, intake_ready: bool}>,
     *     api_examples: list<array{title: string, body: string}>,
     *     quick_starts: list<array{badge: string, title: string, body: string, sample: string, action: string, download_url: ?string, download_label: ?string}>,
     *     workflow_steps: list<array{step: string, title: string, body: string}>,
     *     channels: array<string, int>,
     *     recentProducts: list<mixed>,
     *     recentRuns: list<mixed>
     * }
     */
    public function build(): array
    {
        try {
            $stats = [
                'products' => $this->productRepository->count([]),
                'listings' => $this->channelListingRepository->count([]),
                'publication_runs' => $this->publicationRunRepository->count([]),
                'product_statuses' => $this->productRepository->countByStatus(),
            ];
            $channels = $this->channelListingRepository->countByChannel();
            $recentProducts = $this->productRepository->findLatest();
            $recentRuns = $this->publicationRunRepository->findLatest();
            $databaseReady = true;
            $databaseMessage = null;
        } catch (Throwable $exception) {
            $stats = [
                'products' => 0,
                'listings' => 0,
                'publication_runs' => 0,
                'product_statuses' => [],
            ];
            $channels = [];
            $recentProducts = [];
            $recentRuns = [];
            $databaseReady = false;
            $databaseMessage = $exception->getMessage();
        }

        return [
            'title' => $this->platformName,
            'stats' => $stats,
            'database_ready' => $databaseReady,
            'database_message' => $databaseMessage,
            'quick_starts' => [
                [
                    'badge' => 'Batch',
                    'title' => 'Sectionscode-Testpaket',
                    'body' => 'Starte direkt mit einem kompletten Beispielpaket aus TXT-Dateien und Bildern. Ideal, um Upload, Vorschau und Import in einem Schritt zu prüfen.',
                    'sample' => "1.1.txt\n1.1.png\n1.1.1.png\n1.2.txt\n1.2.png",
                    'action' => 'sections',
                    'download_url' => '/demo-imports/sectionscode-testpaket.zip',
                    'download_label' => 'Dummy-ZIP laden',
                ],
                [
                    'badge' => 'Einzeln',
                    'title' => 'Manueller Einzelimport',
                    'body' => 'Lege ein Produkt mit Rohtext, Beschreibung, Varianten und Bildern manuell an. Perfekt für Sonderfälle oder zum ersten Funktionstest.',
                    'sample' => "Marke: North Trail\nProduktart: Trinkflasche\nMaterial: Edelstahl\nFarbe: Schwarz",
                    'action' => 'manual',
                    'download_url' => '/demo-imports/3-produkte-sammeldatei.txt',
                    'download_label' => 'TXT-Beispiel laden',
                ],
                [
                    'badge' => 'Pflege',
                    'title' => 'Produktstamm bearbeiten',
                    'body' => 'Öffne alle angelegten Produkte, ergänze Bilder, passe Texte an und stoße Amazon- oder Shopware-Syncs gezielt neu an.',
                    'sample' => "Produkte öffnen\nbearbeiten\nlöschen\nneu publizieren",
                    'action' => 'products',
                    'download_url' => null,
                    'download_label' => null,
                ],
                [
                    'badge' => 'API',
                    'title' => 'Intake per Schnittstelle',
                    'body' => 'Übertrage Produktdaten direkt aus einem CMS oder PIM. Die API erzeugt daraus automatisch Produktstamm und Kanal-Entwürfe.',
                    'sample' => "{\n  \"produkt_name\": \"Edelstahl Trinkflasche 750 ml\",\n  \"marke\": \"North Trail\",\n  \"kategorie_pfad\": \"Outdoor/Trinkflaschen\"\n}",
                    'action' => 'api',
                    'download_url' => null,
                    'download_label' => null,
                ],
            ],
            'workflow_steps' => [
                [
                    'step' => '01',
                    'title' => 'Daten und Bilder aufnehmen',
                    'body' => 'TXT-Dateien, Bildpakete oder API-Payloads einsammeln und einem Produkt sauber zuordnen.',
                ],
                [
                    'step' => '02',
                    'title' => 'Listing prüfen und schärfen',
                    'body' => 'Titel, Bulletpoints, Beschreibung, Attribute und Bildreihenfolge kontrollieren und bei Bedarf überarbeiten.',
                ],
                [
                    'step' => '03',
                    'title' => 'Kanäle synchronisieren',
                    'body' => 'Amazon- und Shopware-Entwürfe vorbereiten, veröffentlichen und die Läufe nachvollziehbar protokollieren.',
                ],
            ],
            'capabilities' => [
                [
                    'title' => 'Importe sauber bündeln',
                    'body' => 'CMS-Rohdaten, Bilder, Varianten und Referenzen werden in einem strukturierten Produktstamm zusammengeführt.',
                ],
                [
                    'title' => 'Marktplatzfähige Texte erzeugen',
                    'body' => 'Die Plattform erzeugt suchbare Titel, verständliche Bulletpoints, Produktbeschreibungen und technische Merkmale für Amazon und Shopware.',
                ],
                [
                    'title' => 'Medien und Sichtbarkeit steuern',
                    'body' => 'Produktbilder werden lokal gespeichert, für Shopware synchronisiert und pro Produkt nachvollziehbar gehalten.',
                ],
            ],
            'principles' => [
                [
                    'title' => 'Klare Trennung von Oberfläche und Fachlogik',
                    'body' => 'Controller bleiben schlank, während Import, Listing-Aufbereitung und Publishing in eigenen Services gebündelt werden.',
                ],
                [
                    'title' => 'Zugangsdaten außerhalb der Oberfläche',
                    'body' => 'Shopware-, Amazon- und API-Geheimnisse bleiben in Server-Konfiguration und privaten Umgebungsdateien statt im Frontend.',
                ],
                [
                    'title' => 'Wiederholbare Synchronisation',
                    'body' => 'Bestehende Produkte werden aktualisiert statt dupliziert, und jeder Publishing-Lauf bleibt später nachvollziehbar.',
                ],
            ],
            'integrations' => [
                [
                    'title' => 'Amazon SP-API',
                    'body' => $this->amazonConnector->isConfigured()
                        ? 'Zugang und Marketplace-Prüfung stehen. CTC löst Product Types live gegen Amazon auf, validiert Listings sicher per VALIDATION_PREVIEW und hält Live-Publishing standardmäßig deaktiviert.'
                        : 'Noch nicht vollständig konfiguriert. Die Plattform erzeugt bereits exportfähige Amazon-Payloads und kann nach Hinterlegung der Zugangsdaten Product Types und Pflichtattribute live prüfen.',
                    'ready' => $this->amazonConnector->isConfigured(),
                ],
                [
                    'title' => 'Shopware Admin API',
                    'body' => $this->shopwareConnector->isConfigured()
                        ? 'Shopware-Zugang und Live-Sichtbarkeit sind aktiv. Produkte inklusive Medienzuordnung werden beim Veröffentlichen direkt als sichtbare Shopware-Produkte synchronisiert.'
                        : 'Noch nicht vollständig konfiguriert. Die Plattform erzeugt bereits importfähige Shopware-Payloads.',
                    'ready' => $this->shopwareConnector->isConfigured(),
                ],
            ],
            'source_systems' => $this->externalSystemIntakeRegistry->supportedSystems(),
            'api_examples' => [
                [
                    'title' => 'CMS-Intake-API',
                    'body' => 'POST /api/intake mit X-CTC-Token oder Bearer-Token. Erwartet JSON mit Produktdaten, optional varianten[] und optional asset_urls[] für externe Bilder. Bestehende Produkte werden per externer Referenz oder SKU als Upsert erkannt.',
                ],
                [
                    'title' => 'Quellsystem-Hinweis',
                    'body' => 'Optional kann X-CTC-Source-System mit jtl, plentymarkets oder xentral gesetzt werden. Dann normalisiert CTC das Eingangspayload automatisch auf den internen Produktstamm.',
                ],
                [
                    'title' => 'Delta-Sync für Preis & Bestand',
                    'body' => 'POST /api/intake/delta oder Header X-CTC-Sync-Mode: delta aktualisiert gezielt Preis und Bestand pro SKU, ohne den kompletten Produktstamm neu aufzubauen.',
                ],
                [
                    'title' => 'Export-Vorschau',
                    'body' => 'GET /products/{id}/export/amazon oder /shopware liefert die exportierbare Struktur als JSON.',
                ],
            ],
            'channels' => $channels,
            'recentProducts' => $recentProducts,
            'recentRuns' => $recentRuns,
        ];
    }
}
