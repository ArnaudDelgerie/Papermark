import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EditorController from '../../assets/controllers/editor_controller';
import EditorStateController from '../../assets/controllers/editor_state_controller';
import ModeSwitchController from '../../assets/controllers/mode_switch_controller';
import EditorFactory from '../../assets/editor/editor-factory';
import { type EditorState, emit, on } from '../../assets/editor/events';
import { confirmDialog } from '../../assets/utils/confirm-dialog';
import { INITIAL, attr, masterHtml } from './fixtures';
import { jsonResponse, mount, settle, unmount } from './stimulus';

vi.mock('../../assets/editor/editor-factory', () => ({ default: { create: vi.fn() } }));
vi.mock('../../assets/utils/confirm-dialog', () => ({ confirmDialog: vi.fn() }));
// The fake Crepe below applies what replaceAll() returns.
vi.mock('@milkdown/utils', () => ({ replaceAll: (markdown: string, flush = false) => ({ replaceAll: markdown, flush }) }));

/** Just enough of Crepe for the controller: markdown in, markdown out, updates. */
function fakeCrepe(initial = '', serialize: (markdown: string) => string = (markdown) => markdown): {
    getMarkdown: () => string;
    type: (markdown: string) => void;
    flushes: boolean[];
    destroy: ReturnType<typeof vi.fn>;
} {
    let markdown = initial;
    // The flush flag of each replaceAll(): true starts a fresh state.
    const flushes: boolean[] = [];
    const listeners: Array<(ctx: unknown, markdown: string) => void> = [];
    const set = (value: string): void => {
        markdown = value;
        listeners.forEach((listener) => listener(null, serialize(markdown)));
    };

    return {
        getMarkdown: () => serialize(markdown),
        // What typing in the editor does.
        type: set,
        flushes,
        editor: {
            status: 'fake',
            action: (command: { replaceAll?: string; flush?: boolean }) => {
                if (command.replaceAll !== undefined) {
                    flushes.push(command.flush ?? false);
                    set(command.replaceAll);
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
         data-editor-file-csrf-token-value="tk-file" data-editor-ai-csrf-token-value="tk-ai"
         data-editor-urls-value="${attr({ file: '/editor/file', copy: '/file/copy', image: '/file/image', aiSubscribe: '', aiInstruct: '', aiAbort: '' })}"
         data-editor-i18n-value="${attr({ untitled: 'Untitled', toast: { saved: 'Saved', savedAs: 'Saved as {name}' } })}">
        <button type="button" data-action="click->editor#newFile" data-editor-leave-guard>New</button>
        <button type="button" data-editor-target="saveButton" data-action="click->editor#saveFile" disabled>Save</button>
        <button type="button" data-editor-target="saveAsButton" data-action="click->editor#saveFileAs" disabled>Save as</button>
        <span data-editor-target="dirtyIndicator" hidden></span>
        <span data-editor-target="filePath">Untitled</span>
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

    /** The server: the session's current file, and each route's answer. */
    let current: EditorState;
    function server(): void {
        fetchMock.mockImplementation(async (url: string, init?: RequestInit) => {
            const method = init?.method ?? 'GET';
            const body = init?.body as FormData | undefined;
            const reply = (action: object): Response => jsonResponse({ state: current, action });
            switch (`${method} ${url}`) {
                case 'GET /editor/file':
                    if (current.file === null) {
                        return jsonResponse({ path: null, content: null });
                    }
                    if (!(current.file in files)) {
                        const path = current.file;
                        current = { ...current, file: null };

                        return jsonResponse({ error: 'File not found', path }, 404);
                    }

                    return jsonResponse({ path: current.file, content: files[current.file] });
                case 'GET /editor/state':
                    return jsonResponse({ state: current });
                case 'POST /editor/mode':
                    current = { ...current, mode: body!.get('mode') as EditorState['mode'], file: null };

                    return reply({ mode: current.mode });
                case 'DELETE /editor/file':
                    current = { ...current, file: null };

                    return reply({});
                case 'POST /file/save': {
                    const path = body!.get('path') as string;
                    files[path] = body!.get('content') as string;
                    current = { ...current, file: path };

                    return reply({ path });
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

        expect(calls('GET', '/editor/file')).toHaveLength(0);
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
        expect(calls('GET', '/editor/file')).toHaveLength(2);
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
            expect(calls('GET', '/editor/file')).toHaveLength(1);
        });
    });

    it('loads and empties with a fresh document, so undo cannot bring another file back', async () => {
        await start(current);
        click('[data-action="click->editor#newFile"]');

        expect(crepe.flushes).toEqual([true, true]);
    });

    it('New empties at once and asks the master to drop the current file', async () => {
        await start(current);

        click('[data-action="click->editor#newFile"]');

        expect(crepe.getMarkdown()).toBe('');
        await settle();
        expect(calls('DELETE', '/editor/file')).toHaveLength(1);
        expect(current.file).toBeNull();
    });

    it('Save asks the master with the markdown, and is clean once answered', async () => {
        await start(current);
        crepe.type('# A, edited');
        expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);

        click('[data-editor-target="saveButton"]');
        await settle();

        const [, init] = calls('POST', '/file/save')[0];
        expect((init!.body as FormData).get('content')).toBe('# A, edited');
        expect(files['/notes/a.md']).toBe('# A, edited');
        expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        expect(toasts).toEqual([{ type: 'success', message: 'Saved' }]);
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
        expect(toasts).toEqual([{ type: 'success', message: 'Saved as new.md' }]);
        expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
    });

    it('follows the deletion and the renaming of its file, not of others', async () => {
        await start(current);

        emit('editor:do-rename-succeeded', { state: current, action: { oldPath: '/notes/other.md', newPath: '/notes/x.md' } });
        expect(label()).toBe('/notes/a.md');

        emit('editor:do-rename-succeeded', { state: current, action: { oldPath: '/notes/a.md', newPath: '/notes/b.md' } });
        expect(label()).toBe('/notes/b.md');
        expect(crepe.getMarkdown()).toBe('# A');

        emit('editor:do-delete-succeeded', { state: { ...current, file: null }, action: { path: '/notes/b.md' } });
        expect(crepe.getMarkdown()).toBe('');
        expect(label()).toBe('Untitled');
    });

    it('a current file found gone empties the editor and resyncs everyone', async () => {
        delete files['/notes/a.md'];
        const resynced = vi.fn();
        on('editor:state-resynced', resynced);

        await start(current);
        await settle();

        expect(crepe.getMarkdown()).toBe('');
        expect(toasts).toEqual([{ type: 'error', message: 'File not found' }]);
        expect(calls('GET', '/editor/state')).toHaveLength(1);
        expect(resynced).toHaveBeenCalledWith({ state: { ...INITIAL, file: null }, anomaly: { file: '/notes/a.md' } });
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

            const [, init] = calls('POST', '/file/save')[0];
            expect((init!.body as FormData).get('content')).toBe('# A, edited');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
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
