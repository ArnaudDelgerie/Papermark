<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The AI providers the app knows how to call.
 *
 * Single source of truth: the seed command inserts one Provider row per case,
 * and AiPlatformFactory / AnyModelCatalog map each case to its bridge. The
 * backed value is also the keyring key holding the provider's API key.
 */
enum ProviderName: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Mistral = 'mistral';
}
