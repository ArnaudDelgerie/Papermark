import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CurrentDirectoryController from '../../assets/controllers/current_directory_controller';
import EditorStateController from '../../assets/controllers/editor_state_controller';
import ModeDirController from '../../assets/controllers/mode_dir_controller';
import ModeSingleController from '../../assets/controllers/mode_single_controller';
import ModeSwitchController from '../../assets/controllers/mode_switch_controller';
import { emit } from '../../assets/editor/events';
import { confirmDialog } from '../../assets/utils/confirm-dialog';
import { renameDialog } from '../../assets/utils/rename-dialog';
import { INITIAL, masterHtml, sidebarHtml } from './fixtures';
import { jsonResponse, mount, settle, unmount } from './stimulus';

vi.mock('../../assets/utils/confirm-dialog', () => ({ confirmDialog: vi.fn() }));
vi.mock('../../assets/utils/rename-dialog', () => ({ renameDialog: vi.fn() }));

const HISTORY_KEY = 'editor.single.history';

describe('the left column, with the master', () => {
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
    const singleColumn = (): HTMLElement => $('[data-controller="mode-single"]');
    const dirColumn = (): HTMLElement => $('[data-controller="mode-dir"]');

    async function start(history: string[] = [], mode: 'single' | 'dir' = 'single'): Promise<void> {
        sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history));
        application = await mount(masterHtml({ ...INITIAL, mode }, sidebarHtml(mode)), {
            'editor-state': EditorStateController,
            'mode-switch': ModeSwitchController,
            'mode-single': ModeSingleController,
            'mode-dir': ModeDirController,
            'current-directory': CurrentDirectoryController,
        });
        // jsdom doesn't know <turbo-frame>.
        reload = vi.fn().mockResolvedValue(undefined);
        Object.assign($('turbo-frame'), { reload });
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

    describe('switch_mode', () => {
        it('shows the other column at once, and keeps it once the server agrees', async () => {
            let answer!: (response: Response) => void;
            fetchMock.mockReturnValue(new Promise((resolve) => (answer = resolve)));
            await start();

            click('[data-mode="dir"]');

            expect(singleColumn().hidden).toBe(true);
            expect(dirColumn().hidden).toBe(false);
            expect($('[data-mode="dir"]').classList.contains('is-active')).toBe(true);

            answer(jsonResponse({ state: { mode: 'dir', file: null, dir: '/notes' }, action: { mode: 'dir' } }));
            await settle();

            expect(dirColumn().hidden).toBe(false);
            expect($('[data-mode="single"]').classList.contains('is-active')).toBe(false);
        });

        it('settles back on the state when it fails, without asking again', async () => {
            fetchMock.mockRejectedValue(new TypeError('Network down'));
            await start();

            click('[data-mode="dir"]');
            await settle();

            expect(singleColumn().hidden).toBe(false);
            expect(dirColumn().hidden).toBe(true);
            expect($('[data-mode="single"]').classList.contains('is-active')).toBe(true);
            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(toasts).toEqual([{ type: 'error', message: 'Generic failure' }]);
        });

        it('does not ask for the mode already shown', async () => {
            await start();

            click('[data-mode="single"]');
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
        });
    });

    describe('change_dir', () => {
        it('keeps the old tree during the request, then empties it and reloads it with the new folder', async () => {
            invoke.mockResolvedValue('/other');
            let answer!: (response: Response) => void;
            fetchMock.mockReturnValue(new Promise((resolve) => (answer = resolve)));
            await start([], 'dir');

            click('[data-action="click->current-directory#change"]');
            await settle();

            expect(invoke).toHaveBeenCalledWith('pick_path', { kind: 'directory' });
            expect($('turbo-frame .mode-tree')).not.toBeNull();
            expect($<HTMLButtonElement>('[data-current-directory-target="openButton"]').disabled).toBe(true);

            answer(jsonResponse({ state: { mode: 'dir', file: null, dir: '/real/other' }, action: { path: '/real/other' } }));
            await settle();

            expect($('[data-current-directory-target="path"]').textContent).toBe('/real/other');
            expect($<HTMLButtonElement>('[data-current-directory-target="openButton"]').disabled).toBe(false);
            expect($('turbo-frame').childElementCount).toBe(0);
            expect(reload).toHaveBeenCalledTimes(1);
        });

        it('on failure, keeps the previous folder and its tree as they are', async () => {
            invoke.mockResolvedValue('/gone');
            fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'dir', file: null, dir: '/notes' }, error: 'Folder not found' }, 404));
            await start([], 'dir');

            click('[data-action="click->current-directory#change"]');
            await settle();

            expect($('[data-current-directory-target="path"]').textContent).toBe('/notes');
            expect($('turbo-frame .mode-tree')).not.toBeNull();
            expect(reload).not.toHaveBeenCalled();
            expect(toasts).toEqual([{ type: 'error', message: 'Folder not found' }]);
        });

        it('keeps the gone-folder message the tree rendered instead of flashing it', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'dir', file: null, dir: null } }));
            await start([], 'dir');

            // The tree found the folder gone: the server rendered its message
            // instead of the entries, and the frame just finished loading.
            const gone = document.createElement('p');
            gone.className = 'mode-tree-gone';
            gone.dataset.dirGone = '/notes';
            $('turbo-frame').replaceChildren(gone);
            $('turbo-frame').dispatchEvent(new Event('turbo:frame-load'));
            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(fetchMock.mock.calls[0][0]).toBe('/editor/state');
            expect(reload).not.toHaveBeenCalled();
            expect($('turbo-frame').querySelector('[data-dir-gone]')).toBe(gone);
            expect($('[data-mode-dir-target="toolbar"]').hidden).toBe(true);
            expect($('[data-current-directory-target="path"]').hidden).toBe(true);
        });

        it('shows the refresh button only once a folder is open', async () => {
            invoke.mockResolvedValue('/notes');
            fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'dir', file: null, dir: '/notes' }, action: { path: '/notes' } }));
            // Rendered without a folder: the server hides it.
            sessionStorage.setItem(HISTORY_KEY, '[]');
            application = await mount(masterHtml({ ...INITIAL, mode: 'dir', dir: null }, sidebarHtml('dir', null)), {
                'editor-state': EditorStateController,
                'mode-dir': ModeDirController,
                'current-directory': CurrentDirectoryController,
            });
            Object.assign($('turbo-frame'), { reload: vi.fn().mockResolvedValue(undefined) });
            expect($('[data-mode-dir-target="toolbar"]').hidden).toBe(true);

            click('[data-action="click->current-directory#change"]');
            await settle();

            expect($('[data-mode-dir-target="toolbar"]').hidden).toBe(false);
        });

        it('asks nothing when the picker is cancelled', async () => {
            invoke.mockResolvedValue(null);
            await start([], 'dir');

            click('[data-action="click->current-directory#change"]');
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
        });
    });

    describe('refresh_dir', () => {
        const refreshButton = (): HTMLButtonElement => $<HTMLButtonElement>('[data-mode-dir-target="refreshButton"]');

        it('turns the button until the tree has reloaded, without a loader in the tree', async () => {
            let answer!: (response: Response) => void;
            fetchMock.mockReturnValue(new Promise((resolve) => (answer = resolve)));
            await start([], 'dir');
            let reloaded!: () => void;
            reload.mockReturnValue(new Promise<void>((resolve) => (reloaded = resolve)));

            click('[data-action="click->mode-dir#refresh"]');
            await settle();

            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe('/editor/dir/refresh');
            expect(init.headers).toEqual({ 'X-CSRF-TOKEN': 'tk-dir' });
            expect(refreshButton().disabled).toBe(true);
            expect(refreshButton().classList.contains('is-refreshing')).toBe(true);

            answer(jsonResponse({ state: { mode: 'dir', file: null, dir: '/notes' }, action: {} }));
            await settle();

            expect(reload).toHaveBeenCalledTimes(1);
            expect(refreshButton().disabled).toBe(true);

            reloaded();
            await settle();

            expect(refreshButton().disabled).toBe(false);
            expect(refreshButton().classList.contains('is-refreshing')).toBe(false);
        });

        it('turns until the first load of the tree is rendered', async () => {
            let loaded!: () => void;
            const firstLoad = new Promise<void>((resolve) => (loaded = resolve));
            // Turbo starts the eager load before Stimulus connects.
            Object.defineProperty(HTMLElement.prototype, 'loaded', {
                configurable: true,
                get(this: HTMLElement) {
                    return this.localName === 'turbo-frame' ? firstLoad : undefined;
                },
            });
            try {
                await start([], 'dir');

                expect(refreshButton().classList.contains('is-refreshing')).toBe(true);

                loaded();
                await settle();

                expect(refreshButton().classList.contains('is-refreshing')).toBe(false);
            } finally {
                delete (HTMLElement.prototype as { loaded?: unknown }).loaded;
            }
        });

        it('also turns while the tree reloads after a delete, until the reload settles', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(true);
            fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'dir', file: null, dir: '/notes' }, action: { path: '/notes/b.md' } }));
            await start([], 'dir');
            let reloaded!: () => void;
            reload.mockReturnValue(new Promise<void>((resolve) => (reloaded = resolve)));

            click('[data-action="click->mode-dir#deleteEntry"]');
            await settle();

            expect(reload).toHaveBeenCalledTimes(1);
            expect(refreshButton().classList.contains('is-refreshing')).toBe(true);

            reloaded();
            await settle();

            expect(refreshButton().classList.contains('is-refreshing')).toBe(false);
        });

        it('keeps turning while a later reload runs: the cancelled one never settles', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'dir', file: null, dir: '/notes' }, action: {} }));
            await start([], 'dir');
            const settles: Array<() => void> = [];
            reload.mockImplementation(() => new Promise<void>((resolve) => settles.push(resolve)));

            click('[data-action="click->mode-dir#refresh"]');
            await settle();

            expect(reload).toHaveBeenCalledTimes(1);
            emit('editor:do-delete-succeeded', { state: { ...INITIAL, mode: 'dir' }, action: { path: '/notes/b.md' } });
            expect(reload).toHaveBeenCalledTimes(2);

            settles[0]();
            await settle();
            expect(refreshButton().classList.contains('is-refreshing')).toBe(true);

            settles[1]();
            await settle();
            expect(refreshButton().classList.contains('is-refreshing')).toBe(false);
        });

        it('on failure, stops turning and leaves the tree alone', async () => {
            fetchMock.mockRejectedValue(new TypeError('Network down'));
            await start([], 'dir');

            click('[data-action="click->mode-dir#refresh"]');
            await settle();

            expect(reload).not.toHaveBeenCalled();
            expect(refreshButton().disabled).toBe(false);
            expect(refreshButton().classList.contains('is-refreshing')).toBe(false);
            expect(toasts).toEqual([{ type: 'error', message: 'Generic failure' }]);
        });
    });

    describe('change_file', () => {
        it('puts the picked file on top of the history and asks the master', async () => {
            invoke.mockResolvedValue('/notes/c.md');
            fetchMock.mockResolvedValue(jsonResponse({ state: { file: '/notes/c.md' }, action: { path: '/notes/c.md' } }));
            await start(['/notes/a.md']);

            click('[data-action="click->mode-single#openFile"]');
            await settle();

            expect(history()).toEqual(['/notes/c.md', '/notes/a.md']);
            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe('/editor/file');
            expect((init.body as FormData).get('path')).toBe('/notes/c.md');
        });

        it('drops a history entry whose open fails: the file is gone', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: { file: null }, error: 'File not found' }, 404));
            await start(['/notes/a.md', '/notes/gone.md']);

            click('.mode-history a[data-path="/notes/gone.md"]');
            await settle();

            expect(history()).toEqual(['/notes/a.md']);
            expect(toasts).toEqual([{ type: 'error', message: 'File not found' }]);
        });

        it('opens from the tree', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: { file: '/notes/b.md' }, action: { path: '/notes/b.md' } }));
            await start([], 'dir');

            click('a[data-action="click->mode-dir#openFile"]');
            await settle();

            expect((fetchMock.mock.calls[0][1].body as FormData).get('path')).toBe('/notes/b.md');
        });
    });

    describe('delete and rename', () => {
        it('deletes from the tree: one success toast, the tree reloads, the history drops the entry', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(true);
            fetchMock.mockResolvedValue(jsonResponse({ state: {}, action: { path: '/notes/b.md' } }));
            await start(['/notes/a.md', '/notes/b.md'], 'dir');

            click('[data-action="click->mode-dir#deleteEntry"]');
            await settle();

            expect(vi.mocked(confirmDialog).mock.calls[0][0].question).toBe('Delete b.md?');
            expect(fetchMock.mock.calls[0][0]).toBe('/file/delete');
            expect(toasts).toEqual([{ type: 'success', message: 'File deleted' }]);
            expect(reload).toHaveBeenCalledTimes(1);
            expect(history()).toEqual(['/notes/a.md']);
        });

        it('asks nothing when the deletion is not confirmed', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start([], 'dir');

            click('[data-action="click->mode-dir#deleteEntry"]');
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('renames from the history: the entry changes in place and the tree reloads', async () => {
            vi.mocked(renameDialog).mockResolvedValue('z.md');
            fetchMock.mockResolvedValue(jsonResponse({ state: {}, action: { oldPath: '/notes/a.md', newPath: '/notes/z.md' } }));
            await start(['/notes/b.md', '/notes/a.md']);

            click('.mode-history button[data-path="/notes/a.md"][data-action$="renameEntry"]');
            await settle();

            const body = fetchMock.mock.calls[0][1].body as FormData;
            expect([body.get('path'), body.get('name')]).toEqual(['/notes/a.md', 'z.md']);
            expect(history()).toEqual(['/notes/b.md', '/notes/z.md']);
            expect(toasts).toEqual([{ type: 'success', message: 'File renamed' }]);
            expect(reload).toHaveBeenCalledTimes(1);
        });

        it('a failed rename leaves the history alone and only the master toasts', async () => {
            vi.mocked(renameDialog).mockResolvedValue('b.md');
            fetchMock.mockResolvedValue(jsonResponse({ state: {}, error: 'A file with that name already exists' }, 409));
            await start(['/notes/a.md']);

            click('.mode-history button[data-action$="renameEntry"]');
            await settle();

            expect(history()).toEqual(['/notes/a.md']);
            expect(toasts).toEqual([{ type: 'error', message: 'A file with that name already exists' }]);
            expect(reload).not.toHaveBeenCalled();
        });
    });

    it('leaves the tree alone for a file outside the current folder', async () => {
        vi.mocked(renameDialog).mockResolvedValue('z.md');
        vi.mocked(confirmDialog).mockResolvedValue(true);
        fetchMock.mockImplementation(async (url: string, init: RequestInit) => {
            const path = (init.body as FormData).get('path') as string;

            return url === '/file/rename'
                ? jsonResponse({ state: {}, action: { oldPath: path, newPath: '/elsewhere/z.md' } })
                : jsonResponse({ state: {}, action: { path } });
        });
        // `/notes-old` shares a prefix with the folder `/notes` without being in it.
        await start(['/elsewhere/a.md', '/notes-old/b.md']);

        click('.mode-history button[data-path="/elsewhere/a.md"][data-action$="renameEntry"]');
        await settle();
        click('.mode-history button[data-path="/notes-old/b.md"][data-action$="deleteEntry"]');
        await settle();
        emit('editor:do-save_as-succeeded', { state: { ...INITIAL, file: '/elsewhere/new.md' }, action: { path: '/elsewhere/new.md' } });

        expect(history()).toEqual(['/elsewhere/new.md', '/elsewhere/z.md']);
        expect(reload).not.toHaveBeenCalled();
    });

    it('follows Save as and a current file found gone', async () => {
        await start(['/notes/a.md']);

        emit('editor:do-save_as-succeeded', { state: { ...INITIAL, file: '/notes/new.md' }, action: { path: '/notes/new.md' } });
        expect(history()).toEqual(['/notes/new.md', '/notes/a.md']);
        expect(reload).toHaveBeenCalledTimes(1);

        emit('editor:state-resynced', { state: { ...INITIAL, file: null }, anomaly: { file: '/notes/a.md' } });
        expect(history()).toEqual(['/notes/new.md']);
    });
});
