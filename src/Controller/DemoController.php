<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController extends AbstractController
{
    #[Route('/demo', name: 'app_public_demo', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('demo/index.html.twig', [
            'flow_steps' => [
                [
                    'step' => '01',
                    'title' => 'TXT + Bilder rein',
                    'body' => 'Produktdaten werden als Sectionscode-TXT und die Bilder separat ausgewählt. Gleiche Codes gehören automatisch zusammen.',
                    'sample' => "1.1.txt\n1.1.png\n1.1.1.png\n1.1.2.png\n\n1.2.txt\n1.2.png\n1.2.1.png",
                ],
                [
                    'step' => '02',
                    'title' => 'Produkt entsteht',
                    'body' => 'CTC bündelt Rohdaten, Varianten, Medien und Pflichtfelder zu einem bearbeitbaren Produktstamm.',
                    'sample' => "Produkt: Edelstahl Trinkflasche 750 ml\nMarke: North Trail\nVarianten: Farbe, Größe, EAN\nMedien: Hauptbild + Detailbilder",
                ],
                [
                    'step' => '03',
                    'title' => 'Amazon / Shopware Preview',
                    'body' => 'Aus dem Produkt werden verkaufsfähige Titel, Bulletpoints, Attribute, Bildreihenfolge und Kanal-Payloads erzeugt.',
                    'sample' => "Amazon: Product Type Mapping + Validation Preview\nShopware: Produktdaten + Medienzuordnung\nQualität: A-Level-Check",
                ],
                [
                    'step' => '04',
                    'title' => 'Zugangsdaten & Sync',
                    'body' => 'Shopware, Amazon, JTL, plentymarkets, Xentral, SAP, Pimcore und Shopify sind als Kanäle vorbereitet.',
                    'sample' => "Credentials: pro Channel per Formular\nSync: manuell oder zeitgesteuert\nWrite-back: optimierte Texte zurück ins Zielsystem",
                ],
            ],
            'preview_cards' => [
                [
                    'label' => 'Amazon',
                    'title' => 'Listing Preview',
                    'body' => 'Product-Type-Mapping, Pflichtattribute, SEO-Titel, Bulletpoints und JSON-Payload werden vor Live-Publish validiert.',
                    'status' => 'Preview sicher',
                ],
                [
                    'label' => 'Shopware',
                    'title' => 'Admin API Sync',
                    'body' => 'Produkt, Beschreibung, Varianten, Preise, Bestand und Medien werden für die Shopware Admin API vorbereitet.',
                    'status' => 'Sync bereit',
                ],
                [
                    'label' => 'Warenwirtschaft',
                    'title' => 'Write-back',
                    'body' => 'Optimierte CTC-Texte können in JTL, plentymarkets, Xentral, SAP R/3, Pimcore oder Shopify zurückgeschrieben werden.',
                    'status' => 'Adapter vorbereitet',
                ],
            ],
        ]);
    }
}
