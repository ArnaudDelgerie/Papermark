<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The durable state of one AI request, kept across the web process (which
 * creates and aborts) and the worker (which consumes and finishes). "expired"
 * marks a request taken more than five minutes after being queued.
 */
enum AiRequestStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
    case Aborted = 'aborted';
    case Expired = 'expired';
}
