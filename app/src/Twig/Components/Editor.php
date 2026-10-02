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
            // SET-09, lot 09: the name a new file is proposed under, translated.
            'untitledFileName' => $this->trans('untitled_file_name'),
            // SET-09, lot 09: the native save dialog's filters, translated.
            'saveFilters' => [
                'markdown' => $this->trans('save_filter.markdown'),
                'text' => $this->trans('save_filter.text'),
            ],
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
            // FRT-11, lot 08: the editor could not be created at all.
            'initFailed' => $this->trans('init_failed'),
            // HUB-06, lot 08: the file pickers, shared with every caller.
            'ipc' => [
                'unavailable' => $this->translator->trans('components.ipc.unavailable', [], self::TRANSLATION_DOMAIN),
                'rejected' => $this->translator->trans('components.ipc.rejected', [], self::TRANSLATION_DOMAIN),
            ],
            // FRT-08, lot 08: the picked file is not an image the app can serve.
            'imageInvalid' => $this->trans('image_invalid'),
            // Lot 01 hub-integration: the image picker's filter, translated.
            'imageFilter' => $this->translator->trans('components.pick_filter.images', [], self::TRANSLATION_DOMAIN),
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
            // SET-04, lot 09: the image block's own texts (caption, upload).
            'imageBlock' => [
                'captionPlaceholder' => $this->trans('image_block.caption_placeholder'),
                'uploadButton' => $this->trans('image_block.upload_button'),
                'uploadPlaceholder' => $this->trans('image_block.upload_placeholder'),
                'confirmButton' => $this->trans('image_block.confirm_button'),
            ],
            'toast' => [
                'autosaveFailed' => $this->trans('toast.autosave_failed'),
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
                // SET-04, lot 09: the suggestions menu, translated — the
                // prompts sent to the model stay in English (crepe-host.ts).
                'suggestions' => [
                    'improve' => $this->trans('ai.suggestions.improve'),
                    'improveStreaming' => $this->trans('ai.suggestions.improve_streaming'),
                    'grammar' => $this->trans('ai.suggestions.grammar'),
                    'grammarStreaming' => $this->trans('ai.suggestions.grammar_streaming'),
                    'shorter' => $this->trans('ai.suggestions.shorter'),
                    'shorterStreaming' => $this->trans('ai.suggestions.shorter_streaming'),
                    'longer' => $this->trans('ai.suggestions.longer'),
                    'longerStreaming' => $this->trans('ai.suggestions.longer_streaming'),
                    'streamingFallback' => $this->trans('ai.suggestions.streaming_fallback'),
                    'streamingCancel' => $this->trans('ai.suggestions.streaming_cancel'),
                    'tone' => [
                        'label' => $this->trans('ai.suggestions.tone.label'),
                        'title' => $this->trans('ai.suggestions.tone.title'),
                        'search' => $this->trans('ai.suggestions.tone.search'),
                        'streaming' => $this->trans('ai.suggestions.tone.streaming'),
                        'professional' => $this->trans('ai.suggestions.tone.professional'),
                        'casual' => $this->trans('ai.suggestions.tone.casual'),
                        'confident' => $this->trans('ai.suggestions.tone.confident'),
                        'friendly' => $this->trans('ai.suggestions.tone.friendly'),
                        'direct' => $this->trans('ai.suggestions.tone.direct'),
                        'formal' => $this->trans('ai.suggestions.tone.formal'),
                    ],
                    'translate' => [
                        'label' => $this->trans('ai.suggestions.translate.label'),
                        'title' => $this->trans('ai.suggestions.translate.title'),
                        'search' => $this->trans('ai.suggestions.translate.search'),
                        'streaming' => $this->trans('ai.suggestions.translate.streaming'),
                        'english' => $this->trans('ai.suggestions.translate.english'),
                        'chinese' => $this->trans('ai.suggestions.translate.chinese'),
                        'japanese' => $this->trans('ai.suggestions.translate.japanese'),
                        'korean' => $this->trans('ai.suggestions.translate.korean'),
                        'spanish' => $this->trans('ai.suggestions.translate.spanish'),
                        'french' => $this->trans('ai.suggestions.translate.french'),
                        'german' => $this->trans('ai.suggestions.translate.german'),
                    ],
                ],
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
