<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AnyModelCatalog;
use App\Enum\ProviderName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;

final class AnyModelCatalogTest extends TestCase
{
    /**
     * @return iterable<string, array{ProviderName, class-string, string}>
     */
    public static function providers(): iterable
    {
        yield 'openai' => [ProviderName::OpenAi, Gpt::class, 'max_output_tokens'];
        yield 'anthropic' => [ProviderName::Anthropic, Claude::class, 'max_tokens'];
        yield 'mistral' => [ProviderName::Mistral, Mistral::class, 'max_tokens'];
    }

    /**
     * @param class-string $modelClass
     */
    #[DataProvider('providers')]
    public function testAcceptsAnyModelNameWithTheBridgeClass(ProviderName $provider, string $modelClass, string $budgetOption): void
    {
        $model = (new AnyModelCatalog($provider))->getModel('model-released-after-the-app');

        self::assertInstanceOf($modelClass, $model);
        self::assertSame('model-released-after-the-app', $model->getName());
        self::assertSame(AnyModelCatalog::MAX_TOKENS, $model->getOptions()[$budgetOption]);
    }
}
