<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Left column, single-file mode: mode selector, Open (file) and the history
 * of opened files. History lives in sessionStorage, entirely managed by the
 * mode-single Stimulus controller. Delete/rename (EDITOR_FIX.md #5) reuse the
 * 'file' CSRF token already used by FileController's other fetch actions.
 */
#[AsTwigComponent('mode-single')]
final class ModeSingle
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.mode.file.';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[ExposeInTemplate(name: 'file_csrf_token')]
    public function getFileCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken('file')->getValue();
    }

    /**
     * @return array<string, string>
     */
    #[ExposeInTemplate(name: 'i18n')]
    public function getI18n(): array
    {
        return [
            'rename' => $this->trans('rename'),
            'delete' => $this->trans('delete'),
            'renamePrompt' => $this->trans('rename_prompt'),
            'deleteConfirmMessage' => $this->trans('delete_confirm_message'),
            'deleteConfirmQuestion' => $this->trans('delete_confirm_question'),
            'deleted' => $this->trans('deleted'),
            'renamed' => $this->trans('renamed'),
            'renameFailed' => $this->trans('rename_failed'),
            'deleteFailed' => $this->trans('delete_failed'),
        ];
    }

    private function trans(string $key): string
    {
        return $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN);
    }
}
