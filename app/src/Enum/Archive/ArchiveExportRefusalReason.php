<?php

declare(strict_types=1);

namespace App\Enum\Archive;

/**
 * Why an export was refused in full (see ArchiveExportRefusedException): the
 * value is the last segment of the refusal's translation key — it is never
 * displayed on its own.
 */
enum ArchiveExportRefusalReason: string
{
    case ExtImgConflict = 'ext_img_conflict';
    case ExtMdConflict = 'ext_md_conflict';
    case Empty = 'empty';
    case UnsupportedFileType = 'unsupported_file_type';
}
