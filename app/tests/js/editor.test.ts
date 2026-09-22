import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { abortAICmd } from '@milkdown/crepe/feature/ai';
import { EditorStatus, commandsCtx } from '@milkdown/kit/core';
import EditorController from '../../assets/controllers/editor_controller';
import EditorStateController from '../../assets/controllers/editor_state_controller';
import ModeSwitchController from '../../assets/controllers/mode_switch_controller';
import EditorFactory from '../../assets/editor/editor-factory';
import { type EditorState, emit, on } from '../../assets/editor/events';
import { confirmDialog } from '../../assets/utils/confirm-dialog';
import { saveConflictDialog } from '../../assets/utils/conflict-dialog';
import { INITIAL, attr, masterHtml } from './fixtures';
import { jsonResponse, mount, settle, unmount } from './stimulus';
import EDITOR_I18N from '../contract/i18n/editor.json';

vi.mock('../../assets/editor/editor-factory', () => ({ default: { create: vi.fn() } }));
vi.mock('../../assets/utils/confirm-dialog', () => ({ confirmDialog: vi.fn() }));
vi.mock('../../assets/utils/conflict-dialog', () => ({ saveConflictDialog: vi.fn() }));
// The fake Crepe below applies what replaceAll() returns.
vi.mock('@milkdown/utils', () => ({ replaceAll: (markdown: string, flush = false) => ({ replaceAll: markdown, flush }) }));

/** Just enough of Crepe for the controller: markdown in, markdown out, updates. */
function fakeCrepe(initial = '', serialize: (markdown: string) => string = (markdown) => markdown): {
    getMarkdown: () => string;
    type: (markdown: string) => void;
    flushes: boolean[];
    commands: { call: ReturnType<typeof vi.fn>; get: ReturnType<typeof vi.fn> };
    destroy: ReturnType<typeof vi.fn>;
} {
    let markdown = initial;
    // The flush flag of each replaceAll(): true starts a fresh state.
    const flushes: boolean[] = [];
    const listeners: Array<(ctx: unknown, markdown: string) => void> = [];
    // What #discardAi() calls and what #isAiInProgress() reads, session in
    // test mode (lot 03): get(key)(payload)(state) says "no session".
    const commands = {
        call: vi.fn(),
        get: vi.fn(() => () => () => false),
    };
    const view = { state: {} };
    const ctx = { get: (key: unknown) => (key === commandsCtx ? commands : view) };
    const set = (value: string): void => {
        markdown = value;
        listeners.forEach((listener) => listener(null, serialize(markdown)));
    };

    return {
        getMarkdown: () => serialize(markdown),
        // What typing in the editor does.
        type: set,
        flushes,
        commands,
        editor: {
            status: EditorStatus.Created,
            ctx,
            action: (command: unknown) => {
                // #replaceDocument() passes what replaceAll() returned;
                // #discardAi() passes a plain function to run on the ctx.
                if (typeof command === 'function') {
                    (command as (ctx: unknown) => void)(ctx);
                } else if ((command as { replaceAll?: string }).replaceAll !== undefined) {
                    flushes.push((command as { flush?: boolean }).flush ?? false);
                    set((command as { replaceAll: string }).replaceAll);
                }
            },
        },
        on: (register: (listener: { markdownUpdated: (fn: (ctx: unknown, md: string) => void) => void }) => void) => {
            register({ markdownUpdated: (fn) => listeners.push(fn) });
        },
        destroy: vi.fn(),
    } as ReturnType<typeof fakeCrepe>;
}

function editorHtml(): string {
    return `
    <nav data-controller="mode-switch">
        <button type="button" data-mode="single" class="mode-selector-link is-active" data-mode-switch-target="link"
            data-action="mode-switch#change" data-editor-leave-guard>Single</button>
        <button type="button" data-mode="dir" class="mode-selector-link" data-mode-switch-target="link"
            data-action="mode-switch#change" data-editor-leave-guard>Dir</button>
    </nav>
    <div data-controller="editor" data-editor-editor-state-outlet="#editor-state"
         data-editor-urls-value="${attr({ file: '/document', copy: '/document/copy', image: '/document/image', aiSubscribe: '', aiInstruct: '', aiAbort: '' })}"
         data-editor-i18n-value="${attr(EDITOR_I18N)}">
        <button type="button" data-action="click->editor#newFile" data-editor-leave-guard>New</button>
        <button type="button" data-editor-target="saveButton" data-action="click->editor#saveFile" disabled>Save</button>
        <button type="button" data-editor-target="saveAsButton" data-action="click->editor#saveFileAs" disabled>Save as</button>
        <span data-editor-target="dirtyIndicator" hidden></span>
        <span data-editor-target="filePath">Untitled</span>
        <div class="editor-load-error">
            <p data-editor-target="loadErrorMessage"></p>
            <button type="button" data-action="click->editor#retryLoad">Retry</button>
        </div>
    </div>`;
}

