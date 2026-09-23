<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiRequest;
use App\Enum\AiRequestStatus;
use App\Enum\ProviderName;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiRequest>
 */
class AiRequestRepository extends ServiceEntityRepository
{
    /** Past this age, a queued request is expired at take: a redelivered message never calls the provider. */
    private const MAX_AGE_SECONDS = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiRequest::class);
    }

    /**
     * /ai/instruct: the durable line exists before the message is queued, so an
     * abort can land at any point of the request's life.
     */
    public function createPending(string $id): void
    {
        $this->getEntityManager()->persist(new AiRequest($id));
        $this->getEntityManager()->flush();
    }

    /**
     * /ai/abort: only a pending request can be aborted, before or during the
     * worker's call. Aborting a finished one is a no-op: history stays as it is.
     */
    public function abort(string $id): void
    {
        $this->transition($id, AiRequestStatus::Aborted);
    }

    /**
     * The worker's take. Returns the pending row, or null when the provider
     * must not be called at all: the row is absent, already aborted or
     * finished, or older than five minutes (then marked expired, which covers
     * a message the transport redelivers long after the app was closed).
     */
    public function claim(string $id): ?AiRequest
    {
        $request = $this->find($id);
        if ($request === null || !$request->isPending()) {
            return null;
        }

        if ($request->getCreatedAt() < new \DateTimeImmutable(\sprintf('-%d seconds', self::MAX_AGE_SECONDS))) {
            $this->transition($id, AiRequestStatus::Expired);

            return null;
        }

        return $request;
    }

    /**
     * A scalar read of the current status: the worker's copy of the row goes
     * stale the moment the web process aborts, so mid-stream checks must not
     * trust the identity map.
     */
    public function isAborted(string $id): bool
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM ai_request WHERE id = ? AND status = ?',
            [$id, AiRequestStatus::Aborted->value],
        );

        return $result !== false;
    }

    /**
     * Records what the request actually uses. The provider and model are read
     * from the database, never taken from the client.
     */
    public function recordRun(AiRequest $request, ProviderName $provider, string $model): void
    {
        $request->setProvider($provider);
        $request->setModel($model);
        $this->getEntityManager()->flush();
    }

    /**
     * Ends the request (done or failed) with the tokens consumed when the
     * provider reports them, null otherwise. Conditional: an abort that landed
     * mid-stream must not be overwritten by the end the worker was about to
     * write.
     */
    public function finish(string $id, AiRequestStatus $status, ?int $promptTokens = null, ?int $completionTokens = null): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE ai_request SET status = ?, prompt_tokens = ?, completion_tokens = ? WHERE id = ? AND status = ?',
            [$status->value, $promptTokens, $completionTokens, $id, AiRequestStatus::Pending->value],
        );
    }

    /**
     * pending -> $status, as a conditional update: a row another process has
     * already moved on is left alone.
     */
    private function transition(string $id, AiRequestStatus $status): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE ai_request SET status = ? WHERE id = ? AND status = ?',
            [$status->value, $id, AiRequestStatus::Pending->value],
        );
    }
}
