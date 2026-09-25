import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CurrentDirectoryController from '../../assets/controllers/current_directory_controller';
import EditorStateController from '../../assets/controllers/editor_state_controller';
import ExportController from '../../assets/controllers/export_controller';
import ImportController from '../../assets/controllers/import_controller';
import ModeDirController from '../../assets/controllers/mode_dir_controller';
import ModeSingleController from '../../assets/controllers/mode_single_controller';
import ModeSwitchController from '../../assets/controllers/mode_switch_controller';
import { confirmDialog } from '../../assets/utils/confirm-dialog';
import { DOCUMENT_EXTENSIONS } from '../../assets/utils/extensions';
import { type EditorState, emit } from '../../assets/editor/events';
import { INITIAL, attr, masterHtml, sidebarHtml } from './fixtures';
import { jsonResponse, mount, settle, unmount } from './stimulus';
import EXPORT_I18N from '../contract/i18n/export.json';
import IMPORT_I18N from '../contract/i18n/import.json';

vi.mock('../../assets/utils/confirm-dialog', () => ({ confirmDialog: vi.fn() }));

const HISTORY_KEY = 'editor.single.history';

/** The frame's content as templates/archive/index.html.twig renders it. */
function archiveHtml(kind: 'file' | 'directory' = 'file'): string {
    return `
    <turbo-frame id="archive">
        <section data-controller="export"
                 data-export-initial-kind-value="${kind}"
                 data-export-initial-path-value="${INITIAL.file}"
                 data-export-initial-directory-value="${INITIAL.dir}"
                 data-export-run-url-value="/archive/export"
                 data-export-i18n-value="${attr(EXPORT_I18N)}">
            <input type="radio" name="export-kind" value="file" data-export-target="kindFileRadio" data-action="change->export#changeKind">
            <input type="radio" name="export-kind" value="directory" data-export-target="kindDirectoryRadio" data-action="change->export#changeKind">
            <span class="source" data-export-target="sourcePath"></span>
            <button type="button" class="browse" data-export-target="browseButton" data-action="click->export#browse">Browse</button>
            <input type="checkbox" data-export-target="includeExternalMarkdown">
            <button type="button" data-export-target="exportButton" data-action="click->export#run">
                <span class="export-submit-spinner" data-export-target="exportSpinner" aria-hidden="true" hidden></span>
                <span data-export-target="exportLabel">Export</span>
            </button>
            <div data-export-target="report" hidden></div>
        </section>
        <section data-controller="import"
                 data-import-i18n-value="${attr(IMPORT_I18N)}">
            <span data-import-target="archivePath"></span>
            <button type="button" class="browse-archive" data-import-target="archiveBrowseButton" data-action="click->import#browseArchive">Browse</button>
            <span data-import-target="parentPath"></span>
            <button type="button" class="browse-parent" data-import-target="parentBrowseButton" data-action="click->import#browseParent">Browse</button>
            <button type="button" class="import" data-import-target="importButton" data-action="click->import#run" data-editor-leave-guard>Import</button>
            <div class="report" data-import-target="report" hidden></div>
        </section>
    </turbo-frame>`;
}

