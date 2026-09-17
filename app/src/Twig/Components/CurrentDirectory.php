<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\File\OpenDirectory as OpenDirectorySession;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Open folder, and the folder currently held in session. Its own component
 * because it owns that piece of state: it calls /dir/current and announces the
 * change, while mode-dir only listens (see EDITOR_REACTIVITY.md).
 */
#[AsTwigComponent('current-directory')]
final class CurrentDirectory
{
    private const TRANSLATION_DOMAIN = 'components';

    public function __construct(
        private readonly OpenDirectorySession $openDirectory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[ExposeInTemplate(name: 'open_directory')]
    public function getOpenDirectory(): ?string
    {
        return $this->openDirectory->get();
    }

    #[ExposeInTemplate(name: 'csrf_token')]
    public function getCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken('dir')->getValue();
    }

    /**
     * @return array<string, string>
     */
    #[ExposeInTemplate(name: 'i18n')]
    public function getI18n(): array
    {
        return [
            'failed' => $this->translator->trans('components.mode.open_directory_failed', [], self::TRANSLATION_DOMAIN),
        ];
    }
}
