<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Mints (and remembers) the unguessable Mercure topic AI events are published on.
 *
 * One topic per session, never taken from the client: a browser holds a single
 * Mercure cookie. Streams are told apart by their request id.
 */
final class AiTopicResolver
{
    private const SESSION_KEY = 'ai_mercure_topic';

    public function resolve(Request $request): string
    {
        $session = $request->getSession();
        $topic = $session->get(self::SESSION_KEY);
        if (!\is_string($topic)) {
            $topic = 'ai/' . Uuid::v4()->toRfc4122();
            $session->set(self::SESSION_KEY, $topic);
        }

        return $topic;
    }
}
