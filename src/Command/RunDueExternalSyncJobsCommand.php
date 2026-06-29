<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ExternalSyncJobRepository;
use App\Service\Integration\ExternalSyncJobRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:external-sync:run-due-jobs',
    description: 'Runs all enabled external sync jobs whose interval is due.',
)]
final class RunDueExternalSyncJobsCommand extends Command
{
    public function __construct(
        private readonly ExternalSyncJobRepository $externalSyncJobRepository,
        private readonly ExternalSyncJobRunner $externalSyncJobRunner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobs = $this->externalSyncJobRepository->findDueJobs(new \DateTimeImmutable());

        if ($jobs === []) {
            $io->success('Keine fälligen externen Sync-Jobs gefunden.');

            return Command::SUCCESS;
        }

        foreach ($jobs as $job) {
            try {
                $summary = $this->externalSyncJobRunner->run($job);
                $io->success(sprintf('%s: %s', $job->getName(), $summary['message']));
            } catch (\Throwable $exception) {
                $io->warning(sprintf('%s fehlgeschlagen: %s', $job->getName(), $exception->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
