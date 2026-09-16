<?php

namespace App\Controller;

use App\Editor\EditorMode;
use App\Editor\ModeSession;
use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly ModeSession $modeSession,
    ) {
    }

    #[Route('/', name: 'app_home')]
    public function index(): RedirectResponse
    {
        $mode = $this->modeSession->getCurrentMode() ?? $this->settings->getOrCreate()->getDefaultMode();

        return $this->redirectToRoute($mode === EditorMode::Dir ? 'app_editor_dir' : 'app_editor_single');
    }
}
