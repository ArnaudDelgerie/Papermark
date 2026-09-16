<?php

declare(strict_types=1);

namespace App\Controller;

use App\File\OpenDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EditorController extends AbstractController
{
    #[Route('/editor/single', name: 'app_editor_single')]
    public function single(): Response
    {
        return $this->render('editor/single.html.twig');
    }

    #[Route('/editor/dir', name: 'app_editor_dir')]
    public function dir(OpenDirectory $openDirectory): Response
    {
        return $this->render('editor/dir.html.twig', [
            'directory' => $openDirectory->get(),
        ]);
    }
}
