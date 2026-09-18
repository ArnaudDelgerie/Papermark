import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EditorStateController from '../../assets/controllers/editor_state_controller';
import { emit, on } from '../../assets/editor/events';
import { INITIAL, masterHtml } from './fixtures';
import { jsonResponse, mount, settle, unmount } from './stimulus';

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
        expect(init.headers).toEqual({ 'X-CSRF-TOKEN': 'tk-mode' });
        expect((init.body as FormData).get('mode')).toBe('dir');
        // readonly and ai_enabled are not sent by the routes: they are kept.
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
        fetchMock.mockResolvedValue(jsonResponse({ state: { mode: 'single', file: null, dir: '/notes' }, error: 'File not found' }, 404));
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

    it('without an answer, fails with the unchanged state and the generic message', async () => {
        fetchMock.mockRejectedValue(new TypeError('Network down'));
        const failed = vi.fn();
        on('editor:nav-change_dir-failed', failed);

        emit('editor:nav-change_dir-requested', { action: { path: '/other' } });
        await settle();

        expect(failed).toHaveBeenCalledWith({ state: INITIAL, action: { path: '/other' } });
        expect(toasts).toEqual([{ type: 'error', message: 'Generic failure' }]);
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
});
