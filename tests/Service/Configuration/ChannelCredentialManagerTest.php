<?php

declare(strict_types=1);

namespace App\Tests\Service\Configuration;

use App\Service\Configuration\ChannelCredentialManager;
use PHPUnit\Framework\TestCase;

final class ChannelCredentialManagerTest extends TestCase
{
    public function testSavesShopifyCredentialsMaskedAndPreservesExistingSecret(): void
    {
        $projectDir = $this->temporaryProjectDir();
        $manager = new ChannelCredentialManager($projectDir);
        $envNames = [
            'SHOPIFY_SHOP_DOMAIN',
            'SHOPIFY_ADMIN_ACCESS_TOKEN',
            'SHOPIFY_ADMIN_API_VERSION',
            'SHOPIFY_ENABLE_LIVE_WRITEBACK',
        ];
        $snapshot = $this->snapshotRuntimeEnv($envNames);
        $this->clearRuntimeEnv($envNames);

        try {
            $manager->save('shopify', [
                'SHOPIFY_SHOP_DOMAIN' => 'ctc-demo.myshopify.com',
                'SHOPIFY_ADMIN_ACCESS_TOKEN' => 'shpat_secret_12345678',
                'SHOPIFY_ADMIN_API_VERSION' => '2026-04',
                'SHOPIFY_ENABLE_LIVE_WRITEBACK' => '1',
            ]);

            $path = dirname($projectDir).DIRECTORY_SEPARATOR.'private-config'.DIRECTORY_SEPARATOR.'ctc-shopify.env';
            self::assertFileExists($path);
            self::assertStringContainsString('SHOPIFY_SHOP_DOMAIN=ctc-demo.myshopify.com', (string) file_get_contents($path));
            self::assertStringContainsString('SHOPIFY_ADMIN_ACCESS_TOKEN=shpat_secret_12345678', (string) file_get_contents($path));

            $channel = $manager->channel('shopify');
            self::assertTrue($channel['ready']);
            self::assertSame([], $channel['required_missing']);

            $tokenField = $this->field($channel['fields'], 'SHOPIFY_ADMIN_ACCESS_TOKEN');
            self::assertTrue($tokenField['has_value']);
            self::assertSame('', $tokenField['value']);
            self::assertSame('shpa••••5678', $tokenField['masked_value']);

            $manager->save('shopify', [
                'SHOPIFY_SHOP_DOMAIN' => 'ctc-live.myshopify.com',
                'SHOPIFY_ADMIN_ACCESS_TOKEN' => '',
                'SHOPIFY_ADMIN_API_VERSION' => '2026-04',
            ]);

            $fileContent = (string) file_get_contents($path);
            self::assertStringContainsString('SHOPIFY_SHOP_DOMAIN=ctc-live.myshopify.com', $fileContent);
            self::assertStringContainsString('SHOPIFY_ADMIN_ACCESS_TOKEN=shpat_secret_12345678', $fileContent);
            self::assertStringContainsString('SHOPIFY_ENABLE_LIVE_WRITEBACK=0', $fileContent);
        } finally {
            $this->restoreRuntimeEnv($snapshot);
        }
    }

    public function testChannelsExposeSeparatePrivateConfigFiles(): void
    {
        $manager = new ChannelCredentialManager($this->temporaryProjectDir());
        $channels = $manager->channels();

        self::assertArrayHasKey('shopware', $channels);
        self::assertArrayHasKey('amazon', $channels);
        self::assertArrayHasKey('shopify', $channels);
        self::assertArrayHasKey('jtl', $channels);
        self::assertArrayHasKey('plentymarkets', $channels);
        self::assertArrayHasKey('xentral', $channels);
        self::assertArrayHasKey('sap-r3', $channels);
        self::assertArrayHasKey('pimcore', $channels);

        self::assertStringEndsWith('ctc-shopware.env', $channels['shopware']['file_path']);
        self::assertStringEndsWith('ctc-amazon.env', $channels['amazon']['file_path']);
        self::assertStringEndsWith('ctc-xentral.env', $channels['xentral']['file_path']);
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return array<string, mixed>
     */
    private function field(array $fields, string $env): array
    {
        foreach ($fields as $field) {
            if (($field['env'] ?? null) === $env) {
                return $field;
            }
        }

        self::fail('Feld nicht gefunden: '.$env);
    }

    private function temporaryProjectDir(): string
    {
        $baseDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ctc-credentials-'.bin2hex(random_bytes(6));
        $projectDir = $baseDir.DIRECTORY_SEPARATOR.'project';
        if (!mkdir($projectDir, 0775, true) && !is_dir($projectDir)) {
            self::fail('Temporärer Projektordner konnte nicht erstellt werden.');
        }

        return $projectDir;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, array{server: mixed, env: mixed, getenv: string|false}>
     */
    private function snapshotRuntimeEnv(array $names): array
    {
        $snapshot = [];
        foreach ($names as $name) {
            $snapshot[$name] = [
                'server' => $_SERVER[$name] ?? null,
                'env' => $_ENV[$name] ?? null,
                'getenv' => getenv($name),
            ];
        }

        return $snapshot;
    }

    /**
     * @param list<string> $names
     */
    private function clearRuntimeEnv(array $names): void
    {
        foreach ($names as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
    }

    /**
     * @param array<string, array{server: mixed, env: mixed, getenv: string|false}> $snapshot
     */
    private function restoreRuntimeEnv(array $snapshot): void
    {
        foreach ($snapshot as $name => $values) {
            if ($values['server'] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $values['server'];
            }

            if ($values['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $values['env'];
            }

            if ($values['getenv'] === false) {
                putenv($name);
            } else {
                putenv($name.'='.$values['getenv']);
            }
        }
    }
}
