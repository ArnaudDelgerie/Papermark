<?php

namespace App\Twig\Components;

use App\Ai\AiTopicResolver;
use App\Locale\AppLocale;
use App\Setting\SettingStore;
use App\Theme\ThemeMode;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

#[AsTwigComponent('editor')]
final class Editor
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.';

    public string $height = '400';
    public bool $readonly = false;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SettingStore $settings,
        private readonly RequestStack $requestStack,
        private readonly AiTopicResolver $topicResolver,
        private readonly HubInterface $hub,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[ExposeInTemplate(name: 'i18n')]
    public function getI18n(): array
    {
        return [
            'placeholder' => $this->trans('placeholder'),
            'link' => [
                'confirm' => $this->trans('link.confirm'),
                'inputPlaceholder' => $this->trans('link.input_placeholder'),
            ],
            'toggle' => [
                'edit' => $this->trans('toggle.edit'),
                'readonly' => $this->trans('toggle.readonly'),
            ],
            'untitled' => $this->trans('untitled'),
            'unsaved' => [
                'confirm' => $this->trans('unsaved.confirm'),
                'cancel' => $this->trans('unsaved.cancel'),
                'continue' => $this->trans('unsaved.continue'),
            ],
            'slashMenu' => [
                'text' => $this->trans('slash_menu.text'),
                'paragraph' => $this->trans('slash_menu.paragraph'),
                'h1' => $this->trans('slash_menu.h1'),
                'h2' => $this->trans('slash_menu.h2'),
                'h3' => $this->trans('slash_menu.h3'),
                'h4' => $this->trans('slash_menu.h4'),
                'h5' => $this->trans('slash_menu.h5'),
                'h6' => $this->trans('slash_menu.h6'),
                'quote' => $this->trans('slash_menu.quote'),
                'divider' => $this->trans('slash_menu.divider'),
                'list' => $this->trans('slash_menu.list'),
                'bulletList' => $this->trans('slash_menu.bullet_list'),
                'orderedList' => $this->trans('slash_menu.ordered_list'),
                'taskList' => $this->trans('slash_menu.task_list'),
                'advanced' => $this->trans('slash_menu.advanced'),
                'image' => $this->trans('slash_menu.image'),
                'code' => $this->trans('slash_menu.code'),
                'table' => $this->trans('slash_menu.table'),
            ],
            'codeBlock' => [
                'noLanguage' => $this->trans('code_block.no_language'),
                'copy' => $this->trans('code_block.copy'),
            ],
            'toast' => [
                'saved' => $this->trans('toast.saved'),
                'savedAs' => $this->trans('toast.saved_as'),
                'copiedMarkdown' => $this->trans('toast.copied_markdown'),
                'copyMarkdownFailed' => $this->trans('toast.copy_markdown_failed'),
                'copiedCode' => $this->trans('toast.copied_code'),
                'copyCodeFailed' => $this->trans('toast.copy_code_failed'),
            ],
            'ai' => [
                'askAi' => $this->trans('ai.ask_ai'),
                'instructionPlaceholder' => $this->trans('ai.instruction_placeholder'),
                'suggestionsHeader' => $this->trans('ai.suggestions_header'),
                'sendAsPromptHeader' => $this->trans('ai.send_as_prompt_header'),
                'sendAsPrompt' => $this->trans('ai.send_as_prompt'),
                'submitButton' => $this->trans('ai.submit_button'),
                'listbox' => $this->trans('ai.listbox'),
                'requestFailed' => $this->trans('error.ai_failed'),
            ],
        ];
    }

    #[ExposeInTemplate(name: 'file_csrf_token')]
    public function getFileCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken('file')->getValue();
    }

    #[ExposeInTemplate(name: 'ai_csrf_token')]
    public function getAiCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken('ai')->getValue();
    }

    /**
     * The hub and topic are always given: whether the AI is on comes from the
     * client state (`ai_enabled`), which can change without a reload. The
     * subscriber cookie is minted on demand by /ai/subscribe, not at render.
     *
     * @return array{mercureUrl?: string, topic?: string}
     */
    #[ExposeInTemplate(name: 'ai_config')]
    public function getAiConfig(): array
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return [];
        }

        return [
            'mercureUrl' => $this->hub->getPublicUrl(),
            'topic' => $this->topicResolver->resolve($request),
        ];
    }

    /**
     * What the theme and language buttons of the file bar start from. The
     * languages come from the enum, so a new one shows up without any JS.
     *
     * @return array{theme: string, themeLabels: array<string, string>, locale: string, locales: list<string>, localeLabels: array<string, string>}
     */
    #[ExposeInTemplate(name: 'appearance')]
    public function getAppearance(): array
    {
        return [
            'theme' => $this->settings->getThemeMode()->value,
            'themeLabels' => array_combine(
                array_map(static fn (ThemeMode $mode): string => $mode->value, ThemeMode::cases()),
                array_map(fn (ThemeMode $mode): string => $this->translator->trans('components.theme.' . $mode->value, [], self::TRANSLATION_DOMAIN), ThemeMode::cases()),
            ),
            'locale' => $this->settings->getLocale()->value,
            'locales' => array_map(static fn (AppLocale $locale): string => $locale->value, AppLocale::cases()),
            'localeLabels' => array_combine(
                array_map(static fn (AppLocale $locale): string => $locale->value, AppLocale::cases()),
                array_map(fn (AppLocale $locale): string => $this->translator->trans('components.locale.' . $locale->value, [], self::TRANSLATION_DOMAIN), AppLocale::cases()),
            ),
        ];
    }

    #[ExposeInTemplate(name: 'settings_csrf_token')]
    public function getSettingsCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken('settings')->getValue();
    }

    #[ExposeInTemplate(name: 'css_height')]
    public function getCssHeight(): string
    {
        return preg_match('/^\d+$/', $this->height) ? $this->height . 'px' : $this->height;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN);
    }
}
