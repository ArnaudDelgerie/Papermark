<?php

declare(strict_types=1);

namespace App\Ai;

use App\Setting\SettingStore;
use ArnaudDelgerie\TFSAppBundle\StationContext\StationContextInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * AI is offered only with a worker, a selected provider, and a key for it.
 * Shared by the editor component (its AI config) and the editor page (the
 * `ai_enabled` of the client state, see EDITOR_TS_MIGRATION.md).
 */
final class AiAvailability
{
    public function __construct(
        private readonly SettingStore $settings,
        private readonly RequestStack $requestStack,
        private readonly StationContextInterface $stationContext,
        private readonly ApiKeyResolver $apiKeyResolver,
    ) {
    }

    public function isEnabled(): bool
    {
        $providerName = $this->settings->getSelectedProviderName();

        return $this->requestStack->getMainRequest() !== null
            && $this->stationContext->isAsyncWorker()
            && $providerName !== null
            && $this->apiKeyResolver->resolve($providerName) !== null;
    }
}
