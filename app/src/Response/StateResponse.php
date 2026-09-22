<?php

declare(strict_types=1);

namespace App\Response;

use App\Service\EditorState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A JSON response that carries the editor state, so the caller never has to
 * guess where it ends up — success or refusal (EDITOR_REACTIVITY.md, S5-S6).
 * The state is what the session holds when the answer is built.
 *
 * @see StateSuccessResponse the success form, with what was done
 * @see StateErrorResponse   the refusal form, with what went wrong
 */
abstract class StateResponse extends JsonResponse
{
    /**
     * @param array<string, mixed> $payload the keys this form adds to the state
     * @param array<string, string> $headers
     */
    public function __construct(EditorState $editorState, array $payload, int $status = Response::HTTP_OK, array $headers = [])
    {
        parent::__construct(['state' => $editorState->toArray(), ...$payload], $status, $headers);
    }
}
