<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton row for app-wide settings. Holds the images folder the user
 * chose (see EDITOR_IMAGES.md) and which Provider is selected for AI, which
 * used to be a boolean flag on Provider itself.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?string $imageFolder = null;

    #[ORM\OneToOne(targetEntity: Provider::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Provider $selectedProvider = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImageFolder(): ?string
    {
        return $this->imageFolder;
    }

    public function setImageFolder(?string $imageFolder): void
    {
        $this->imageFolder = $imageFolder;
    }

    public function hasImageFolder(): bool
    {
        return $this->imageFolder !== null && $this->imageFolder !== '';
    }

    public function getSelectedProvider(): ?Provider
    {
        return $this->selectedProvider;
    }

    public function setSelectedProvider(?Provider $selectedProvider): void
    {
        $this->selectedProvider = $selectedProvider;
    }
}
