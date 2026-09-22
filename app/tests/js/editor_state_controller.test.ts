import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EditorStateController from '../../assets/controllers/editor_state_controller';
import { emit, on } from '../../assets/editor/events';
import { INITIAL, masterHtml } from './fixtures';
import { jsonResponse, mount, settle, unmount } from './stimulus';
import EDITOR_STATE_I18N from '../contract/i18n/editor-state.json';

describe('editor-state (the master)', () => {
    let application: Application;
    let fetchMock: ReturnType<typeof vi.fn>;
    const toasts: unknown[] = [];
    const onToast = (event: Event): number => toasts.push((event as CustomEvent).detail);

    beforeEach(async () => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        vi.spyOn(console, 'error').mockImplementation(() => {});
        toasts.length = 0;
        window.addEventListener('toast:show', onToast);
        application = await mount(masterHtml(), { 'editor-state': EditorStateController });
    });

    afterEach(async () => {
        await unmount(application);
        window.removeEventListener('toast:show', onToast);
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('calls the route, merges the answer, and emits succeeded', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'dir', file: null, dir: '/notes' }, action: { mode: 'dir' } }));
        const succeeded = vi.fn();
        on('editor:nav-switch_mode-succeeded', succeeded);

        emit('editor:nav-switch_mode-requested', { action: { mode: 'dir' } });
        await settle();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/editor/mode');
        expect(init.method).toBe('POST');
        expect(init.headers).toEqual({ 'X-CSRF-TOKEN': 'tk-app' });
        expect((init.body as FormData).get('mode')).toBe('dir');
        // readonly is not sent by the routes: it is kept. ai_enabled is, but not by this one.
        expect(succeeded).toHaveBeenCalledWith({
            state: { mode: 'dir', file: null, dir: '/notes', readonly: false, ai_enabled: true },
            action: { mode: 'dir' },
        });
    });

    it('sends DELETE without a body for new_file', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ state: { file: null }, action: {} }));

        emit('editor:nav-new_file-requested', { action: {} });
        await settle();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/editor/file');
        expect(init.method).toBe('DELETE');
        expect(init.body).toBeUndefined();
    });

    it('on a refusal, takes the state the server sent and toasts its message', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'single', file: null, dir: '/notes' }, genericErrors: ['File not found'], mappedErrors: [] }, 404));
        const failed = vi.fn();
        on('editor:nav-change_file-failed', failed);

        emit('editor:nav-change_file-requested', { action: { path: '/notes/a.md' } });
        await settle();

        expect(failed).toHaveBeenCalledWith({
            state: { ...INITIAL, file: null },
            action: { path: '/notes/a.md' },
        });
        expect(toasts).toEqual([{ type: 'error', message: 'File not found' }]);
    });

    it('on a refusal with only field errors, toasts the first one', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ state: INITIAL, genericErrors: [], mappedErrors: [{ field: 'name', message: 'Not a document name' }] }, 422));

        emit('editor:do-rename-requested', { action: { path: '/notes/a.md', name: 'note.png' } });
        await settle();

        expect(toasts).toEqual([{ type: 'error', message: 'Not a document name' }]);
    });

    it('without an answer, fails with the unchanged state and the generic message', async () => {
        fetchMock.mockRejectedValue(new TypeError('Network down'));
        const failed = vi.fn();
        on('editor:nav-change_dir-failed', failed);

        emit('editor:nav-change_dir-requested', { action: { path: '/other' } });
        await settle();

        expect(failed).toHaveBeenCalledWith({ state: INITIAL, action: { path: '/other' } });
        expect(toasts).toEqual([{ type: 'error', message: EDITOR_STATE_I18N.failed }]);
    });

    it('a 500 without JSON is a failure without answer', async () => {
        fetchMock.mockResolvedValue(new Response('<html>boom</html>', { status: 500 }));
        const failed = vi.fn();
        on('editor:do-delete-failed', failed);

        emit('editor:do-delete-requested', { action: { path: '/notes/a.md' } });
        await settle();

        expect(failed).toHaveBeenCalledWith({ state: INITIAL, action: { path: '/notes/a.md' } });
    });

    it('never sends the markdown back in a failure', async () => {
        fetchMock.mockRejectedValue(new TypeError('Network down'));
        const failed = vi.fn();
        on('editor:do-save-failed', failed);

        emit('editor:do-save-requested', { action: { path: '/notes/a.md', content: '# Hi' } });
        await settle();

        expect(failed).toHaveBeenCalledWith({ state: INITIAL, action: { path: '/notes/a.md' } });
    });

    /**
     * A save conflict (lot 03) is the editor's to show, not the master's: no
     * toast, and the failure carries what its dialog needs.
     */
    it('a 409 on a save fails quietly, with the status and the message', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ state: INITIAL, genericErrors: ['The file was modified outside of Papermark'], mappedErrors: [] }, 409));
        const failed = vi.fn();
        on('editor:do-save-failed', failed);

        emit('editor:do-save-requested', { action: { path: '/notes/a.md', content: '# Hi', revision: 'r0' } });
        await settle();

        expect(failed).toHaveBeenCalledWith({
            state: INITIAL,
            action: { path: '/notes/a.md', revision: 'r0', status: 409, message: 'The file was modified outside of Papermark' },
        });
        expect(toasts).toEqual([]);
    });

    it('drops a nav request still in flight when the same action is asked again', async () => {
        fetchMock.mockImplementation((_url: string, init: RequestInit) => new Promise((resolve, reject) => {
            init.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
            const mode = (init.body as FormData).get('mode');
            setTimeout(() => resolve(jsonResponse({ state: { mode }, action: { mode } })), 10);
        }));
        const succeeded = vi.fn();
        const failed = vi.fn();
        on('editor:nav-switch_mode-succeeded', succeeded);
        on('editor:nav-switch_mode-failed', failed);

        emit('editor:nav-switch_mode-requested', { action: { mode: 'dir' } });
        emit('editor:nav-switch_mode-requested', { action: { mode: 'single' } });
        await new Promise((resolve) => setTimeout(resolve, 30));

        expect(failed).not.toHaveBeenCalled();
        expect(succeeded).toHaveBeenCalledTimes(1);
        expect(succeeded.mock.calls[0][0].action).toEqual({ mode: 'single' });
    });

    it('never drops a do request', async () => {
        fetchMock.mockImplementation((_url: string, init: RequestInit) => {
            const path = (init.body as FormData).get('path');

            return Promise.resolve(jsonResponse({ state: {}, action: { path } }));
        });
        const succeeded = vi.fn();
        on('editor:do-delete-succeeded', succeeded);

        emit('editor:do-delete-requested', { action: { path: '/a.md' } });
        emit('editor:do-delete-requested', { action: { path: '/b.md' } });
        await settle();

        expect(succeeded).toHaveBeenCalledTimes(2);
    });

    it('re-reads the state on an anomaly and emits state-resynced', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'single', file: null, dir: '/notes' } }));
        const resynced = vi.fn();
        on('editor:state-resynced', resynced);

        emit('editor:state-anomaly-reported', { anomaly: { file: '/notes/a.md' } });
        await settle();

        expect(fetchMock.mock.calls[0][0]).toBe('/editor/state');
        expect(resynced).toHaveBeenCalledWith({ state: { ...INITIAL, file: null }, anomaly: { file: '/notes/a.md' } });
    });

    it('drops the stale keys itself when the state cannot be re-read', async () => {
        fetchMock.mockRejectedValue(new TypeError('Network down'));
        const resynced = vi.fn();
        on('editor:state-resynced', resynced);

        emit('editor:state-anomaly-reported', { anomaly: { dir: '/notes' } });
        await settle();

        expect(resynced).toHaveBeenCalledWith({ state: { ...INITIAL, dir: null }, anomaly: { dir: '/notes' } });
    });

    describe('do-import', () => {
        const request = { archive: '/tmp/notes.zip', parentDir: '/notes' };

        it('sends the archive and the parent folder with the import token, and takes the state of the answer', async () => {
            const action = { destination: '/notes/notes', openMode: 'single', ignoredEntries: ['a.pdf'] };
            fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'single', file: '/notes/notes/doc.md', dir: '/notes' }, action }));
            const succeeded = vi.fn();
            on('editor:do-import-succeeded', succeeded);

            emit('editor:do-import-requested', { action: request });
            await settle();

            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe('/archive/import');
            expect(init.method).toBe('POST');
            expect(init.headers).toEqual({ 'X-CSRF-TOKEN': 'tk-app' });
            expect((init.body as FormData).get('archive')).toBe('/tmp/notes.zip');
            expect((init.body as FormData).get('parentDir')).toBe('/notes');
            expect(succeeded).toHaveBeenCalledWith({ state: { ...INITIAL, file: '/notes/notes/doc.md' }, action });
            expect(toasts).toEqual([]);
        });

        it('a refusal toasts the message and fails with what was asked and the state', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: INITIAL, genericErrors: ['The selected file is not a valid zip archive'], mappedErrors: [] }, 409));
            const failed = vi.fn();
            on('editor:do-import-failed', failed);

            emit('editor:do-import-requested', { action: request });
            await settle();

            expect(failed).toHaveBeenCalledWith({ state: INITIAL, action: request });
            expect(toasts).toEqual([{ type: 'error', message: 'The selected file is not a valid zip archive' }]);
        });

        it('is never dropped when asked twice', async () => {
            fetchMock.mockImplementation((_url: string, init: RequestInit) => {
                const archive = (init.body as FormData).get('archive');

                return Promise.resolve(jsonResponse({ state: {}, action: { destination: archive, openMode: null, ignoredEntries: [] } }));
            });
            const succeeded = vi.fn();
            on('editor:do-import-succeeded', succeeded);

            emit('editor:do-import-requested', { action: { ...request, archive: '/tmp/a.zip' } });
            emit('editor:do-import-requested', { action: { ...request, archive: '/tmp/b.zip' } });
            await settle();

            expect(succeeded).toHaveBeenCalledTimes(2);
        });
    });

    describe('the settings actions', () => {
        it('do-save_settings sends the form as it is, with the settings token', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: { ai_enabled: false }, action: {} }));
            const succeeded = vi.fn();
            on('editor:do-save_settings-succeeded', succeeded);
            const form = new FormData();
            form.append('settings[selected]', 'anthropic');

            emit('editor:do-save_settings-requested', { action: { form, url: '/settings' } });
            await settle();

            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe('/settings');
            expect(init.method).toBe('POST');
            expect(init.headers).toEqual({ 'X-CSRF-TOKEN': 'tk-app' });
            expect(init.body).toBe(form);
            // The state of the answer is taken: the AI went off.
            expect(succeeded).toHaveBeenCalledWith({ state: { ...INITIAL, ai_enabled: false }, action: {} });
            expect(toasts).toEqual([]);
        });

        it('an invalid form (422) fails with the mapped errors and no toast', async () => {
            const mappedErrors = [{ field: 'settings[providers][anthropic][model]', message: 'Anthropic · Model: not allowed' }];
            fetchMock.mockResolvedValue(jsonResponse(
                { state: { mode: 'single', file: '/notes/a.md', dir: '/notes', ai_enabled: true }, genericErrors: [], mappedErrors },
                422,
            ));
            const failed = vi.fn();
            on('editor:do-save_settings-failed', failed);
            const form = new FormData();

            emit('editor:do-save_settings-requested', { action: { form, url: '/settings' } });
            await settle();

            expect(failed).toHaveBeenCalledWith({ state: INITIAL, action: { form, url: '/settings', errors: mappedErrors } });
            expect(toasts).toEqual([]);
        });

        /**
         * do-save_settings always fails quietly: even a technical failure's
         * generic message lands in the modal's error list, not a toast — the
         * modal is always open when this action runs.
         */
        it('a technical failure of the save also fails quietly, its message in the errors', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: INITIAL, genericErrors: ['Could not save the setting'], mappedErrors: [] }, 500));
            const failed = vi.fn();
            on('editor:do-save_settings-failed', failed);
            const form = new FormData();

            emit('editor:do-save_settings-requested', { action: { form, url: '/settings' } });
            await settle();

            expect(failed).toHaveBeenCalledWith({
                state: INITIAL,
                action: { form, url: '/settings', errors: [{ field: '', message: 'Could not save the setting' }] },
            });
            expect(toasts).toEqual([]);
        });

        it('do-set_key puts the provider in the url and the key in the body', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: { ai_enabled: true }, action: { name: 'mistral' } }));
            const succeeded = vi.fn();
            on('editor:do-set_key-succeeded', succeeded);

            emit('editor:do-set_key-requested', { action: { name: 'mistral', key: 'sk-secret' } });
            await settle();

            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe('/settings/provider/mistral/key');
            expect(init.method).toBe('POST');
            expect((init.body as FormData).get('key')).toBe('sk-secret');
            expect(succeeded).toHaveBeenCalledWith({ state: INITIAL, action: { name: 'mistral' } });
        });

        it('never repeats the key in a failure', async () => {
            fetchMock.mockRejectedValue(new TypeError('Network down'));
            const failed = vi.fn();
            on('editor:do-set_key-failed', failed);

            emit('editor:do-set_key-requested', { action: { name: 'mistral', key: 'sk-secret' } });
            await settle();

            expect(failed).toHaveBeenCalledWith({ state: INITIAL, action: { name: 'mistral' } });
            expect(JSON.stringify(failed.mock.calls)).not.toContain('sk-secret');
        });

        it('do-delete_key is a DELETE on the provider, without a body', async () => {
            fetchMock.mockResolvedValue(jsonResponse({ state: { ai_enabled: false }, action: { name: 'anthropic' } }));
            const succeeded = vi.fn();
            on('editor:do-delete_key-succeeded', succeeded);

            emit('editor:do-delete_key-requested', { action: { name: 'anthropic' } });
            await settle();

            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe('/settings/provider/anthropic/key');
            expect(init.method).toBe('DELETE');
            expect(init.body).toBeUndefined();
            expect(succeeded).toHaveBeenCalledWith({ state: { ...INITIAL, ai_enabled: false }, action: { name: 'anthropic' } });
        });
    });
});
