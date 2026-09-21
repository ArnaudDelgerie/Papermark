<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Provider;
use App\Enum\Ai\ProviderName;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Provider>
 */
class ProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Provider::class);
    }

    /**
     * Providers keyed by name, in ProviderName declaration order.
     *
     * @return array<string, Provider>
     */
    public function findAllByName(): array
    {
        $byName = [];
        foreach ($this->findAll() as $provider) {
            $byName[$provider->getName()->value] = $provider;
        }

        $ordered = [];
        foreach (ProviderName::cases() as $name) {
            if (isset($byName[$name->value])) {
                $ordered[$name->value] = $byName[$name->value];
            }
        }

        return $ordered;
    }
}
