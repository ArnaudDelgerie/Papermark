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
use App\Import\ArchiveImporter;
use App\Import\ArchiveImportRefusedException;
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
        private readonly ArchiveImporter $importer,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/archive', name: 'app_archive', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('archive/index.html.twig', [
            'initial_path' => $this->modeSession->getFile(EditorMode::Single),
            'initial_directory' => $this->openDirectory->get(),
            'export_csrf_token' => $this->csrfTokenManager->getToken('export')->getValue(),
            'export_i18n' => $this->exportI18n(),
            'import_csrf_token' => $this->csrfTokenManager->getToken('import')->getValue(),
            'import_i18n' => $this->importI18n(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function exportI18n(): array
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

    /**
     * @return array<string, mixed>
     */
    private function importI18n(): array
    {
        $trans = fn (string $key): string => $this->translator->trans('components.import.' . $key, [], self::TRANSLATION_DOMAIN);

        return [
            'browse' => $trans('browse'),
            'importButton' => $trans('import_button'),
            'noSource' => $trans('no_source'),
            'done' => $trans('done'),
            'failed' => $trans('failed'),
            'report' => [
                'title' => $trans('report.title'),
                'empty' => $trans('report.empty'),
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

    #[Route('/import/run', name: 'app_import_run', methods: ['POST'])]
    public function importRun(Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!\is_string($csrfToken) || !$this->csrfTokenManager->isTokenValid(new CsrfToken('import', $csrfToken))) {
            return $this->errorResponse('invalid_csrf', Response::HTTP_FORBIDDEN);
        }

        $archive = $request->request->get('archive');
        if (!\is_string($archive) || '' === $archive) {
            return $this->errorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        $parentDir = $request->request->get('parentDir');
        if (!\is_string($parentDir) || '' === $parentDir) {
            return $this->errorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        $realArchive = realpath($archive);
        if (false === $realArchive || !is_file($realArchive)) {
            return $this->errorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        $realParentDir = realpath($parentDir);
        if (false === $realParentDir || !is_dir($realParentDir) || !is_writable($realParentDir)) {
            return $this->errorResponse('not_writable', Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $this->importer->import($realArchive, $realParentDir);
        } catch (ArchiveImportRefusedException $e) {
            return $this->errorResponse($e->reason->value, Response::HTTP_CONFLICT);
        }

        // Only the session is updated here — app_home is what actually
        // redirects to the right editor route, same as everywhere else in
        // the app that switches mode (see HomeController).
        if (EditorMode::Single === $result->openMode) {
            $this->modeSession->setCurrentMode(EditorMode::Single);
            $this->modeSession->setFile(EditorMode::Single, $result->openPath);
        } elseif (EditorMode::Dir === $result->openMode) {
            $this->modeSession->setCurrentMode(EditorMode::Dir);
            $this->openDirectory->set($result->destination);
        }

        return new JsonResponse([
            'destination' => $result->destination,
            'openMode' => $result->openMode?->value,
            'openPath' => $result->openPath,
            'ignoredEntries' => $result->ignoredEntries,
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
