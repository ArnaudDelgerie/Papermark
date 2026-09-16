<?php

declare(strict_types=1);

namespace App\Entity;

use App\Editor\EditorMode;
use App\Repository\SettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton row for app-wide settings. Holds which Provider is selected for
 * AI, which used to be a boolean flag on Provider itself.
 */
#[ORM\Entity(repositoryClass: SettingRepository::class)]
class Setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Provider::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Provider $selectedProvider = null;

    #[ORM\Column(length: 16, enumType: EditorMode::class)]
    private EditorMode $defaultMode = EditorMode::Single;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSelectedProvider(): ?Provider
    {
        return $this->selectedProvider;
    }

    public function setSelectedProvider(?Provider $selectedProvider): void
    {
        $this->selectedProvider = $selectedProvider;
    }

    public function getDefaultMode(): EditorMode
    {
        return $this->defaultMode;
    }

    public function setDefaultMode(EditorMode $defaultMode): void
    {
        $this->defaultMode = $defaultMode;
    }
}
