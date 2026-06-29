<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\SectionCodeFileGrouper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SectionCodeFileGrouperTest extends TestCase
{
    public function testGroupsTxtAndAssetsBySectionCode(): void
    {
        $grouper = new SectionCodeFileGrouper();

        $result = $grouper->group([
            $this->uploadedFile('1.1.txt'),
            $this->uploadedFile('1.1.jpg'),
            $this->uploadedFile('1.1.2.jpg'),
            $this->uploadedFile('1.2.txt'),
            $this->uploadedFile('1.2.1.png'),
            $this->uploadedFile('sonstiges.jpg'),
        ]);

        self::assertCount(2, $result['products']);
        self::assertSame('1.1', $result['products'][0]['section_code']);
        self::assertSame(['1.1.jpg', '1.1.2.jpg'], array_map(
            static fn (UploadedFile $file): string => $file->getClientOriginalName(),
            $result['products'][0]['asset_files'],
        ));
        self::assertSame('1.2', $result['products'][1]['section_code']);
        self::assertSame(['1.2.1.png'], array_map(
            static fn (UploadedFile $file): string => $file->getClientOriginalName(),
            $result['products'][1]['asset_files'],
        ));
        self::assertSame(['sonstiges.jpg'], $result['unmatched_files']);
    }

    public function testRejectsDuplicateTxtFilesForSameSectionCode(): void
    {
        $grouper = new SectionCodeFileGrouper();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sectionscode "1.1" ist mehrfach als TXT-Datei vorhanden.');

        $grouper->group([
            $this->uploadedFile('1.1.txt'),
            $this->uploadedFile('1.1.txt'),
        ]);
    }

    private function uploadedFile(string $originalName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ctc_');
        if ($path === false) {
            self::fail('Temporäre Datei konnte nicht erzeugt werden.');
        }

        file_put_contents($path, 'demo');

        $mimeType = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) === 'txt'
            ? 'text/plain'
            : 'image/jpeg';

        return new UploadedFile($path, $originalName, $mimeType, null, true);
    }
}
