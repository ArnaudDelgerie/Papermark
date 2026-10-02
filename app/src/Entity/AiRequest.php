<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AiRequestStatus;
use App\Enum\ProviderName;
use App\Repository\AiRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The durable state of one AI request, keyed by the id the browser generated.
 * The web process creates it (pending) and can abort it; the worker claims it,
 * records what was actually used, and ends it. It replaces the abort flag that
 * used to live in cache.app, and keeps the data a future request journal will
 * need: no row is ever cleaned up.
 */
#[ORM\Entity(repositoryClass: AiRequestRepository::class)]
class AiRequest
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 16, enumType: AiRequestStatus::class)]
    private AiRequestStatus $status = AiRequestStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true, enumType: ProviderName::class)]
    private ?ProviderName $provider = null;

    #[ORM\Column(nullable: true)]
    private ?string $model = null;

    #[ORM\Column(nullable: true)]
    private ?int $promptTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $completionTokens = null;

    public function __construct(string $id, ?\DateTimeImmutable $createdAt = null)
    {
        $this->id = $id;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable('now');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): AiRequestStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return AiRequestStatus::Pending === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getProvider(): ?ProviderName
    {
        return $this->provider;
    }

    public function setProvider(ProviderName $provider): void
    {
        $this->provider = $provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }
}
