<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Enum\Ai\ProviderName;
use App\Repository\ProviderRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedProvidersCommandTest extends KernelTestCase
{
    public function testInsertsEveryProviderOnceWhenReplayed(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:ai:seed-providers'));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('3 provider(s) inserted', $tester->getDisplay());

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('0 provider(s) inserted', $tester->getDisplay());

        $providers = self::getContainer()->get(ProviderRepository::class)->findAllByName();
        self::assertSame(array_map(static fn (ProviderName $name) => $name->value, ProviderName::cases()), array_keys($providers));

        foreach ($providers as $provider) {
            self::assertNull($provider->getModel());
        }
    }
}
