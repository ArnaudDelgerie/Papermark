<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The AI providers the app knows how to call.
 *
 * Single source of truth: the seed command inserts one Provider row per case,
 * and AiPlatformFactory / AnyModelCatalog map each case to its bridge. The
 * backed value is also the keyring key holding the provider's API key.
 */
enum ProviderName: string implements TranslatableInterface
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Mistral = 'mistral';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enums.provider_name.' . $this->value, domain: 'enums', locale: $locale);
    }
}
