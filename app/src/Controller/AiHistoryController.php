<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AiRequest;
use App\Repository\AiRequestRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * The AI history modal's frame (EDITOR_AI_HISTORY.md): the token consumption
 * of the requests that got an answer from the API, one page at a time, and
 * the deletion of one line.
 */
final class AiHistoryController extends AbstractController
{
    /**
     * Decision 4 of EDITOR_AI_HISTORY.md: 20 rows per page. Public so the
     * tests size their fixtures after it, whatever it is.
     */
    public const PAGE_SIZE = 20;

    public function __construct(
        private readonly AiRequestRepository $requests,
    ) {
    }

    /**
     * The content of the frame, and nothing else: it carries no `src`, or
     * Turbo would see a frame that references itself. A page past the last
     * one is answered with the last one itself.
     */
    #[Route('/ai/history', name: 'app_ai_history', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$requests, $page, $lastPage] = $this->page(max(1, $request->query->getInt('page', 1)));

        return $this->render('ai_history/index.html.twig', [
            'requests' => $requests,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }

    /**
     * Removes one line, then comes back to the page it was deleted from —
     * or to the last page, when that page no longer exists.
     */
    #[Route('/ai/history/{id}/delete', name: 'app_ai_history_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: '_token')]
    public function delete(string $id, Request $request): RedirectResponse
    {
        if (!$this->requests->deleteById($id)) {
            throw $this->createNotFoundException();
        }

        $page = max(1, $request->query->getInt('page', 1));
        $lastPage = max(1, (int) ceil($this->requests->countHistory() / self::PAGE_SIZE));

        return $this->redirectToRoute('app_ai_history', ['page' => min($page, $lastPage)], 303);
    }

    /**
     * The requested page, clamped to the last one: the paginator counts the
     * whole filtered set whatever the page asked for.
     *
     * @return array{0: Paginator<AiRequest>, 1: int, 2: int}
     */
    private function page(int $page): array
    {
        $requests = $this->requests->findHistoryPage($page, self::PAGE_SIZE);
        $lastPage = max(1, (int) ceil(count($requests) / self::PAGE_SIZE));

        if ($page > $lastPage) {
            $page = $lastPage;
            $requests = $this->requests->findHistoryPage($page, self::PAGE_SIZE);
        }

        return [$requests, $page, $lastPage];
    }
}
