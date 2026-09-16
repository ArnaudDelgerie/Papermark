<?php

declare(strict_types=1);

namespace App\Editor;

enum EditorMode: string
{
    case Single = 'single';
    case Dir = 'dir';
}
