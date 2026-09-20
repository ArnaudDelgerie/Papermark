<?php

declare(strict_types=1);

namespace App\File;

enum NoReplaceRenameResult
{
    case Renamed;
    case TargetExists;
    case Failed;
}
