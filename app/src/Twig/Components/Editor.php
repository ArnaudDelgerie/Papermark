<?php

namespace App\Twig\Components;

use App\Service\Ai\AiTopicResolver;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\ThemeMode;
use App\Interface\SettingStoreInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
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
        private readonly SettingStoreInterface $settings,
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
            'conflict' => [
                'question' => $this->trans('conflict.question'),
                'cancel' => $this->trans('conflict.cancel'),
                'saveAs' => $this->trans('conflict.save_as'),
                'overwrite' => $this->trans('conflict.overwrite'),
            ],
            'loadError' => $this->trans('load_error'),
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
                'currentFileDeleted' => $this->trans('toast.current_file_deleted'),
                'currentFileGone' => $this->trans('toast.current_file_gone'),
                'fileNotFound' => $this->trans('toast.file_not_found'),
                'draftRestored' => $this->trans('toast.draft_restored'),
            ],
            'draftConflict' => [
                'question' => $this->trans('draft_conflict.question'),
                'keepDraft' => $this->trans('draft_conflict.keep_draft'),
                'useDisk' => $this->trans('draft_conflict.use_disk'),
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
     * values and labels come from the enums, so a new theme or language
     * shows up without any JS.
     *
     * @return array{theme: string, themes: list<string>, themeLabels: array<string, string>, locale: string, locales: list<string>, localeLabels: array<string, string>}
     */
    #[ExposeInTemplate(name: 'appearance')]
    public function getAppearance(): array
    {
        return [
            'theme' => $this->settings->getThemeMode()->value,
            'themes' => ThemeMode::values(),
            'themeLabels' => ThemeMode::labels($this->translator),
            'locale' => $this->settings->getLocale()->value,
            'locales' => AppLocale::values(),
            'localeLabels' => AppLocale::labels($this->translator),
        ];
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
