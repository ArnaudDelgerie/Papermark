<?php

declare(strict_types=1);

namespace App\File;

enum MarkdownReferenceType: string
{
    case Image = 'image';
    case Link = 'link';
}
