<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * Accepts any model name for a provider.
 *
 * The bridges' catalogs are hard-coded lists: a model released after the app
 * would be rejected. The bridges' model clients require their own Model class
 * (Gpt, Claude, Mistral), so FallbackModelCatalog's generic Model can't be used.
 * An invalid name surfaces as a provider error at call time.
 */
final class AnyModelCatalog implements ModelCatalogInterface
{
    /** Output token budget; Claude defaults to 1000, too short for a long rewrite. */
    public const MAX_TOKENS = 8000;

    public function __construct(
        private readonly ProviderName $provider,
    ) {
    }

    public function getModel(string $modelName): Model
    {
        return match ($this->provider) {
            // The Responses API names the budget max_output_tokens.
            ProviderName::OpenAi => new Gpt($modelName, Capability::cases(), ['max_output_tokens' => self::MAX_TOKENS]),
            ProviderName::Anthropic => new Claude($modelName, Capability::cases(), ['max_tokens' => self::MAX_TOKENS]),
            ProviderName::Mistral => new Mistral($modelName, Capability::cases(), ['max_tokens' => self::MAX_TOKENS]),
        };
    }

    public function getModels(): array
    {
        return [];
    }
}
