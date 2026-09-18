<?php

declare(strict_types=1);

namespace App\Ai;

use App\Repository\SettingRepository;
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
        private readonly SettingRepository $settings,
        private readonly RequestStack $requestStack,
        private readonly StationContextInterface $stationContext,
        private readonly ApiKeyResolver $apiKeyResolver,
    ) {
    }

    public function isEnabled(): bool
    {
        $provider = $this->settings->getOrCreate()->getSelectedProvider();

        return $this->requestStack->getMainRequest() !== null
            && $this->stationContext->isAsyncWorker()
            && $provider !== null
            && $this->apiKeyResolver->resolve($provider->getName()) !== null;
    }
}
