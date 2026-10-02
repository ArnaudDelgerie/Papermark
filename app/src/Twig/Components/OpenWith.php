<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Left column, lot 4b (« Ouvrir avec »): the box that proposes the paths the
 * hub delivered (tfsapp-hub open, the desktop's Open with). Hidden until the
 * open-with Stimulus controller has a request to show; nothing opens without
 * a click. Only the texts the controller renders dynamically travel as i18n
 * — the two buttons are translated in the template.
 */
#[AsTwigComponent('open-with')]
final class OpenWith
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.open_with.';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    #[ExposeInTemplate(name: 'i18n')]
    public function getI18n(): array
    {
        return [
            'question' => $this->trans('question'),
            // The count is only known front side, so the controller picks the
            // form, as the import and export reports already do.
            'ignored' => [
                'count_one' => $this->trans('ignored.count_one'),
                'count_other' => $this->trans('ignored.count_other'),
            ],
        ];
    }

    private function trans(string $key): string
    {
        return $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN);
    }
}
