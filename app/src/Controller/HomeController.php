<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * The editor page resolves the mode itself, from the session or the
     * default setting, so there is nothing left to decide here.
     */
    #[Route('/', name: 'app_home')]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute('app_editor');
    }

    /**
     * The app icon, committed at the project root for the hub's
     * `icon_path` (UX-08, lot 10) and served here so the page's favicon is
     * the same image, not a second copy under public/.
     */
    #[Route('/icon.png', name: 'app_icon')]
    public function icon(): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->kernel->getProjectDir() . '/icon.png');
        $response->headers->add(['Cache-Control' => 'public, max-age=31536000, immutable']);

        return $response;
    }
}
