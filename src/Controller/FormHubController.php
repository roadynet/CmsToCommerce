<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/forms')]
final class FormHubController extends AbstractController
{
    #[Route('', name: 'app_form_hub_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_form_hub_show', ['slug' => 'sections-import']);
    }

    #[Route('/{slug}', name: 'app_form_hub_show', methods: ['GET', 'POST'])]
    public function show(string $slug, Request $request): Response
    {
        $forms = $this->forms();
        if (!isset($forms[$slug])) {
            throw $this->createNotFoundException('Unbekanntes Formular.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('dummy_form_'.$slug, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Ungueltiges Formular-Token.');
            }

            $this->addFlash('success', sprintf('Dummy-Formular "%s" wurde angenommen. Noch kein Live-Workflow ausgefuehrt.', $forms[$slug]['title']));

            return $this->redirectToRoute('app_form_hub_show', ['slug' => $slug]);
        }

        return $this->render('form_hub/show.html.twig', [
            'forms' => $forms,
            'active_slug' => $slug,
            'active_form' => $forms[$slug],
        ]);
    }

    /**
     * @return array<string, array{
     *     badge: string,
     *     title: string,
     *     body: string,
     *     real_link?: array{label: string, route: string, fragment?: string},
     *     fields: list<array{name: string, label: string, type: string, value?: string, rows?: int, options?: list<string>}>
     * }>
     */
    private function forms(): array
    {
        return [
            'sections-import' => [
                'badge' => 'Import',
                'title' => 'Sectionscode Upload',
                'body' => 'Dummy-Maske fuer TXT-Dateien und Bilder nach Sectionscode. Der echte Upload ist bereits verlinkt.',
                'real_link' => ['label' => 'Echten Batch-Import oeffnen', 'route' => 'app_product_new', 'fragment' => 'sections-import'],
                'fields' => [
                    ['name' => 'quelle', 'label' => 'Quelle / Importname', 'type' => 'text', 'value' => 'Sections-Upload Demo'],
                    ['name' => 'sprache', 'label' => 'Sprache', 'type' => 'text', 'value' => 'de'],
                    ['name' => 'txt_dateien', 'label' => 'TXT-Dateien', 'type' => 'file'],
                    ['name' => 'bilder', 'label' => 'Bilder', 'type' => 'file'],
                    ['name' => 'hinweis', 'label' => 'Notiz', 'type' => 'textarea', 'rows' => 4, 'value' => "1.1.txt + 1.1.jpg + 1.1.1.jpg gehoeren zu einem Produkt.\n1.2.txt startet das naechste Produkt."],
                ],
            ],
            'single-product' => [
                'badge' => 'Import',
                'title' => 'Manueller Einzelimport',
                'body' => 'Dummy-Maske fuer einzelne Produktanlage mit Rohtext, Varianten und Bildern.',
                'real_link' => ['label' => 'Echten Einzelimport oeffnen', 'route' => 'app_product_new', 'fragment' => 'manual-import'],
                'fields' => [
                    ['name' => 'produktname', 'label' => 'Produktname', 'type' => 'text', 'value' => 'Edelstahl Trinkflasche 750 ml'],
                    ['name' => 'marke', 'label' => 'Marke', 'type' => 'text', 'value' => 'North Trail'],
                    ['name' => 'kategorie', 'label' => 'Kategorie', 'type' => 'text', 'value' => 'Outdoor/Trinkflaschen'],
                    ['name' => 'rohtext', 'label' => 'Rohtext', 'type' => 'textarea', 'rows' => 7, 'value' => "Doppelwandige Trinkflasche aus Edelstahl.\nFarbe: Schwarz\nGroesse: 750 ml\nPreis: 29,90"],
                ],
            ],
            'amazon-listing' => [
                'badge' => 'Amazon',
                'title' => 'Amazon Listing Vorbereitung',
                'body' => 'Dummy-Maske fuer Product-Type, Pflichtattribute, Keywords und Preview-Freigabe.',
                'fields' => [
                    ['name' => 'marketplace', 'label' => 'Marketplace', 'type' => 'text', 'value' => 'Amazon.de'],
                    ['name' => 'product_type', 'label' => 'Amazon Product Type', 'type' => 'text', 'value' => 'WATER_BOTTLE'],
                    ['name' => 'modus', 'label' => 'Modus', 'type' => 'select', 'options' => ['VALIDATION_PREVIEW', 'Live gesperrt']],
                    ['name' => 'keywords', 'label' => 'Suchbegriffe', 'type' => 'textarea', 'rows' => 4, 'value' => 'Trinkflasche, Edelstahl, Outdoor, auslaufsicher'],
                ],
            ],
            'shopware-export' => [
                'badge' => 'Shopware',
                'title' => 'Shopware Export',
                'body' => 'Dummy-Maske fuer Kategorie, Sichtbarkeit, Medienzuordnung und Produktnummer.',
                'fields' => [
                    ['name' => 'sales_channel', 'label' => 'Sales Channel', 'type' => 'text', 'value' => 'Storefront'],
                    ['name' => 'category', 'label' => 'Ziel-Kategorie', 'type' => 'text', 'value' => 'Amazon Imports'],
                    ['name' => 'product_number', 'label' => 'Produktnummer', 'type' => 'text', 'value' => 'CTC-01DEMO'],
                    ['name' => 'media_mode', 'label' => 'Medienmodus', 'type' => 'select', 'options' => ['Cover + Galerie', 'Nur Cover', 'Keine Medien']],
                ],
            ],
            'jtl-writeback' => [
                'badge' => 'JTL',
                'title' => 'JTL Write-back',
                'body' => 'Dummy-Maske fuer Rueckschreiben optimierter CTC-Texte in JTL.',
                'fields' => [
                    ['name' => 'item_id', 'label' => 'JTL Artikel-ID', 'type' => 'text', 'value' => '3f0c46ca-fb82-4c91-8d33-demo'],
                    ['name' => 'sprache', 'label' => 'Sprache', 'type' => 'text', 'value' => 'DE'],
                    ['name' => 'felder', 'label' => 'Felder', 'type' => 'textarea', 'rows' => 5, 'value' => "Titel\nKurzbeschreibung\nBeschreibung\nMeta Description\nMeta Keywords"],
                ],
            ],
            'plenty-writeback' => [
                'badge' => 'plenty',
                'title' => 'plentymarkets Write-back',
                'body' => 'Dummy-Maske fuer Rueckschreiben optimierter Variationstexte.',
                'fields' => [
                    ['name' => 'item_id', 'label' => 'Item ID', 'type' => 'text', 'value' => '154332'],
                    ['name' => 'variation_id', 'label' => 'Variation ID', 'type' => 'text', 'value' => '1136'],
                    ['name' => 'sprache', 'label' => 'Sprache', 'type' => 'text', 'value' => 'de'],
                    ['name' => 'felder', 'label' => 'Felder', 'type' => 'textarea', 'rows' => 5, 'value' => "Name\nTitle\nPreview Description\nDescription\nMeta Keywords"],
                ],
            ],
            'xentral-writeback' => [
                'badge' => 'Xentral',
                'title' => 'Xentral Write-back',
                'body' => 'Dummy-Maske fuer spaeteres Rueckschreiben von Artikeltexten und Freigabestatus.',
                'fields' => [
                    ['name' => 'article_id', 'label' => 'Artikel-ID', 'type' => 'text', 'value' => 'XEN-4711'],
                    ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['Entwurf', 'Zur Freigabe', 'Freigegeben']],
                    ['name' => 'notiz', 'label' => 'Interne Notiz', 'type' => 'textarea', 'rows' => 4, 'value' => 'Optimierte CTC-Texte zur Pruefung bereitstellen.'],
                ],
            ],
            'sap-r3-writeback' => [
                'badge' => 'SAP R/3',
                'title' => 'SAP R/3 Write-back',
                'body' => 'Dummy-Maske fuer IDoc-/BAPI-nahes Rueckschreiben optimierter CTC-Texte ueber SAP Gateway, PI/PO, CPI oder RFC-Proxy.',
                'fields' => [
                    ['name' => 'matnr', 'label' => 'Materialnummer MATNR', 'type' => 'text', 'value' => '000000000000471100'],
                    ['name' => 'mandant', 'label' => 'Mandant', 'type' => 'text', 'value' => '100'],
                    ['name' => 'transport', 'label' => 'Transport', 'type' => 'select', 'options' => ['MATMAS05 IDoc', 'BAPI_MATERIAL_SAVEDATA', 'SAP Gateway Proxy']],
                    ['name' => 'felder', 'label' => 'Felder', 'type' => 'textarea', 'rows' => 5, 'value' => "MAKTX Kurztext\nZCTC Titel\nZCTC Bulletpoints\nZCTC Langtext\nZCTC Keywords"],
                ],
            ],
            'pimcore-writeback' => [
                'badge' => 'Pimcore',
                'title' => 'Pimcore Write-back',
                'body' => 'Dummy-Maske fuer Rueckschreiben optimierter CTC-Texte in Pimcore Data Objects inklusive localized fields und Workflow-Status.',
                'fields' => [
                    ['name' => 'object_id', 'label' => 'Pimcore Objekt-ID', 'type' => 'text', 'value' => '471100'],
                    ['name' => 'class_name', 'label' => 'Klasse', 'type' => 'text', 'value' => 'Product'],
                    ['name' => 'sprache', 'label' => 'Sprache', 'type' => 'text', 'value' => 'de'],
                    ['name' => 'felder', 'label' => 'Felder', 'type' => 'textarea', 'rows' => 5, 'value' => "ctcOptimizedTitle\nctcShortDescription\nctcDescription\nctcBulletpoints\nctcKeywords\nctcQualityScore"],
                ],
            ],
            'sync-job' => [
                'badge' => 'Automation',
                'title' => 'Zeitgesteuerter Sync-Job',
                'body' => 'Dummy-Maske fuer Upsert- und Delta-Laeufe aus JTL, plentymarkets, Xentral, SAP R/3 oder Pimcore.',
                'real_link' => ['label' => 'Echte Sync-Jobs oeffnen', 'route' => 'app_sync_job_index'],
                'fields' => [
                    ['name' => 'jobname', 'label' => 'Jobname', 'type' => 'text', 'value' => 'JTL Delta Bestand und Preis'],
                    ['name' => 'system', 'label' => 'System', 'type' => 'select', 'options' => ['JTL', 'plentymarkets', 'Xentral', 'SAP R/3', 'Pimcore']],
                    ['name' => 'modus', 'label' => 'Modus', 'type' => 'select', 'options' => ['Delta', 'Upsert']],
                    ['name' => 'intervall', 'label' => 'Intervall Minuten', 'type' => 'number', 'value' => '60'],
                ],
            ],
        ];
    }
}
