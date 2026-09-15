<?php

namespace App\Twig\Components;

use App\Ai\AiTopicResolver;
use App\Ai\ApiKeyResolver;
use App\Repository\ProviderRepository;
use ArnaudDelgerie\TFSAppBundle\StationContext\StationContextInterface;
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
        private readonly StationContextInterface $stationContext,
        private readonly ApiKeyResolver $apiKeyResolver,
        private readonly ProviderRepository $providers,
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
            'a4' => $this->trans('a4'),
            'full_width' => $this->trans('full_width'),
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
            'toast' => [
                'saved' => $this->trans('toast.saved'),
                'savedAs' => $this->trans('toast.saved_as'),
                'opened' => $this->trans('toast.opened'),
                'copiedMarkdown' => $this->trans('toast.copied_markdown'),
                'copyMarkdownFailed' => $this->trans('toast.copy_markdown_failed'),
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

    #[ExposeInTemplate(name: 'upload_csrf_token')]
    public function getUploadCsrfToken(): string
    {
        return $this->csrfTokenManager->getToken('upload')->getValue();
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
     * AI is offered only with a worker, a selected provider, and a key for it.
     * The subscriber cookie is minted on demand by /ai/subscribe, not at render.
     *
     * @return array{enabled: bool, mercureUrl?: string, topic?: string}
     */
    #[ExposeInTemplate(name: 'ai_config')]
    public function getAiConfig(): array
    {
        $provider = $this->providers->findSelected();
        $request = $this->requestStack->getMainRequest();

        $enabled = $request !== null
            && $this->stationContext->isAsyncWorker()
            && $provider !== null
            && $this->apiKeyResolver->resolve($provider->getName()) !== null;

        if (!$enabled) {
            return ['enabled' => false];
        }

        $topic = $this->topicResolver->resolve($request);

        return [
            'enabled' => true,
            'mercureUrl' => $this->hub->getPublicUrl(),
            'topic' => $topic,
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