describe('the editor, with the master', () => {
    let application: Application;
    let crepe: ReturnType<typeof fakeCrepe>;
    let fetchMock: ReturnType<typeof vi.fn>;
    let invoke: ReturnType<typeof vi.fn>;
    const files: Record<string, string> = {};
    const toasts: Array<{ type: string; message: string }> = [];
    const onToast = (event: Event): number => toasts.push((event as CustomEvent).detail);

    const $ = <E extends Element = HTMLElement>(selector: string): E => document.querySelector<E>(selector)!;
    const click = (selector: string): void => {
        $(selector).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    };
    const label = (): string | null => $('[data-editor-target="filePath"]').textContent;
    const saveButton = (): HTMLButtonElement => $<HTMLButtonElement>('[data-editor-target="saveButton"]');
    const saveAsButton = (): HTMLButtonElement => $<HTMLButtonElement>('[data-editor-target="saveAsButton"]');
    const calls = (method: string, url: string): Array<[string, RequestInit | undefined]> =>
        (fetchMock.mock.calls as Array<[string, RequestInit | undefined]>).filter(([u, init]) => u === url && (init?.method ?? 'GET') === method);

    async function start(state: EditorState): Promise<void> {
        application = await mount(masterHtml(state, editorHtml()), {
            'editor-state': EditorStateController,
            'editor': EditorController,
            'mode-switch': ModeSwitchController,
        });
        await settle();
    }

    // The revision of the read (lot 03): 'r0', renewed at each save.
    let saveCount = 0;
    // Whether the next save answers 409, as a modified file would.
    let conflictNextSave = false;

    /** The server: the session's current file, and each route's answer. */
    let current: EditorState;
    function server(): void {
        fetchMock.mockImplementation(async (url: string, init?: RequestInit) => {
            const method = init?.method ?? 'GET';
            const body = init?.body as FormData | undefined;
            const reply = (action: object): Response => jsonResponse({ state: current, action });
            switch (`${method} ${url}`) {
                case 'GET /document':
                    if (current.file === null) {
                        return jsonResponse({ path: null, content: null });
                    }
                    if (!(current.file in files)) {
                        current = { ...current, file: null };

                        return jsonResponse({ state: current, genericErrors: ['Path not found'], mappedErrors: [] }, 404);
                    }

                    return jsonResponse({ path: current.file, content: files[current.file], revision: 'r0' });
                case 'GET /editor/state':
                    return jsonResponse({ state: current });
                case 'POST /editor/mode':
                    current = { ...current, mode: body!.get('mode') as EditorState['mode'], file: null };

                    return reply({ mode: current.mode });
                case 'DELETE /editor/file':
                    current = { ...current, file: null };

                    return reply({});
                case 'POST /document/save': {
                    const path = body!.get('path') as string;
                    if (conflictNextSave) {
                        conflictNextSave = false;

                        return jsonResponse({ state: current, genericErrors: ['The file was modified outside of Papermark'], mappedErrors: [] }, 409);
                    }
                    files[path] = body!.get('content') as string;
                    current = { ...current, file: path };

                    return reply({ path, revision: `r${++saveCount}` });
                }
            }
            throw new Error(`Unexpected ${method} ${url}`);
        });
    }

    beforeEach(() => {
        crepe = fakeCrepe();
        vi.mocked(EditorFactory.create).mockResolvedValue(crepe as never);
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        invoke = vi.fn();
        window.__TAURI__ = { core: { invoke: invoke as never } };
        vi.spyOn(console, 'error').mockImplementation(() => {});
        toasts.length = 0;
        window.addEventListener('toast:show', onToast);
        for (const key of Object.keys(files)) {
            delete files[key];
        }
        files['/notes/a.md'] = '# A';
        current = { ...INITIAL, file: '/notes/a.md' };
        saveCount = 0;
        conflictNextSave = false;
        server();
    });

    afterEach(async () => {
        await unmount(application);
        window.removeEventListener('toast:show', onToast);
        delete window.__TAURI__;
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('reads the current file of the state at load, once Crepe is ready', async () => {
        await start(current);

        expect(crepe.getMarkdown()).toBe('# A');
        expect(label()).toBe('/notes/a.md');
    });

    it('reads nothing at load without a current file', async () => {
        current = { ...current, file: null };
        await start(current);

        expect(calls('GET', '/document')).toHaveLength(0);
        expect(label()).toBe('Untitled');
    });

    it('empties on a switch of mode, once the server agrees', async () => {
        await start(current);

        click('[data-mode="dir"]');
        expect(crepe.getMarkdown()).toBe('# A');
        await settle();

        expect(crepe.getMarkdown()).toBe('');
        expect(label()).toBe('Untitled');
    });

    it('reads the file again when it is picked again, even the current one', async () => {
        await start(current);
        crepe.type('# A, edited');

        emit('editor:nav-change_file-succeeded', { state: current, action: { path: '/notes/a.md' } });
        await settle();

        expect(crepe.getMarkdown()).toBe('# A');
        expect(calls('GET', '/document')).toHaveLength(2);
    });

    describe('an import', () => {
        it('that opened a file loads it', async () => {
            await start(current);
            files['/notes/imported.md'] = '# Imported';
            current = { ...current, file: '/notes/imported.md' };

            emit('editor:do-import-succeeded', {
                state: current,
                action: { destination: '/notes/imported', openMode: 'single', ignoredEntries: [] },
            });
            await settle();

            expect(crepe.getMarkdown()).toBe('# Imported');
            expect(label()).toBe('/notes/imported.md');
        });

        it('that opened a folder empties the editor', async () => {
            await start(current);
            current = { ...current, mode: 'dir', file: null, dir: '/notes/project' };

            emit('editor:do-import-succeeded', {
                state: current,
                action: { destination: '/notes/project', openMode: 'dir', ignoredEntries: [] },
            });
            await settle();

            expect(crepe.getMarkdown()).toBe('');
            expect(label()).toBe('Untitled');
        });

        it('that opened nothing leaves the document as it is', async () => {
            await start(current);
            crepe.type('# A, edited');

            emit('editor:do-import-succeeded', {
                state: current,
                action: { destination: '/notes/pdfs', openMode: null, ignoredEntries: ['a.pdf'] },
            });
            await settle();

            expect(crepe.getMarkdown()).toBe('# A, edited');
            expect(label()).toBe('/notes/a.md');
            expect(calls('GET', '/document')).toHaveLength(1);
        });
    });

    it('loads and empties with a fresh document, so undo cannot bring another file back', async () => {
        await start(current);
        click('[data-action="click->editor#newFile"]');

        // A load now empties first (FRT-04): the emptying for the load, the
        // load itself, then the one New asked for.
        expect(crepe.flushes).toEqual([true, true, true]);
    });

    it('New empties at once and asks the master to drop the current file', async () => {
        await start(current);

        click('[data-action="click->editor#newFile"]');

        expect(crepe.getMarkdown()).toBe('');
        await settle();
        expect(calls('DELETE', '/editor/file')).toHaveLength(1);
        expect(current.file).toBeNull();
    });

    it('Save asks the master with the markdown and the revision it read, and is clean once answered', async () => {
        await start(current);
        crepe.type('# A, edited');
        expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);

        click('[data-editor-target="saveButton"]');
        await settle();

        const [, init] = calls('POST', '/document/save')[0];
        expect((init!.body as FormData).get('content')).toBe('# A, edited');
        // What GET /editor/file gave with the content (lot 03).
        expect((init!.body as FormData).get('revision')).toBe('r0');
        expect(files['/notes/a.md']).toBe('# A, edited');
        expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.saved }]);
    });

    it('Save as takes the path the server answers with', async () => {
        current = { ...current, mode: 'dir', dir: '/notes', file: null };
        await start(current);
        crepe.type('# New');
        invoke.mockResolvedValue('/notes/new.md');

        click('[data-editor-target="saveAsButton"]');
        await settle();

        // The dialog opens in the current folder, in dir mode.
        expect(invoke).toHaveBeenCalledWith('save_path', expect.objectContaining({ fileName: 'untitled.md', directory: '/notes' }));
        expect(label()).toBe('/notes/new.md');
        expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.savedAs.replace('{name}', 'new.md') }]);
        expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
    });

    describe('Save, following the dirty state (lot 03)', () => {
        it('is grayed on a clean document and never asks the master', async () => {
            await start(current);

            expect(saveButton().disabled).toBe(true);
            click('[data-editor-target="saveButton"]');
            await settle();

            expect(calls('POST', '/document/save')).toHaveLength(0);
        });

        it('is active on an emptied document: a zero-byte file is a legitimate file', async () => {
            await start(current);
            crepe.type('');

            expect(saveButton().disabled).toBe(false);
            click('[data-editor-target="saveButton"]');
            await settle();

            expect(files['/notes/a.md']).toBe('');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        });

        it('stays grayed on a document with no path: Save as is the CTA for that', async () => {
            current = { ...current, file: null };
            await start(current);
            crepe.type('# New');

            expect(saveButton().disabled).toBe(true);
            expect(saveAsButton().disabled).toBe(false);
            // Even a click forced on the grayed button goes nowhere.
            click('[data-editor-target="saveButton"]');
            await settle();

            expect(invoke).not.toHaveBeenCalled();
            expect(calls('POST', '/document/save')).toHaveLength(0);
        });

        it('keeps the buttons disabled while a save is in flight, and ignores a second click', async () => {
            await start(current);
            crepe.type('# A, edited');
            let answer!: (response: Response) => void;
            fetchMock.mockImplementation((url: string) => url === '/document/save'
                ? new Promise((resolve) => { answer = resolve; })
                : Promise.reject(new Error(`Unexpected ${url}`)));

            click('[data-editor-target="saveButton"]');
            await settle();

            expect(saveButton().disabled).toBe(true);
            expect(saveAsButton().disabled).toBe(true);
            click('[data-editor-target="saveButton"]');
            await settle();
            expect(calls('POST', '/document/save')).toHaveLength(1);

            answer(jsonResponse({ state: current, action: { path: '/notes/a.md', revision: 'r1' } }));
            await settle();

            // Clean again, not stuck: only Save stays grayed until it is edited.
            expect(saveButton().disabled).toBe(true);
            expect(saveAsButton().disabled).toBe(false);
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
            expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.saved }]);
        });

        it('renews the revision at each save and sends it back on the next one', async () => {
            await start(current);
            crepe.type('# A, edited');

            click('[data-editor-target="saveButton"]');
            await settle();
            expect((calls('POST', '/document/save')[0][1]!.body as FormData).get('revision')).toBe('r0');

            crepe.type('# A, edited more');
            click('[data-editor-target="saveButton"]');
            await settle();

            expect((calls('POST', '/document/save')[1][1]!.body as FormData).get('revision')).toBe('r1');
        });

        it('on a 409, asks Save as or Overwrite instead of toasting, and Overwrite writes without a revision', async () => {
            vi.mocked(saveConflictDialog).mockResolvedValue('overwrite');
            await start(current);
            crepe.type('# A, edited');
            conflictNextSave = true;

            click('[data-editor-target="saveButton"]');
            await settle();

            // The 409 itself is never toasted — only the overwrite's success.
            expect(saveConflictDialog).toHaveBeenCalledWith(expect.objectContaining({
                message: 'The file was modified outside of Papermark',
                question: EDITOR_I18N.conflict.question,
                cancelLabel: EDITOR_I18N.conflict.cancel,
                saveAsLabel: EDITOR_I18N.conflict.saveAs,
                overwriteLabel: EDITOR_I18N.conflict.overwrite,
            }));
            // Écraser replays the save without a revision, on fresh markdown.
            expect(calls('POST', '/document/save')).toHaveLength(2);
            const [, init] = calls('POST', '/document/save')[1];
            expect((init!.body as FormData).get('revision')).toBe(null);
            expect((init!.body as FormData).get('content')).toBe('# A, edited');
            expect(files['/notes/a.md']).toBe('# A, edited');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
            expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.saved }]);
        });

        it('on a 409, Save as goes through the picker', async () => {
            vi.mocked(saveConflictDialog).mockResolvedValue('save_as');
            await start(current);
            crepe.type('# A, edited');
            conflictNextSave = true;
            invoke.mockResolvedValue('/notes/copy.md');

            click('[data-editor-target="saveButton"]');
            await settle();

            expect(saveConflictDialog).toHaveBeenCalledTimes(1);
            expect(invoke).toHaveBeenCalledWith('save_path', expect.objectContaining({ fileName: 'a.md' }));
            const [, init] = calls('POST', '/document/save')[1];
            expect((init!.body as FormData).get('path')).toBe('/notes/copy.md');
            expect((init!.body as FormData).get('revision')).toBe(null);
        });
    });

    describe('intention or anomaly (lot 03)', () => {
        it('keeps the text and drops the path when its file is deleted with unsaved changes', async () => {
            await start(current);
            crepe.type('# A, edited');

            emit('editor:do-delete-succeeded', { state: { ...current, file: null }, action: { path: '/notes/a.md' } });

            expect(crepe.getMarkdown()).toBe('# A, edited');
            expect(label()).toBe('Untitled');
            // Still dirty, and Save as is the way out.
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
            expect(saveAsButton().disabled).toBe(false);
            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.toast.currentFileDeleted.replace('{name}', 'a.md') }]);
        });

        it('marks a clean document unsaved when its file goes: the text is nowhere else now', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(current);

            // Not a line typed: the document matches what was read from disk.
            emit('editor:do-delete-succeeded', { state: { ...current, file: null }, action: { path: '/notes/a.md' } });

            expect(crepe.getMarkdown()).toBe('# A');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);

            // And leaving asks, instead of dropping the text without a word.
            click('[data-mode="dir"]');
            await settle();

            expect(confirmDialog).toHaveBeenCalledTimes(1);
            expect(calls('POST', '/editor/mode')).toHaveLength(0);
        });

        it('leaves an empty document clean when its file goes: nothing to lose', async () => {
            await start({ ...current, file: null });

            emit('editor:state-resynced', { state: { ...current, file: null }, anomaly: { file: '/notes/a.md' } });

            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        });

        it('same when a re-read finds it gone', async () => {
            await start(current);

            emit('editor:state-resynced', { state: { ...current, file: null }, anomaly: { file: '/notes/a.md' } });

            expect(crepe.getMarkdown()).toBe('# A');
            expect(label()).toBe('Untitled');
            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.toast.currentFileGone.replace('{name}', 'a.md') }]);
        });

        it('same when asking again for the current file finds it gone', async () => {
            await start(current);

            emit('editor:nav-change_file-failed', { state: { ...current, file: null }, action: { path: '/notes/a.md' } });

            expect(crepe.getMarkdown()).toBe('# A');
            expect(label()).toBe('Untitled');
        });

        it('a different file asked and gone is an intention: the editor empties', async () => {
            await start(current);

            emit('editor:nav-change_file-failed', { state: { ...current, file: null }, action: { path: '/notes/other.md' } });

            expect(crepe.getMarkdown()).toBe('');
            expect(label()).toBe('Untitled');
        });
    });

    describe('a load that fails (FRT-04)', () => {
        it('shows an explicit error state with Retry, not the old document', async () => {
            fetchMock.mockImplementation(async () => jsonResponse({ genericErrors: ['Open failed'] }, 500));
            await start(current);

            expect(crepe.getMarkdown()).toBe('');
            expect($('[data-editor-target="loadErrorMessage"]').textContent).toBe('Open failed');
            expect($('[data-controller="editor"]').classList.contains('is-load-failed')).toBe(true);
            expect(saveButton().disabled).toBe(true);
            expect(saveAsButton().disabled).toBe(true);
            expect(toasts).toEqual([]);

            // Retry: the same read, the same intention.
            server();
            click('[data-action="click->editor#retryLoad"]');
            await settle();

            expect(crepe.getMarkdown()).toBe('# A');
            expect(label()).toBe('/notes/a.md');
            expect($('[data-controller="editor"]').classList.contains('is-load-failed')).toBe(false);
            expect(saveAsButton().disabled).toBe(false);
        });

        it('falls back to the generic message when the server says nothing', async () => {
            fetchMock.mockImplementation(async () => jsonResponse({}, 500));
            await start(current);

            expect($('[data-editor-target="loadErrorMessage"]').textContent).toBe(EDITOR_I18N.loadError);
        });
    });

    it('follows the renaming of its file, not of others, and survives the deletion of its own', async () => {
        await start(current);

        emit('editor:do-rename-succeeded', { state: current, action: { oldPath: '/notes/other.md', newPath: '/notes/x.md' } });
        expect(label()).toBe('/notes/a.md');

        emit('editor:do-rename-succeeded', { state: current, action: { oldPath: '/notes/a.md', newPath: '/notes/b.md' } });
        expect(label()).toBe('/notes/b.md');
        expect(crepe.getMarkdown()).toBe('# A');

        // An anomaly (lot 03): the text stays, only the path falls — and it
        // counts as unsaved, its copy on disk having just gone.
        emit('editor:do-delete-succeeded', { state: { ...current, file: null }, action: { path: '/notes/b.md' } });
        expect(crepe.getMarkdown()).toBe('# A');
        expect(label()).toBe('Untitled');
        expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
        expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.toast.currentFileDeleted.replace('{name}', 'b.md') }]);
    });

    it('a current file found gone empties the editor and resyncs everyone', async () => {
        delete files['/notes/a.md'];
        const resynced = vi.fn();
        on('editor:state-resynced', resynced);

        await start(current);
        await settle();

        expect(crepe.getMarkdown()).toBe('');
        expect(toasts).toEqual([{ type: 'error', message: 'Path not found' }]);
        expect(calls('GET', '/editor/state')).toHaveLength(1);
        expect(resynced).toHaveBeenCalledWith({ state: { ...INITIAL, file: null }, anomaly: { file: '/notes/a.md' } });
    });

    it('a 404 without a body falls back to the server-given file-not-found text', async () => {
        const answer = fetchMock.getMockImplementation() as (url: string, init?: RequestInit) => Promise<Response>;
        fetchMock.mockImplementation(async (url: string, init?: RequestInit) =>
            url === '/document' ? new Response('', { status: 404 }) : answer(url, init),
        );

        await start(current);
        await settle();

        expect(crepe.getMarkdown()).toBe('');
        expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.toast.fileNotFound }]);
    });

    describe('the AI, following the state', () => {
        interface Creation {
            aiEnabled: boolean;
            defaultValue: string;
        }
        let crepes: Array<ReturnType<typeof fakeCrepe>>;
        // How the next Crepe serializes what it is given.
        let serialize: (markdown: string) => string;

        const creations = (): Creation[] => vi.mocked(EditorFactory.create).mock.calls.map(([options]) => options as unknown as Creation);
        const withAi = (aiEnabled: boolean): EditorState => ({ ...current, ai_enabled: aiEnabled });

        beforeEach(() => {
            crepes = [];
            serialize = (markdown) => markdown;
            vi.mocked(EditorFactory.create).mockImplementation((async (options: Creation) => {
                const created = fakeCrepe(options.defaultValue, serialize);
                crepes.push(created);

                return created;
            }) as never);
        });

        it('creates Crepe with the AI as the state has it at load', async () => {
            await start(withAi(false));
            expect(creations().map((creation) => creation.aiEnabled)).toEqual([false]);

            await unmount(application);
            vi.mocked(EditorFactory.create).mockClear();
            await start(withAi(true));
            expect(creations().map((creation) => creation.aiEnabled)).toEqual([true]);
        });

        it.each([
            ['do-save_settings-succeeded', {}],
            ['do-set_key-succeeded', { name: 'mistral' }],
            ['do-delete_key-succeeded', { name: 'mistral' }],
        ] as const)('%s turning the AI on recreates Crepe around the same markdown, with no confirmation', async (name, action) => {
            await start(withAi(false));
            crepes[0].type('# A, edited');

            emit(`editor:${name}`, { state: withAi(true), action } as never);
            await settle();

            expect(creations()).toEqual([
                expect.objectContaining({ aiEnabled: false }),
                expect.objectContaining({ aiEnabled: true, defaultValue: '# A, edited' }),
            ]);
            expect(crepes[0].destroy).toHaveBeenCalledTimes(1);
            expect(confirmDialog).not.toHaveBeenCalled();
            // What was on screen is still there: the file, and the unsaved state.
            expect(crepes[1].getMarkdown()).toBe('# A, edited');
            expect(label()).toBe('/notes/a.md');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
        });

        it('turning the AI off recreates it without', async () => {
            await start(withAi(true));

            emit('editor:do-delete_key-succeeded', { state: withAi(false), action: { name: 'anthropic' } });
            await settle();

            expect(creations().map((creation) => creation.aiEnabled)).toEqual([true, false]);
        });

        it('does nothing when the state agrees with what Crepe was created with', async () => {
            await start(withAi(true));

            emit('editor:do-save_settings-succeeded', { state: withAi(true), action: {} });
            await settle();

            expect(creations()).toHaveLength(1);
            expect(crepes[0].destroy).not.toHaveBeenCalled();
        });

        it('a clean document stays clean even if Crepe serializes it differently the second time', async () => {
            await start(withAi(false));
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
            serialize = (markdown) => `${markdown}\n`;

            emit('editor:do-set_key-succeeded', { state: withAi(true), action: { name: 'mistral' } });
            await settle();

            expect(crepes[1].getMarkdown()).toBe('# A\n');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        });

        it('changes met one after the other are handled one at a time, the last one wins', async () => {
            await start(withAi(false));

            emit('editor:do-set_key-succeeded', { state: withAi(true), action: { name: 'mistral' } });
            emit('editor:do-delete_key-succeeded', { state: withAi(false), action: { name: 'mistral' } });
            await settle();

            expect(creations().map((creation) => creation.aiEnabled)).toEqual([false, true, false]);
            expect(crepes[1].destroy).toHaveBeenCalledTimes(1);
        });

        it('Save still works on the new Crepe, with its markdown', async () => {
            await start(withAi(false));
            emit('editor:do-save_settings-succeeded', { state: withAi(true), action: {} });
            await settle();
            crepes[1].type('# A, edited');

            click('[data-editor-target="saveButton"]');
            await settle();

            const [, init] = calls('POST', '/document/save')[0];
            expect((init!.body as FormData).get('content')).toBe('# A, edited');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        });

        it('discards the AI on every document replacement (IA-04)', async () => {
            await start(withAi(true));

            click('[data-action="click->editor#newFile"]');
            await settle();

            expect(crepes[0].commands.call).toHaveBeenCalledWith(abortAICmd.key, { keep: false });
        });

        it('discards the AI when the current file is deleted, even mid-generation', async () => {
            await start(withAi(true));

            emit('editor:do-delete-succeeded', { state: withAi(true), action: { path: '/notes/a.md' } });

            expect(crepes[0].commands.call).toHaveBeenCalledWith(abortAICmd.key, { keep: false });
        });

        it('the leave guard also sees a Crepe session in progress, not just streaming and diff', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(withAi(true));
            // The session read in test mode: the command would abort (IA-04).
            crepes[0].commands.get.mockReturnValue(() => () => true);

            click('[data-mode="dir"]');
            await settle();

            expect(confirmDialog).toHaveBeenCalledTimes(1);
            expect(calls('POST', '/editor/mode')).toHaveLength(0);
        });
    });

    describe('the leave guard', () => {
        it('lets nothing through while unsaved work is not given up', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(current);
            crepe.type('# A, edited');

            click('[data-mode="dir"]');
            await settle();

            expect(confirmDialog).toHaveBeenCalledTimes(1);
            expect(calls('POST', '/editor/mode')).toHaveLength(0);
            expect($('[data-mode="single"]').classList.contains('is-active')).toBe(true);
        });

        it('replays the click once confirmed, and the request goes out', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(true);
            await start(current);
            crepe.type('# A, edited');

            click('[data-mode="dir"]');
            await settle();

            expect(calls('POST', '/editor/mode')).toHaveLength(1);
            expect(crepe.getMarkdown()).toBe('');
        });

        it('asks nothing with nothing to lose', async () => {
            await start(current);

            click('[data-mode="dir"]');
            await settle();

            expect(confirmDialog).not.toHaveBeenCalled();
            expect(calls('POST', '/editor/mode')).toHaveLength(1);
        });
    });
});
