<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AiHistoryController;
use App\Entity\AiRequest;
use App\Enum\AiRequestStatus;
use App\Enum\ProviderName;
use App\Repository\AiRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The AI history modal (EDITOR_AI_HISTORY.md): only the requests that got an
 * answer from the API are listed, AiHistoryController::PAGE_SIZE per page,
 * and one line can be deleted — behind a CSRF token, and back to the page
 * it came from.
 */
final class AiHistoryControllerTest extends WebTestCase
{
    public function testListsOnlyTheAnsweredRequestsNewestFirst(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->insertRow($client, ProviderName::Mistral, 'mistral-small-latest', AiRequestStatus::Done, 100, 240, '-2 hours');
        $this->insertRow($client, ProviderName::OpenAi, 'gpt-4.1', AiRequestStatus::Failed, null, null, '-1 hour');

        // Every other life of a request stays out of the history.
        $this->insertRow($client, ProviderName::Anthropic, 'claude-sonnet', null, null, null, '-30 minutes');
        $this->insertRow($client, ProviderName::Mistral, 'mistral-small-latest', AiRequestStatus::Aborted, null, null, '-20 minutes');
        $this->insertRow($client, null, null, AiRequestStatus::Done, null, null, '-10 minutes');
        $this->insertRow($client, null, null, AiRequestStatus::Failed, null, null, '-5 minutes');
        $this->insertRow($client, ProviderName::OpenAi, 'gpt-4.1', null, null, null, '-1 hour');
        $this->expireRow($client);

        $crawler = $client->request('GET', '/ai/history');

        self::assertResponseIsSuccessful();
        // The two answered requests, newest first.
        self::assertSame(['gpt-4.1', 'mistral-small-latest'], $crawler->filter('.ai-history-model')->extract(['_text']));
        // A count the provider did not report reads as a dash.
        self::assertSame(['—', '100'], $crawler->filter('tbody tr td:nth-child(4)')->extract(['_text']));
        self::assertSame(['—', '240'], $crawler->filter('tbody tr td:nth-child(5)')->extract(['_text']));
    }

    public function testAnEmptyHistorySaysSo(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/ai/history');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ai-history-table'));
        self::assertCount(1, $crawler->filter('.ai-history-empty'));
    }

    public function testOneMoreRowThanThePageMakesTwoPages(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->insertHistory($client, AiHistoryController::PAGE_SIZE + 1);

        $crawler = $client->request('GET', '/ai/history');
        self::assertCount(AiHistoryController::PAGE_SIZE, $crawler->filter('tbody tr'));
        self::assertCount(1, $crawler->filter('.ai-history-pagination'));

        $crawler = $client->request('GET', '/ai/history?page=2');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertCount(1, $crawler->filter('.ai-history-pagination'));
    }

    public function testExactlyOnePageOfRowsMakesNoPagination(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->insertHistory($client, AiHistoryController::PAGE_SIZE);

        $crawler = $client->request('GET', '/ai/history');
        self::assertCount(AiHistoryController::PAGE_SIZE, $crawler->filter('tbody tr'));
        self::assertCount(0, $crawler->filter('.ai-history-pagination'));
    }

    public function testDeleteRemovesTheRowAndRedirectsToItsPage(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $ids = $this->insertHistory($client, AiHistoryController::PAGE_SIZE + 1);

        // The newest row sits on page 1, where its delete button is.
        $client->request('POST', '/ai/history/' . $ids[0] . '/delete?page=1', ['_token' => $this->csrfToken($client)], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/ai/history?page=1', 303);
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull($client->getContainer()->get(AiRequestRepository::class)->find($ids[0]));
    }

    public function testDeleteRedirectsToTheLastPageWhenTheCurrentOneDisappears(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        // Page 2 holds the oldest row: deleting it empties the page.
        $ids = $this->insertHistory($client, AiHistoryController::PAGE_SIZE + 1);
        $oldest = end($ids);

        $client->request('POST', '/ai/history/' . $oldest . '/delete?page=2', ['_token' => $this->csrfToken($client)], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/ai/history?page=1', 303);
    }

    public function testDeleteLeavesARequestTheHistoryDoesNotShow(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $id = $this->insertRow($client, ProviderName::Mistral, 'mistral-small-latest');

        $client->request('POST', '/ai/history/' . $id . '/delete?page=1', ['_token' => $this->csrfToken($client)], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(404);
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNotNull($client->getContainer()->get(AiRequestRepository::class)->find($id));
    }

    public function testDeleteWithoutAValidCsrfTokenIsRefused(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $id = $this->insertRow($client, ProviderName::Mistral, 'mistral-small-latest', AiRequestStatus::Done, 100, 240);

        $client->request('POST', '/ai/history/' . $id . '/delete?page=1', ['_token' => 'invalid'], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(403);
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNotNull($client->getContainer()->get(AiRequestRepository::class)->find($id));
    }

    /**
     * Persists one request through its real life: created when the browser
     * asks, then either left pending, aborted from the web process, expired
     * at the worker's take, or finished by the worker with what the provider
     * reported. Null tokens when the provider reported none.
     */
    private function insertRow(
        KernelBrowser $client,
        ?ProviderName $provider,
        ?string $model,
        ?AiRequestStatus $status = null,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?string $createdAt = null,
    ): string {
        $id = Uuid::v4()->toRfc4122();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(new AiRequest($id, $createdAt === null ? null : new \DateTimeImmutable($createdAt)));
        $em->flush();

        if ($provider !== null && $model !== null) {
            $repository = $this->repository($client);
            $repository->recordRun($repository->find($id), $provider, $model);
        }

        if ($status === AiRequestStatus::Aborted) {
            $this->repository($client)->abort($id);
        } elseif ($status !== null) {
            $this->repository($client)->finish($id, $status, $promptTokens, $completionTokens);
        }

        // The lifecycle writes go through raw SQL: the managed copy the
        // identity map holds never learns them, so nothing downstream reads
        // a stale row (same as AiControllerTest::requestRow()).
        $em->clear();

        return $id;
    }

    /**
     * A request the worker never took: claimed long after it was queued, it
     * ends expired without ever reaching the API.
     */
    private function expireRow(KernelBrowser $client): void
    {
        $id = Uuid::v4()->toRfc4122();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(new AiRequest($id, new \DateTimeImmutable('-10 minutes')));
        $em->flush();

        self::assertNull($this->repository($client)->claim($id));
    }

    /**
     * Fills the history with $count finished requests, created one minute
     * apart, and returns their ids, newest first (the last one is the oldest,
     * the only row of the last page once there is one row too many).
     *
     * @return list<string>
     */
    private function insertHistory(KernelBrowser $client, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $this->insertRow(
                $client,
                ProviderName::Mistral,
                "mistral-{$i}",
                AiRequestStatus::Done,
                10 * ($i + 1),
                20 * ($i + 1),
                sprintf('-%d minutes', $i + 1),
            );
        }

        return $ids;
    }

    private function repository(KernelBrowser $client): AiRequestRepository
    {
        return $client->getContainer()->get(AiRequestRepository::class);
    }

    private function csrfToken(KernelBrowser $client): string
    {
        return $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('papermark_app')->getValue();
    }
}
