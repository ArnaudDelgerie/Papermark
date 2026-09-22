<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Provider;
use App\Enum\ProviderName;
use App\Repository\ProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Inserts a Provider row for each ProviderName case that has none yet.
 * Safe to replay: run on every install and update (tfsapp.config.json), so
 * providers added in a later version show up for existing users.
 */
#[AsCommand(name: 'app:ai:seed-providers', description: 'Insert the missing AI providers')]
final class SeedProvidersCommand
{
    public function __construct(
        private readonly ProviderRepository $providers,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $existing = $this->providers->findAllByName();

        $inserted = 0;
        foreach (ProviderName::cases() as $name) {
            if (!isset($existing[$name->value])) {
                $this->entityManager->persist(new Provider($name));
                ++$inserted;
            }
        }

        $this->entityManager->flush();

        $io->success(\sprintf('%d provider(s) inserted.', $inserted));

        return Command::SUCCESS;
    }
}
