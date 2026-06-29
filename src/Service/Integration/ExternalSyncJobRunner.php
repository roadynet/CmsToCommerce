<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Entity\ExternalSyncJob;
use App\Enum\ChannelType;
use App\Service\Publishing\PublicationOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExternalSyncJobRunner
{
    public function __construct(
        private readonly ExternalSystemIntakeRegistry $externalSystemIntakeRegistry,
        private readonly ExternalSystemProductSyncManager $externalSystemProductSyncManager,
        private readonly PublicationOrchestrator $publicationOrchestrator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * @return array{processed: int, created: int, media: int, variants_updated: int, variants_created: int, warnings: list<string>, message: string}
     */
    public function run(ExternalSyncJob $job): array
    {
        try {
            $records = $this->recordsFromPayload($this->fetchPayload($job));
            if ($records === []) {
                throw new \RuntimeException('Der Job hat keine verwertbaren Datensätze geliefert.');
            }

            $processed = 0;
            $created = 0;
            $media = 0;
            $variantsUpdated = 0;
            $variantsCreated = 0;
            $warnings = [];

            foreach ($records as $record) {
                $normalized = $this->externalSystemIntakeRegistry->normalize($record, $job->getSystem()->value);
                $syncResult = $this->externalSystemProductSyncManager->sync($normalized, $job->getMode()->value === 'delta');

                foreach (ChannelType::cases() as $channel) {
                    $this->publicationOrchestrator->prepare($syncResult->product, $channel);
                }

                ++$processed;
                $created += $syncResult->created ? 1 : 0;
                $media += $syncResult->mediaAdded;
                $variantsUpdated += $syncResult->variantsUpdated;
                $variantsCreated += $syncResult->variantsCreated;
                $warnings = array_merge($warnings, $syncResult->warnings);
            }

            $message = sprintf(
                '%d Datensatz/Datensätze verarbeitet · %d neu · %d Medien · %d Varianten aktualisiert · %d Varianten neu',
                $processed,
                $created,
                $media,
                $variantsUpdated,
                $variantsCreated,
            );

            if ($warnings !== []) {
                $message .= ' · Warnungen: '.count($warnings);
            }

            $job->markSuccess($message);
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            return [
                'processed' => $processed,
                'created' => $created,
                'media' => $media,
                'variants_updated' => $variantsUpdated,
                'variants_created' => $variantsCreated,
                'warnings' => $warnings,
                'message' => $message,
            ];
        } catch (\Throwable $exception) {
            $job->markFailure($exception->getMessage());
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPayload(ExternalSyncJob $job): array
    {
        $sourceFile = trim((string) $job->getSourceFilePath());
        if ($sourceFile !== '') {
            if (!is_file($sourceFile) || !is_readable($sourceFile)) {
                throw new \RuntimeException(sprintf('Job-Datei "%s" ist nicht lesbar.', $sourceFile));
            }

            $content = (string) file_get_contents($sourceFile);

            return $this->decodeJson($content, $sourceFile);
        }

        $sourceUrl = trim((string) $job->getSourceUrl());
        if ($sourceUrl === '') {
            throw new \RuntimeException('Weder sourceUrl noch sourceFilePath sind für den Job gesetzt.');
        }

        $options = [
            'headers' => $job->getRequestHeaders(),
        ];

        if (in_array($job->getRequestMethod(), ['POST', 'PUT', 'PATCH'], true) && $job->getRequestBody() !== []) {
            $options['json'] = $job->getRequestBody();
        }

        $response = ($this->httpClient ?? HttpClient::create())->request($job->getRequestMethod(), $sourceUrl, $options);
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf('Job-Quelle "%s" antwortete mit HTTP %d.', $sourceUrl, $statusCode));
        }

        return $this->decodeJson($response->getContent(false), $sourceUrl);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function recordsFromPayload(array $payload): array
    {
        foreach (['items', 'products', 'data', 'entries'] as $listKey) {
            if (isset($payload[$listKey]) && is_array($payload[$listKey])) {
                return array_values(array_filter($payload[$listKey], 'is_array'));
            }
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        return [$payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $content, string $source): array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('JSON aus "%s" konnte nicht gelesen werden: %s', $source, $exception->getMessage()), previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('JSON aus "%s" muss ein Objekt oder Array liefern.', $source));
        }

        return $decoded;
    }
}
