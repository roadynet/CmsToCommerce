<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class DemoImportDownloadController extends AbstractController
{
    /**
     * @var array<string, string>
     */
    private const FILES = [
        '3-produkte-sammeldatei.txt' => 'public/demo-imports/3-produkte-sammeldatei.txt',
        'sectionscode-testpaket.zip' => 'public/demo-imports/sectionscode-testpaket.zip',
    ];

    #[Route('/demo-imports/{filename}', name: 'app_demo_import_download', methods: ['GET'])]
    public function __invoke(string $filename, KernelInterface $kernel): BinaryFileResponse
    {
        $relativePath = self::FILES[$filename] ?? null;
        if ($relativePath === null) {
            throw $this->createNotFoundException('Demo-Datei nicht gefunden.');
        }

        $absolutePath = $kernel->getProjectDir().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($absolutePath)) {
            throw $this->createNotFoundException('Demo-Datei nicht gefunden.');
        }

        return $this->file($absolutePath, $filename, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }
}