describe('the archive modal, with the master', () => {
    let application: Application;
    let fetchMock: ReturnType<typeof vi.fn>;
    let invoke: ReturnType<typeof vi.fn>;
    let reload: ReturnType<typeof vi.fn>;
    const toasts: Array<{ type: string; message: string }> = [];
    const onToast = (event: Event): number => toasts.push((event as CustomEvent).detail);

    const $ = <E extends Element = HTMLElement>(selector: string): E => document.querySelector<E>(selector)!;
    const click = (selector: string): void => {
        $(selector).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    };
    const history = (): string[] => [...document.querySelectorAll<HTMLElement>('.mode-history a')].map((a) => a.dataset.path!);
    const importRequests = (): unknown[][] => fetchMock.mock.calls.filter(([url]) => url === '/archive/import');

    async function start(mode: EditorState['mode'] = 'single', kind: 'file' | 'directory' = 'file'): Promise<void> {
        sessionStorage.setItem(HISTORY_KEY, JSON.stringify([]));
        application = await mount(masterHtml({ ...INITIAL, mode }, sidebarHtml(mode) + archiveHtml(kind)), {
            'editor-state': EditorStateController,
            'mode-switch': ModeSwitchController,
            'mode-single': ModeSingleController,
            'mode-dir': ModeDirController,
            'current-directory': CurrentDirectoryController,
            export: ExportController,
            import: ImportController,
        });
        // jsdom doesn't know <turbo-frame>.
        reload = vi.fn().mockResolvedValue(undefined);
        Object.assign($('turbo-frame#mode-dir-tree'), { reload });
    }

    /** Picks the archive and the destination the way the hub's dialogs would. */
    async function pick(archive = '/tmp/notes.zip', parent = '/dest'): Promise<void> {
        invoke.mockResolvedValueOnce(archive);
        click('.browse-archive');
        await settle();
        invoke.mockResolvedValueOnce(parent);
        click('.browse-parent');
        await settle();
    }

    function answerImport(state: Partial<EditorState>, action: { destination: string; openMode: EditorState['mode'] | null; ignoredEntries: string[] }): void {
        fetchMock.mockResolvedValue(jsonResponse({ state, action }));
    }

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        invoke = vi.fn();
        // The declared invoke is generic in its result, a mock can't be.
        window.__TAURI__ = { core: { invoke: invoke as never } };
        vi.spyOn(console, 'error').mockImplementation(() => {});
        toasts.length = 0;
        window.addEventListener('toast:show', onToast);
    });

    afterEach(async () => {
        await unmount(application);
        window.removeEventListener('toast:show', onToast);
        delete window.__TAURI__;
        sessionStorage.clear();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    describe('the import block', () => {
        it('asks the master, and shows the toast and the report when it succeeds, the modal staying as it is', async () => {
            await start();
            await pick();
            answerImport({ mode: 'single', file: '/dest/notes/doc.md' }, { destination: '/dest/notes', openMode: 'single', ignoredEntries: ['a.pdf'] });

            click('.import');
            expect($<HTMLButtonElement>('.import').disabled).toBe(true);
            await settle();

            const [[, init]] = importRequests();
            expect((init as RequestInit).headers).toEqual({ 'X-CSRF-TOKEN': 'tk-app' });
            expect(((init as RequestInit).body as FormData).get('archive')).toBe('/tmp/notes.zip');
            expect(((init as RequestInit).body as FormData).get('parentDir')).toBe('/dest');
            expect(toasts).toEqual([{ type: 'success', message: IMPORT_I18N.done.replace('{path}', '/dest/notes') }]);
            expect($('.report').hidden).toBe(false);
            expect($('.report summary').textContent).toBe(IMPORT_I18N.report.count_one.replace('{count}', '1'));
            expect($('.report li').textContent).toBe('a.pdf');
            expect($<HTMLButtonElement>('.import').disabled).toBe(false);
        });

        it('says so when nothing was ignored', async () => {
            await start();
            await pick();
            answerImport({}, { destination: '/dest/notes', openMode: null, ignoredEntries: [] });

            click('.import');
            await settle();

            expect(document.querySelector('.report details')).toBeNull();
            expect($('.report p').textContent).toBe(IMPORT_I18N.report.empty);
        });

        it('shows only the count for several ignored entries, the list behind a toggle', async () => {
            await start();
            await pick();
            answerImport({}, { destination: '/dest/notes', openMode: null, ignoredEntries: ['a.pdf', 'b.pdf', 'c.pdf'] });

            click('.import');
            await settle();

            const details = $<HTMLDetailsElement>('.report details');
            expect(details.open).toBe(false);
            expect(details.querySelector('summary')!.textContent).toBe(IMPORT_I18N.report.count_other.replace('{count}', '3'));
            expect(details.querySelectorAll('li')).toHaveLength(3);
        });

        it('clears and hides the report on the clear button', async () => {
            await start();
            await pick();
            answerImport({}, { destination: '/dest/notes', openMode: null, ignoredEntries: ['a.pdf'] });

            click('.import');
            await settle();

            click('.report .export-report-clear');

            expect($<HTMLElement>('.report').hidden).toBe(true);
            expect($('.report').children).toHaveLength(0);
        });

        it('on a failure, the master toasts, the button comes back and there is no report', async () => {
            await start();
            await pick();
            fetchMock.mockResolvedValue(jsonResponse({ state: INITIAL, genericErrors: ['Not a zip'], mappedErrors: [] }, 409));

            click('.import');
            await settle();

            expect(toasts).toEqual([{ type: 'error', message: 'Not a zip' }]);
            expect($<HTMLButtonElement>('.import').disabled).toBe(false);
            expect($('.report').hidden).toBe(true);
        });

        it('asks nothing without an archive and a folder', async () => {
            await start();

            click('.import');
            await settle();

            expect(importRequests()).toHaveLength(0);
            expect(toasts).toEqual([{ type: 'error', message: IMPORT_I18N.noSource }]);
        });

        it('pre-fills the folder with the archive\'s own, without overwriting one already picked', async () => {
            await start();

            invoke.mockResolvedValueOnce('/tmp/sub/notes.zip');
            click('.browse-archive');
            await settle();
            expect($('[data-import-target="parentPath"]').textContent).toBe('/tmp/sub');

            invoke.mockResolvedValueOnce('/other/b.zip');
            click('.browse-archive');
            await settle();
            expect($('[data-import-target="parentPath"]').textContent).toBe('/tmp/sub');
        });

        it('asks nothing when the leave guard stops the click', async () => {
            await start();
            await pick();
            // What the editor's guard does before the button's own handler.
            window.addEventListener('click', (event) => event.stopImmediatePropagation(), { capture: true, once: true });

            click('.import');
            await settle();

            expect(importRequests()).toHaveLength(0);
            expect($<HTMLButtonElement>('.import').disabled).toBe(false);
        });

        it('ignores the imports it did not ask for', async () => {
            await start();

            emit('editor:do-import-succeeded', {
                state: INITIAL,
                action: { destination: '/x', openMode: null, ignoredEntries: [] },
            });

            expect(toasts).toEqual([]);
            expect($('.report').hidden).toBe(true);
        });
    });

    describe('what follows an import', () => {
        it('a folder, from single mode: the dir column shows, the tree reloads, the folder is named', async () => {
            await start('single');
            await pick();
            answerImport({ mode: 'dir', file: null, dir: '/dest/project' }, { destination: '/dest/project', openMode: 'dir', ignoredEntries: [] });

            click('.import');
            await settle();

            expect($('[data-controller="mode-single"]').hidden).toBe(true);
            expect($('[data-controller="mode-dir"]').hidden).toBe(false);
            expect($('[data-mode="dir"]').classList.contains('is-active')).toBe(true);
            expect(reload).toHaveBeenCalledTimes(1);
            expect($('[data-current-directory-target="path"]').textContent).toBe('/dest/project');
        });

        it('a file, from dir mode: the single column shows, and the file enters the history', async () => {
            await start('dir');
            await pick();
            answerImport({ mode: 'single', file: '/dest/notes/doc.md' }, { destination: '/dest/notes', openMode: 'single', ignoredEntries: [] });

            click('.import');
            await settle();

            expect($('[data-controller="mode-single"]').hidden).toBe(false);
            expect($('[data-controller="mode-dir"]').hidden).toBe(true);
            expect($('[data-mode="single"]').classList.contains('is-active')).toBe(true);
            expect(history()).toEqual(['/dest/notes/doc.md']);
            expect(reload).not.toHaveBeenCalled();
        });

        it('a file, from single mode: no switch, no tree reload', async () => {
            await start('single');
            await pick();
            answerImport({ mode: 'single', file: '/dest/notes/doc.md' }, { destination: '/dest/notes', openMode: 'single', ignoredEntries: [] });

            click('.import');
            await settle();

            expect($('[data-controller="mode-single"]').hidden).toBe(false);
            expect(history()).toEqual(['/dest/notes/doc.md']);
            expect(reload).not.toHaveBeenCalled();
        });

        it('nothing to open: nothing visible changes', async () => {
            await start('single');
            await pick();
            answerImport({}, { destination: '/dest/pdfs', openMode: null, ignoredEntries: ['a.pdf'] });

            click('.import');
            await settle();

            expect($('[data-controller="mode-single"]').hidden).toBe(false);
            expect($('[data-controller="mode-dir"]').hidden).toBe(true);
            expect(history()).toEqual([]);
            expect(reload).not.toHaveBeenCalled();
        });

        it('a failure changes nothing on screen', async () => {
            await start('single');
            await pick();
            fetchMock.mockResolvedValue(jsonResponse({ state: INITIAL, genericErrors: ['Not a zip'], mappedErrors: [] }, 409));

            click('.import');
            await settle();

            expect($('[data-controller="mode-single"]').hidden).toBe(false);
            expect(history()).toEqual([]);
            expect(reload).not.toHaveBeenCalled();
        });
    });

    describe('the export report', () => {
        const issue = (n: number): object => ({ originalTarget: `/very/long/path/${n}.png`, reason: 'not_found', referencingPath: `/notes/${n}.md` });

        async function exportWith(issues: object[]): Promise<void> {
            await start();
            invoke.mockResolvedValueOnce('/out/archive.zip');
            fetchMock.mockResolvedValue(jsonResponse({ path: '/out/archive.zip', issues }));
            click('[data-export-target="exportButton"]');
            await settle();
        }

        it('shows only the count, the list being behind a toggle', async () => {
            await exportWith([issue(1), issue(2), issue(3)]);

            // SET-09, lot 09: the save dialog's zip filter is translated.
            expect(invoke.mock.calls[0][1].filters).toEqual([{ name: 'Zip archive', extensions: ['zip'] }]);

            const details = $<HTMLDetailsElement>('[data-export-target="report"] details');
            expect(details.open).toBe(false);
            expect(details.querySelector('summary')!.textContent).toBe(EXPORT_I18N.report.count_other.replace('{count}', '3'));
            expect(details.querySelectorAll('li')).toHaveLength(3);
        });

        it('says the count in the singular for one', async () => {
            await exportWith([issue(1)]);

            expect($('[data-export-target="report"] summary').textContent).toBe(EXPORT_I18N.report.count_one.replace('{count}', '1'));
        });

        it('has no toggle when everything was embedded', async () => {
            await exportWith([]);

            expect(document.querySelector('[data-export-target="report"] details')).toBeNull();
            expect($('[data-export-target="report"] p').textContent).toBe(EXPORT_I18N.report.empty);
        });

        it('clears and hides the report on the clear button, leaving the import block alone', async () => {
            await exportWith([issue(1)]);

            click('[data-export-target="report"] .export-report-clear');

            expect($<HTMLElement>('[data-export-target="report"]').hidden).toBe(true);
            expect($('[data-export-target="report"]').children).toHaveLength(0);
        });
    });

    describe('the export unsaved-source guard (ARC-09)', () => {
        /** Answers editor:unsaved-file-query as if `openFile` were dirty, everything else clean. */
        function answerUnsaved(openFile: string): () => void {
            const handler = (event: Event): void => {
                const query = (event as CustomEvent<{ path: string; unsaved: boolean }>).detail;
                query.unsaved = query.path === openFile;
            };
            window.addEventListener('editor:unsaved-file-query', handler);

            return () => window.removeEventListener('editor:unsaved-file-query', handler);
        }

        async function attemptExport(): Promise<void> {
            invoke.mockResolvedValueOnce('/out/archive.zip');
            fetchMock.mockResolvedValue(jsonResponse({ path: '/out/archive.zip', issues: [] }));
            click('[data-export-target="exportButton"]');
            await settle();
        }

        it('asks before exporting the file open with unsaved changes as its own source', async () => {
            await start('single', 'file'); // source and open file both start as '/notes/a.md'
            const stop = answerUnsaved('/notes/a.md');
            vi.mocked(confirmDialog).mockResolvedValue(true);

            await attemptExport();
            stop();

            expect(vi.mocked(confirmDialog)).toHaveBeenCalledTimes(1);
            expect(vi.mocked(confirmDialog).mock.calls[0][0].question).toBe(EXPORT_I18N.unsaved.confirm);
            expect(fetchMock).toHaveBeenCalled();
        });

        it('sends no request when the dialog is cancelled', async () => {
            await start('single', 'file');
            const stop = answerUnsaved('/notes/a.md');
            vi.mocked(confirmDialog).mockResolvedValue(false);

            await attemptExport();
            stop();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('asks nothing when the editor is clean', async () => {
            await start('single', 'file');
            const stop = answerUnsaved('/some/other/file.md');

            await attemptExport();
            stop();

            expect(confirmDialog).not.toHaveBeenCalled();
            expect(fetchMock).toHaveBeenCalled();
        });

        it('asks before exporting a folder that contains the file open with unsaved changes', async () => {
            await start('dir', 'directory'); // source '/notes', open file '/notes/a.md' sits inside it
            const stop = answerUnsaved('/notes/a.md');
            vi.mocked(confirmDialog).mockResolvedValue(true);

            await attemptExport();
            stop();

            expect(confirmDialog).toHaveBeenCalledTimes(1);
        });

        it('asks nothing when the open file sits outside the exported folder', async () => {
            await start('dir', 'directory');
            emit('editor:nav-change_dir-succeeded', { state: { ...INITIAL, mode: 'dir', file: '/notes/a.md', dir: '/other' }, action: { path: '/other' } });
            const stop = answerUnsaved('/notes/a.md');

            await attemptExport();
            stop();

            expect(confirmDialog).not.toHaveBeenCalled();
            expect(fetchMock).toHaveBeenCalled();
        });
    });

    describe('the export source, following the state', () => {
        const source = (): string => $('.source').textContent!;
        const pickKind = (value: 'file' | 'directory'): void => {
            const radio = document.querySelector<HTMLInputElement>(`input[name="export-kind"][value="${value}"]`)!;
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        };
        const kind = (): string => document.querySelector<HTMLInputElement>('input[name="export-kind"]:checked')!.value;

        it('starts from what the frame was rendered with', async () => {
            await start('single', 'file');

            expect(kind()).toBe('file');
            expect(source()).toBe('/notes/a.md');
        });

        it('follows a switch of mode, and the radio keeps its other path', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'dir', file: null, dir: '/notes' }, action: { mode: 'dir' } }));
            await start('single', 'file');

            click('[data-mode="dir"]');
            await settle();

            expect(kind()).toBe('directory');
            expect(source()).toBe('/notes');
        });

        it('follows a change of file', async () => {
            await start('single', 'file');

            emit('editor:nav-change_file-succeeded', { state: { ...INITIAL, file: '/notes/other.md' }, action: { path: '/notes/other.md' } });

            expect(source()).toBe('/notes/other.md');
        });

        it('follows a change of folder', async () => {
            await start('dir', 'directory');

            emit('editor:nav-change_dir-succeeded', { state: { ...INITIAL, mode: 'dir', file: null, dir: '/elsewhere' }, action: { path: '/elsewhere' } });

            expect(kind()).toBe('directory');
            expect(source()).toBe('/elsewhere');
        });

        it('follows an import, over a kind chosen by hand', async () => {
            await start('single', 'file');
            pickKind('directory');

            emit('editor:do-import-succeeded', {
                state: { ...INITIAL, mode: 'single', file: '/dest/notes/doc.md' },
                action: { destination: '/dest/notes', openMode: 'single', ignoredEntries: [] },
            });

            expect(kind()).toBe('file');
            expect(source()).toBe('/dest/notes/doc.md');
        });

        it('keeps its choice when a failure comes', async () => {
            await start('single', 'file');
            pickKind('directory');

            emit('editor:nav-change_file-failed', { state: INITIAL, action: { path: '/x.md' } });

            expect(kind()).toBe('directory');
        });
    });

    describe('the empty and loading states (UX-13, lot 10)', () => {
        const source = (): string => $('.source').textContent!;

        it('the export source carries its path in a title, and says what is missing once there is none', async () => {
            await start('single', 'file');

            expect(source()).toBe('/notes/a.md');
            expect($('.source').title).toBe('/notes/a.md');

            emit('editor:nav-new_file-succeeded', { state: { ...INITIAL, file: null }, action: {} });

            expect(source()).toBe(EXPORT_I18N.noSourceSelected);
            expect($('.source').getAttribute('title')).toBeNull();
        });

        it('the import fields each say what is missing until picked, then carry their path in a title', async () => {
            await start();

            expect($('[data-import-target="archivePath"]').textContent).toBe(IMPORT_I18N.noArchiveSelected);
            expect($('[data-import-target="parentPath"]').textContent).toBe(IMPORT_I18N.noParentSelected);

            invoke.mockResolvedValueOnce('/tmp/sub/notes.zip');
            click('.browse-archive');
            await settle();

            expect($('[data-import-target="archivePath"]').textContent).toBe('/tmp/sub/notes.zip');
            expect($('[data-import-target="archivePath"]').title).toBe('/tmp/sub/notes.zip');
            // The folder was pre-filled with the archive's own, not left empty.
            expect($('[data-import-target="parentPath"]').textContent).toBe('/tmp/sub');
            expect($('[data-import-target="parentPath"]').title).toBe('/tmp/sub');
        });

        it('the export button trades its label for a spinner while the archive is written, and comes back', async () => {
            let answer!: (response: Response) => void;
            invoke.mockResolvedValueOnce('/dest/notes.zip');
            await start('single', 'file');
            fetchMock.mockImplementation(() => new Promise((resolve) => (answer = resolve)));

            click('[data-export-target="exportButton"]');
            await settle();

            expect($('[data-export-target="exportSpinner"]').hidden).toBe(false);
            expect($('[data-export-target="exportLabel"]').hidden).toBe(true);

            answer(jsonResponse({ path: '/dest/notes.zip', issues: [] }));
            await settle();

            expect($('[data-export-target="exportSpinner"]').hidden).toBe(true);
            expect($('[data-export-target="exportLabel"]').hidden).toBe(false);
        });
    });

    describe('the pickers (HUB-06, lot 08)', () => {
        it('Browse says the hub is required without IPC, and the button comes back', async () => {
            delete window.__TAURI__;
            await start();

            click('[data-export-target="browseButton"]');
            await settle();

            expect(fetchMock.mock.calls.filter(([url]) => url === '/archive/export')).toHaveLength(0);
            expect(toasts).toEqual([{ type: 'error', message: EXPORT_I18N.ipc.unavailable }]);
            expect($<HTMLButtonElement>('[data-export-target="browseButton"]').disabled).toBe(false);
            expect($('[data-export-target="sourcePath"]').textContent).toBe(INITIAL.file);
        });

        it('a rejected invoke toasts the picker failure, and the button comes back', async () => {
            await start();

            invoke.mockRejectedValue(new Error('no window'));
            click('[data-export-target="browseButton"]');
            await settle();

            expect(toasts).toEqual([{ type: 'error', message: EXPORT_I18N.ipc.rejected }]);
            expect($<HTMLButtonElement>('[data-export-target="browseButton"]').disabled).toBe(false);

            toasts.length = 0;
            invoke.mockResolvedValue(null);
            click('[data-export-target="browseButton"]');
            await settle();

            expect(toasts).toEqual([]);
        });

        it('the import pickers say the hub is required too, each with its button back', async () => {
            delete window.__TAURI__;
            await start();

            click('.browse-archive');
            await settle();
            expect(toasts).toEqual([{ type: 'error', message: IMPORT_I18N.ipc.unavailable }]);
            expect($<HTMLButtonElement>('[data-import-target="archiveBrowseButton"]').disabled).toBe(false);

            toasts.length = 0;
            click('.browse-parent');
            await settle();
            expect(toasts).toEqual([{ type: 'error', message: IMPORT_I18N.ipc.unavailable }]);
            expect($<HTMLButtonElement>('[data-import-target="parentBrowseButton"]').disabled).toBe(false);
        });

        it('an export whose save dialog could not open saves nothing, and the button comes back', async () => {
            delete window.__TAURI__;
            await start();

            click('[data-export-target="exportButton"]');
            await settle();

            expect(fetchMock.mock.calls.filter(([url]) => url === '/archive/export')).toHaveLength(0);
            expect(toasts).toEqual([{ type: 'error', message: EXPORT_I18N.ipc.unavailable }]);
            expect($<HTMLButtonElement>('[data-export-target="exportButton"]').disabled).toBe(false);
        });

        it('a file source is browsed under a documents filter', async () => {
            invoke.mockResolvedValue('/notes/a.md');
            await start('single', 'file');

            click('[data-export-target="browseButton"]');
            await settle();

            expect(invoke).toHaveBeenCalledWith('pick_path', {
                kind: 'file',
                filters: [{ name: EXPORT_I18N.documentFilter, extensions: DOCUMENT_EXTENSIONS }],
            });
        });

        it('a folder source is browsed with no filter at all', async () => {
            invoke.mockResolvedValue('/notes');
            await start('dir', 'directory');

            click('[data-export-target="browseButton"]');
            await settle();

            expect(invoke).toHaveBeenCalledWith('pick_path', { kind: 'directory' });
        });

        it('the archive is picked under a zip filter, the destination folder without any', async () => {
            invoke.mockResolvedValue('/tmp/notes.zip');
            await start();

            click('.browse-archive');
            await settle();
            expect(invoke).toHaveBeenCalledWith('pick_path', {
                kind: 'file',
                filters: [{ name: IMPORT_I18N.zipFilter, extensions: ['zip'] }],
            });

            invoke.mockClear();
            invoke.mockResolvedValue('/dest');
            click('.browse-parent');
            await settle();
            expect(invoke).toHaveBeenCalledWith('pick_path', { kind: 'directory' });
        });
    });
});
