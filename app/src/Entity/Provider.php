<?php

declare(strict_types=1);

namespace App\Entity;

use App\Ai\ProviderName;
use App\Repository\ProviderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An AI provider and the user's choices for it. The API key is not stored
 * here: it lives in the TFSApp keyring, under the provider's name. Which
 * provider is selected is not stored here either: see Setting::$selectedProvider.
 */
#[ORM\Entity(repositoryClass: ProviderRepository::class)]
class Provider
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?string $model = null;

    public function __construct(
        #[ORM\Column(unique: true, enumType: ProviderName::class)]
        private ProviderName $name,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ProviderName
    {
        return $this->name;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
    }
}
