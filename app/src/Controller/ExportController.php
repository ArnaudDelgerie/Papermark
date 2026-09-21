<?php

declare(strict_types=1);

namespace App\Controller;

use App\Editor\EditorState;
use App\Enum\Setting\EditorMode;
use App\Export\ArchiveExportPlanner;
use App\Export\ArchiveExportRefusedException;
use App\Export\ArchiveTargetResolver;
use App\Export\ArchiveWriter;
use App\Export\ExportIssue;
use App\Import\ArchiveImporter;
use App\Import\ArchiveImportRefusedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExportController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';
    private const TRANSLATION_PREFIX = 'components.editor.error.';

    public function __construct(
        private readonly EditorState $editorState,
        private readonly ArchiveExportPlanner $planner,
        private readonly ArchiveTargetResolver $targetResolver,
        private readonly ArchiveWriter $writer,
        private readonly ArchiveImporter $importer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The content of the Archive modal's frame, and nothing else: it carries
     * no `src`, or Turbo would see a frame that references itself.
     */
    #[Route('/archive', name: 'app_archive', methods: ['GET'])]
    public function index(): Response
    {
        // The source preselected when the frame loads: the current file in
        // single mode, the current folder in dir mode. The export controller
        // then follows the state on its own (see EDITOR_ARCHIVE.md).
        return $this->render('archive/index.html.twig', [
            'initial_kind' => $this->editorState->getMode() === EditorMode::Dir ? 'directory' : 'file',
            'initial_path' => $this->editorState->getFile(),
            'initial_directory' => $this->editorState->getDir(),
            'export_i18n' => $this->exportI18n(),
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
                'count_one' => $trans('report.count_one'),
                'count_other' => $trans('report.count_other'),
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
            'report' => [
                'title' => $trans('report.title'),
                'empty' => $trans('report.empty'),
            ],
        ];
    }

    #[Route('/export/run', name: 'app_export_run', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function run(Request $request): JsonResponse
    {
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
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function importRun(Request $request): JsonResponse
    {
        $archive = $request->request->get('archive');
        if (!\is_string($archive) || '' === $archive) {
            return $this->stateErrorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        $parentDir = $request->request->get('parentDir');
        if (!\is_string($parentDir) || '' === $parentDir) {
            return $this->stateErrorResponse('no_path', Response::HTTP_BAD_REQUEST);
        }

        $realArchive = realpath($archive);
        if (false === $realArchive || !is_file($realArchive)) {
            return $this->stateErrorResponse('not_found', Response::HTTP_NOT_FOUND);
        }

        $realParentDir = realpath($parentDir);
        if (false === $realParentDir || !is_dir($realParentDir) || !is_writable($realParentDir)) {
            return $this->stateErrorResponse('not_writable', Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $this->importer->import($realArchive, $realParentDir);
        } catch (ArchiveImportRefusedException $e) {
            return $this->stateErrorResponse($e->reason->value, Response::HTTP_CONFLICT);
        }

        // The state now points at what the archive gave to open; the master
        // broadcasts it, and each column follows.
        if (EditorMode::Single === $result->openMode) {
            $this->editorState->setMode(EditorMode::Single);
            $this->editorState->setFile($result->openPath);
        } elseif (EditorMode::Dir === $result->openMode) {
            $this->editorState->setMode(EditorMode::Dir);
            $this->editorState->setDir($result->destination);
        }

        return new JsonResponse([
            'state' => $this->editorState->toArray(),
            'action' => [
                'destination' => $result->destination,
                'openMode' => $result->openMode?->value,
                'ignoredEntries' => $result->ignoredEntries,
            ],
        ]);
    }

    private function stateErrorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN),
            'state' => $this->editorState->toArray(),
        ], $status);
    }

    private function errorResponse(string $key, int $status): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->translator->trans(self::TRANSLATION_PREFIX . $key, [], self::TRANSLATION_DOMAIN)],
            $status,
        );
    }
}
