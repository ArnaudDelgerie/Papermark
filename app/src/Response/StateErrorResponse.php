<?php

declare(strict_types=1);

namespace App\Response;

use App\Service\Editor\EditorState;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one refusal shape: the state — which a refusal may have corrected —
 * with two lists, always present, empty or not. `genericErrors` are messages
 * the caller shows as such (a toast), `mappedErrors` are messages each bound
 * to a field, for the caller to show in place. Both receive messages already
 * translated: the translation stays with whoever raises the refusal.
 */
final class StateErrorResponse extends StateResponse
{
    /**
     * @param list<string> $genericErrors messages, already translated
     * @param list<array{field: string, message: string}> $mappedErrors each bound to a field
     * @param array<string, string> $headers
     */
    public function __construct(
        EditorState $editorState,
        array $genericErrors = [],
        array $mappedErrors = [],
        int $status = Response::HTTP_BAD_REQUEST,
        array $headers = [],
    ) {
        parent::__construct($editorState, ['genericErrors' => $genericErrors, 'mappedErrors' => $mappedErrors], $status, $headers);
    }
}
