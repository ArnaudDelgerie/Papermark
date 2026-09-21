<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\ApiKeyResolver;
use App\Enum\Ai\ProviderName;
use App\Tests\Double\InMemorySecretStore;
use ArnaudDelgerie\TFSAppBundle\Bridge\Exception\SecretsNotEnabledException;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class ApiKeyResolverTest extends TestCase
{
    public function testReturnsStoredKey(): void
    {
        $resolver = new ApiKeyResolver(new InMemorySecretStore(['anthropic' => 'key']), new BufferingLogger());

        self::assertSame('key', $resolver->resolve(ProviderName::Anthropic));
        self::assertNull($resolver->resolve(ProviderName::OpenAi));
    }

    public function testUnavailableKeyringReturnsNull(): void
    {
        $store = $this->createStub(SecretStoreInterface::class);
        $store->method('isAvailable')->willReturn(false);

        self::assertNull((new ApiKeyResolver($store, new BufferingLogger()))->resolve(ProviderName::Anthropic));
    }

    public function testKeyringFailureReturnsNullAndIsLogged(): void
    {
        $store = $this->createStub(SecretStoreInterface::class);
        $store->method('isAvailable')->willReturn(true);
        $store->method('get')->willThrowException(SecretsNotEnabledException::create());
        $logger = new BufferingLogger();

        self::assertNull((new ApiKeyResolver($store, $logger))->resolve(ProviderName::Anthropic));

        $logs = $logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('error', $logs[0][0]);
        self::assertInstanceOf(SecretsNotEnabledException::class, $logs[0][2]['exception']);
    }
}
