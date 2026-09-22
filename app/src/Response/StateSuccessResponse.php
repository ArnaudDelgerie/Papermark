<?php

declare(strict_types=1);

namespace App\Response;

use App\Service\Editor\EditorState;
use Symfony\Component\HttpFoundation\Response;

/**
 * The answer of a route that writes the state: what it holds now, and what
 * was done — paths realpath'd. `action` is always an object, even when empty,
 * so the caller can tell an empty one from a missing one.
 */
final class StateSuccessResponse extends StateResponse
{
    /**
     * @param array<string, mixed> $action what was done
     * @param array<string, string> $headers
     */
    public function __construct(EditorState $editorState, array $action = [], array $headers = [])
    {
        parent::__construct($editorState, ['action' => (object) $action], Response::HTTP_OK, $headers);
    }
}
