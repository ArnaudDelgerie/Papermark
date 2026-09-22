<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Archive\ExportArchiveRequest;
use App\Dto\Archive\ExportIssue;
use App\Dto\Archive\ImportArchiveRequest;
use App\Response\StateSuccessResponse;
use App\Service\Archive\ArchiveExporter;
use App\Service\Archive\ArchiveImporter;
use App\Service\Editor\EditorState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Archive modal (lot 06-archive-controller.md), under `/archive`: its
 * page, and export and import, each behind a DTO and a single service call.
 */
final class ArchiveController extends AbstractController
{
    private const TRANSLATION_DOMAIN = 'components';

    public function __construct(
        private readonly EditorState $editorState,
        private readonly ArchiveExporter $exporter,
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
            'initial_kind' => $this->editorState->getMode()->exportKind(),
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

    #[Route('/archive/export', name: 'app_archive_export', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function export(#[MapRequestPayload(mapWhenEmpty: true)] ExportArchiveRequest $payload): JsonResponse
    {
        // ArchiveExportRefusedException (a reserved name conflict) is
        // UserFacing on its own and propagates as-is, same for WriteFailedException
        // thrown by the writer on a failed zip.
        $result = $this->exporter->export($payload->source, $payload->target, $payload->includeExternalMarkdown);

        return new JsonResponse([
            'path' => $result['path'],
            'issues' => array_map(
                static fn (ExportIssue $issue): array => [
                    'referencingPath' => $issue->referencingPath,
                    'originalTarget' => $issue->originalTarget,
                    'reason' => $issue->reason->value,
                ],
                $result['plan']->issues,
            ),
        ]);
    }

    #[Route('/archive/import', name: 'app_archive_import', methods: ['POST'])]
    #[IsCsrfTokenValid('papermark_app', tokenKey: 'X-CSRF-TOKEN', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function import(#[MapRequestPayload(mapWhenEmpty: true)] ImportArchiveRequest $payload): JsonResponse
    {
        // ArchiveImportRefusedException and WriteFailedException (a failed
        // extraction) are UserFacing on their own and propagate as-is. The
        // state ArchiveImported carries is set by EditorStateListener before
        // this response is built.
        $result = $this->importer->import($payload->archive, $payload->parentDir);

        return new StateSuccessResponse($this->editorState, [
            'destination' => $result->destination,
            'openMode' => $result->openMode?->value,
            'ignoredEntries' => $result->ignoredEntries,
        ]);
    }
}
