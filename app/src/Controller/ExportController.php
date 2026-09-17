<?php

declare(strict_types=1);

namespace App\Controller;

use App\Editor\EditorMode;
use App\Editor\ModeSession;
use App\Export\ArchiveExportPlanner;
use App\Export\ArchiveExportRefusedException;
use App\Export\ArchiveTargetResolver;
use App\Export\ArchiveWriter;
use App\Export\ExportIssue;
use App\File\OpenDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExportController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly ModeSession $modeSession,
        private readonly OpenDirectory $openDirectory,
        private readonly ArchiveExportPlanner $planner,
        private readonly ArchiveTargetResolver $targetResolver,
        private readonly ArchiveWriter $writer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/export', name: 'app_export', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('export/index.html.twig', [
            'initial_path' => $this->modeSession->getFile(EditorMode::Single),
            'initial_directory' => $this->openDirectory->get(),
            'csrf_token' => $this->csrfTokenManager->getToken('export')->getValue(),
            'i18n' => $this->i18n(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function i18n(): array
    {
        $trans = fn (string $key): string => $this->translator->trans('components.export.' . $key, [], self::TRANSLATION_DOMAIN);

        return [
            'sourceTitle' => $trans('source_title'),
            'sourceFile' => $trans('source_file'),
            'sourceFolder' => $trans('source_folder'),
            'browse' => $trans('browse'),
            'noSourceSelected' => $trans('no_source_selected'),
            'includeExternalMarkdown' => $trans('include_external_markdown'),
            'exportButton' => $trans('export_button'),
            'noSource' => $trans('no_source'),
            'done' => $trans('done'),
            'failed' => $trans('failed'),
            'report' => [
                'title' => $trans('report.title'),
                'empty' => $trans('report.empty'),
                'reason' => [
                    'not_found' => $trans('report.reason.not_found'),
                    'limit_exceeded' => $trans('report.reason.limit_exceeded'),
                ],
            ],
        ];
    }

    #[Route('/export/run', name: 'app_export_run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('export', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $source = $request->request->get('source');
        if (!\is_string($source) || '' === $source) {
            return $this->errorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        $target = $request->request->get('target');
        if (!\is_string($target) || '' === $target) {
            return $this->errorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        $realSource = realpath($source);
        if (false === $realSource || (!is_file($realSource) && !is_dir($realSource))) {
            return $this->errorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        $resolvedTarget = $this->targetResolver->resolve($target);

        $realTargetParent = realpath(\dirname($resolvedTarget));
        if (false === $realTargetParent || !is_dir($realTargetParent) || !is_writable($realTargetParent)) {
            return $this->errorResponse('not_writable', Response::HTTP_FORBIDDEN);
        }

        $includeExternalMarkdown = $request->request->getBoolean('includeExternalMarkdown');

        try {
            $plan = $this->planner->plan($realSource, $includeExternalMarkdown);
        } catch (ArchiveExportRefusedException $e) {
            return $this->errorResponse($e->reservedName . '_conflict', Response::HTTP_CONFLICT);
        }

        try {
            $this->writer->write($plan, $resolvedTarget);
        } catch (\RuntimeException) {
            return $this->errorResponse('write_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'path' => $resolvedTarget,
            'issues' => array_map(
                static fn (ExportIssue $issue): array => [
                    'referencingPath' => $issue->referencingPath,
                    'originalTarget' => $issue->originalTarget,
                    'reason' => $issue->reason->value,
                ],
                $plan->issues,
            ),
        ]);
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
