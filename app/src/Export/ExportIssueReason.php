<?php

declare(strict_types=1);

namespace App\Export;

enum ExportIssueReason: string
{
    case NotFound = 'not_found';
    case LimitExceeded = 'limit_exceeded';
}
