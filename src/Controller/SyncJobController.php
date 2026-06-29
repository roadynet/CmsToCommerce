<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ExternalSyncJob;
use App\Enum\ExternalSyncMode;
use App\Enum\ExternalSystemType;
use App\Repository\ExternalSyncJobRepository;
use App\Service\Integration\ExternalSyncJobRunner;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sync-jobs')]
final class SyncJobController extends AbstractController
{
    #[Route('', name: 'app_sync_job_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        ExternalSyncJobRepository $externalSyncJobRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('sync_job_create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
            }

            $name = trim((string) $request->request->get('name', ''));
            $systemValue = trim((string) $request->request->get('system', ''));
            $modeValue = trim((string) $request->request->get('mode', ''));

            if ($name === '') {
                $this->addFlash('warning', 'Bitte einen Jobnamen angeben.');

                return $this->redirectToRoute('app_sync_job_index');
            }

            try {
                $system = ExternalSystemType::from($systemValue);
                $mode = ExternalSyncMode::from($modeValue);
            } catch (\ValueError) {
                $this->addFlash('warning', 'System oder Modus ist ungültig.');

                return $this->redirectToRoute('app_sync_job_index');
            }

            try {
                $headers = $this->parseJsonField((string) $request->request->get('request_headers_json', '{}'));
                $body = $this->parseJsonField((string) $request->request->get('request_body_json', '{}'));
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('warning', $exception->getMessage());

                return $this->redirectToRoute('app_sync_job_index');
            }

            $job = new ExternalSyncJob($name, $system, $mode);
            $job
                ->setSourceUrl($this->nullable($request->request->get('source_url')))
                ->setSourceFilePath($this->nullable($request->request->get('source_file_path')))
                ->setRequestMethod((string) $request->request->get('request_method', 'GET'))
                ->setRequestHeaders($headers)
                ->setRequestBody($body)
                ->setIntervalMinutes(max(1, (int) $request->request->get('interval_minutes', 60)))
                ->setEnabled($request->request->get('enabled') === '1');

            if ($job->getSourceUrl() === null && $job->getSourceFilePath() === null) {
                $this->addFlash('warning', 'Bitte Quell-URL oder Quelldatei setzen.');

                return $this->redirectToRoute('app_sync_job_index');
            }

            $entityManager->persist($job);
            $entityManager->flush();

            $this->addFlash('success', sprintf('Sync-Job "%s" wurde angelegt.', $job->getName()));

            return $this->redirectToRoute('app_sync_job_index');
        }

        $jobs = [];
        $storageUnavailable = false;

        try {
            $jobs = $externalSyncJobRepository->findBy([], ['updatedAt' => 'DESC']);
        } catch (DbalException) {
            $storageUnavailable = true;
            $this->addFlash('warning', 'Die Sync-Job-Datenbank ist aktuell nicht verfügbar. Die Oberfläche bleibt nutzbar, aber gespeicherte Jobs können gerade nicht geladen werden.');
        }

        return $this->render('sync_job/index.html.twig', [
            'jobs' => $jobs,
            'systems' => array_filter(
                ExternalSystemType::cases(),
                static fn (ExternalSystemType $type): bool => $type !== ExternalSystemType::Generic,
            ),
            'modes' => ExternalSyncMode::cases(),
            'now' => new \DateTimeImmutable(),
            'storageUnavailable' => $storageUnavailable,
        ]);
    }

    #[Route('/{id}/run', name: 'app_sync_job_run', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function run(ExternalSyncJob $job, Request $request, ExternalSyncJobRunner $externalSyncJobRunner): Response
    {
        if (!$this->isCsrfTokenValid('sync_job_run_'.$job->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        try {
            $summary = $externalSyncJobRunner->run($job);
            $this->addFlash('success', sprintf('%s: %s', $job->getName(), $summary['message']));
        } catch (\Throwable $exception) {
            $this->addFlash('warning', sprintf('%s fehlgeschlagen: %s', $job->getName(), $exception->getMessage()));
        }

        return $this->redirectToRoute('app_sync_job_index');
    }

    #[Route('/{id}/delete', name: 'app_sync_job_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(ExternalSyncJob $job, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('sync_job_delete_'.$job->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $name = $job->getName();
        $entityManager->remove($job);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Sync-Job "%s" wurde gelöscht.', $name));

        return $this->redirectToRoute('app_sync_job_index');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonField(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Header- oder Body-JSON ist ungültig.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Header- oder Body-JSON muss ein Objekt sein.');
        }

        return $decoded;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
