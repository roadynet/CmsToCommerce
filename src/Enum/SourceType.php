<?php

declare(strict_types=1);

namespace App\Enum;

enum SourceType: string
{
    case CmsImport = 'cms_import';
    case ManualText = 'manual_text';
    case FileUpload = 'file_upload';
    case ImageUpload = 'image_upload';

    public function label(): string
    {
        return match ($this) {
            self::CmsImport => 'CMS-Import',
            self::ManualText => 'Manueller Text',
            self::FileUpload => 'Datei-Upload',
            self::ImageUpload => 'Bild-Upload',
        };
    }
}
