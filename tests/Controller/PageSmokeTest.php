<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PageSmokeTest extends WebTestCase
{
    public function testLoginLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Anmeldung');
    }

    public function testProtectedPagesRedirectToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testAdminCanLoginAndOpenProductAreas(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Anmelden')->form([
            '_username' => 'admin',
            '_password' => 'change-me',
        ]);

        $client->submit($form);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'CMS to Commerce Hub');

        $client->request('GET', '/products');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Produkte');

        $client->request('GET', '/products/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Listen-Import per Sectionscode');
        self::assertSelectorTextContains('body', 'Dateien prüfen');
        self::assertSelectorTextContains('body', 'Manueller Einzelimport');

        $crawler = $client->request('GET', '/forms');
        self::assertResponseRedirects('/forms/sections-import');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Formulare');
        self::assertSelectorTextContains('body', 'Sectionscode Upload');

        $form = $crawler->selectButton('Dummy speichern')->form();
        $client->submit($form);
        self::assertResponseRedirects('/forms/sections-import');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Dummy-Formular');

        $client->request('GET', '/sync-jobs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sync-Jobs');

        $client->request('GET', '/demo-imports/3-produkte-sammeldatei.txt');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment;', (string) $client->getResponse()->headers->get('content-disposition'));
    }
}
