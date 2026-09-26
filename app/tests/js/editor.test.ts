import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EditorController from '../../assets/controllers/editor_controller';
import EditorStateController from '../../assets/controllers/editor_state_controller';
import ModeSwitchController from '../../assets/controllers/mode_switch_controller';
import CrepeHost, { type ImageOrigin } from '../../assets/editor/crepe-host';
import { type EditorState, emit, on } from '../../assets/editor/events';
import { confirmDialog } from '../../assets/utils/confirm-dialog';
import { saveConflictDialog } from '../../assets/utils/conflict-dialog';
import { draftConflictDialog } from '../../assets/utils/draft-conflict-dialog';
import { IMAGE_EXTENSIONS } from '../../assets/utils/extensions';
import { INITIAL, attr, masterHtml } from './fixtures';
import { jsonResponse, mount, settle, unmount } from './stimulus';
import EDITOR_I18N from '../contract/i18n/editor.json';

vi.mock('../../assets/editor/crepe-host', () => ({ default: vi.fn() }));
vi.mock('../../assets/utils/confirm-dialog', () => ({ confirmDialog: vi.fn() }));
vi.mock('../../assets/utils/conflict-dialog', () => ({ saveConflictDialog: vi.fn() }));
vi.mock('../../assets/utils/draft-conflict-dialog', () => ({ draftConflictDialog: vi.fn() }));

const DRAFT_KEY = 'editor.draft';

// How the next recreate() serializes the markdown it carries over; reset in
// beforeEach, reassignable per test.
let serialize: (markdown: string) => string = (markdown) => markdown;

interface CrepeBuild {
    aiEnabled: boolean;
    markdown: string;
}

/**
 * Just enough of CrepeHost for the controller, stateful like the real one:
 * one build at create(), more through recreate() — governed by `serialize`,
 * reassignable per test, for the recreated document's markdown. replace()
 * and type() (what typing in the editor does) both swap the current
 * markdown and deliver it to whatever onChange() registered. The serial
 * ordering of concurrent recreate() calls is CrepeHost's own, tested in
 * crepe-host.test.ts; this fake only records the calls, in order.
 */
function fakeHost(): {
    create: ReturnType<typeof vi.fn>;
    recreate: ReturnType<typeof vi.fn>;
    whenIdle: ReturnType<typeof vi.fn>;
    destroy: ReturnType<typeof vi.fn>;
    markdown: () => string;
    type: (markdown: string) => void;
    replace: ReturnType<typeof vi.fn>;
    onChange: (listener: (markdown: string) => void) => void;
    onAiBusyChange: (listener: (busy: boolean) => void) => void;
    /** What a busy transition does: the state flips and the listener hears it (lot 02). */
    aiBusy: (busy: boolean) => void;
    setEditable: ReturnType<typeof vi.fn>;
    focus: ReturnType<typeof vi.fn>;
    isAiBusy: ReturnType<typeof vi.fn>;
    discardAi: ReturnType<typeof vi.fn>;
    insertImage: ReturnType<typeof vi.fn>;
    insertImageFromSlashMenu: ReturnType<typeof vi.fn>;
    printCopy: ReturnType<typeof vi.fn>;
    readonly created: boolean;
    readonly aiEnabled: boolean;
    readonly builds: CrepeBuild[];
} {
    let markdown = '';
    let aiEnabled = false;
    let createdFlag = false;
    let changeListener: ((markdown: string) => void) | null = null;
    let aiBusyListener: ((busy: boolean) => void) | null = null;
    const builds: CrepeBuild[] = [];
    const isAiBusy = vi.fn((): boolean => false);

    return {
        create: vi.fn((options: { markdown?: string; aiEnabled: boolean; aiProvider?: unknown }) => {
            markdown = options.markdown ?? '';
            aiEnabled = options.aiEnabled;
            createdFlag = true;
            builds.push({ aiEnabled, markdown });

            return Promise.resolve();
        }),
        recreate: vi.fn((nextAiEnabled: boolean, provider: () => unknown) => {
            if (!createdFlag || nextAiEnabled === aiEnabled) {
                return Promise.resolve(null);
            }
            const carried = markdown;
            provider();
            aiEnabled = nextAiEnabled;
            markdown = serialize(carried);
            builds.push({ aiEnabled: nextAiEnabled, markdown: carried });
            changeListener?.(markdown);

            return Promise.resolve(carried);
        }),
        whenIdle: vi.fn(() => Promise.resolve()),
        destroy: vi.fn(),
        markdown: () => markdown,
        // What typing in the editor does.
        type: (value: string) => {
            markdown = value;
            changeListener?.(markdown);
        },
        // Loading a document is not a change: the real host flushes, and
        // Milkdown's listener re-bases on the new document without calling
        // back. The controller has to update what it shows by itself.
        replace: vi.fn((value: string) => {
            markdown = value;
        }),
        onChange: (listener: (markdown: string) => void) => {
            changeListener = listener;
        },
        onAiBusyChange: (listener: (busy: boolean) => void) => {
            aiBusyListener = listener;
        },
        aiBusy: (busy: boolean) => {
            isAiBusy.mockReturnValue(busy);
            aiBusyListener?.(busy);
        },
        setEditable: vi.fn(),
        focus: vi.fn(),
        isAiBusy,
        discardAi: vi.fn(),
        insertImage: vi.fn(),
        insertImageFromSlashMenu: vi.fn(),
        printCopy: vi.fn(() => null),
        get created(): boolean {
            return createdFlag;
        },
        get aiEnabled(): boolean {
            return aiEnabled;
        },
        get builds(): CrepeBuild[] {
            return builds;
        },
    };
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
        <button type="button" data-editor-target="newButton" data-action="click->editor#newFile" data-editor-leave-guard>New</button>
        <button type="button" data-editor-target="saveButton" data-action="click->editor#saveFile" disabled>Save</button>
        <button type="button" data-editor-target="saveAsButton" data-action="click->editor#saveFileAs" disabled>Save as</button>
        <button type="button" data-editor-target="printButton" data-action="click->editor#printFile" disabled>Print</button>
        <button type="button" data-editor-target="copyMarkdownButton" data-action="click->editor#copyMarkdown" disabled>Copy</button>
        <span data-editor-target="dirtyIndicator" role="status" hidden title="Unsaved"><span class="visually-hidden">Unsaved</span></span>
        <span data-editor-target="filePath">Untitled</span>
        <button type="button" data-editor-target="toggleButton" data-action="click->editor#toggleReadonly" aria-pressed="false" title="Read only">
            <span class="visually-hidden" data-editor-target="toggleLabel">Read only</span>
        </button>
        <div class="editor-load-error">
            <p data-editor-target="loadErrorMessage"></p>
            <button type="button" data-action="click->editor#retryLoad">Retry</button>
        </div>
    </div>`;
}

describe('the editor, with the master', () => {
    let application: Application;
    let host: ReturnType<typeof fakeHost>;
    /** The callbacks of the host the controller currently holds (FRT-02/08). */
    let hostCallbacks: { onInsertImage: (origin: ImageOrigin) => void } | null = null;
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
    const draft = (): unknown => {
        const raw = sessionStorage.getItem(DRAFT_KEY);

        return raw === null ? null : JSON.parse(raw);
    };
    const saveButton = (): HTMLButtonElement => $<HTMLButtonElement>('[data-editor-target="saveButton"]');
    const saveAsButton = (): HTMLButtonElement => $<HTMLButtonElement>('[data-editor-target="saveAsButton"]');
    const printButton = (): HTMLButtonElement => $<HTMLButtonElement>('[data-editor-target="printButton"]');
    const copyMarkdownButton = (): HTMLButtonElement => $<HTMLButtonElement>('[data-editor-target="copyMarkdownButton"]');
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
    // What /document/image answers to a HEAD (FRT-08, lot 08): refused by
    // default, so no test inserts by accident.
    let imageAnswer: 'ok' | 'refused' | 'network' = 'refused';

    /** The server: the session's current file, and each route's answer. */
    let current: EditorState;
    function server(): void {
        fetchMock.mockImplementation(async (url: string, init?: RequestInit) => {
            const method = init?.method ?? 'GET';
            const body = init?.body as FormData | undefined;
            const reply = (action: object): Response => jsonResponse({ state: current, action });
            // The image route is asked with its path in the query (FRT-08).
            if (method === 'HEAD' && url.startsWith('/document/image')) {
                if (imageAnswer === 'network') {
                    throw new TypeError('network down');
                }

                return new Response(null, { status: imageAnswer === 'ok' ? 200 : 404 });
            }
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
                case 'POST /editor/file':
                    // The server resolves the path (a link is relative);
                    // this fake only records what was asked for.
                    current = { ...current, file: body!.get('path') as string };

                    return reply({ path: current.file });
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
                case 'POST /document/copy':
                    // Distinct from the raw markdown, so a test can tell the
                    // clipboard got the server's answer, not the editor's.
                    return jsonResponse({ content: `${body!.get('content') as string} (converted)` });
            }
            throw new Error(`Unexpected ${method} ${url}`);
        });
    }

    beforeEach(() => {
        serialize = (markdown) => markdown;
        // A regular function, not an arrow one: `new CrepeHost(...)` needs a
        // constructable implementation.
        vi.mocked(CrepeHost).mockImplementation(function (_root, _i18n, callbacks) {
            host = fakeHost();
            hostCallbacks = callbacks;
            return host as never;
        });
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        // The hub's close guard context (lot 02); every other command keeps
        // the old default answer, undefined.
        invoke = vi.fn((command: string) => {
            if (command === 'close_guard_context') {
                return Promise.resolve({ context: 'hub-document' });
            }

            return undefined;
        });
        window.__TAURI__ = { core: { invoke: invoke as never } };
        vi.spyOn(console, 'error').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        vi.spyOn(window, 'print').mockImplementation(() => {});
        Object.assign(navigator, { clipboard: { writeText: vi.fn(() => Promise.resolve()) } });
        toasts.length = 0;
        window.addEventListener('toast:show', onToast);
        for (const key of Object.keys(files)) {
            delete files[key];
        }
        files['/notes/a.md'] = '# A';
        current = { ...INITIAL, file: '/notes/a.md' };
        saveCount = 0;
        conflictNextSave = false;
        imageAnswer = 'refused';
        server();
    });

    afterEach(async () => {
        await unmount(application);
        window.removeEventListener('toast:show', onToast);
        delete window.__TAURI__;
        sessionStorage.clear();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('reads the current file of the state at load, once Crepe is ready', async () => {
        await start(current);

        expect(host.markdown()).toBe('# A');
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
        expect(host.markdown()).toBe('# A');
        await settle();

        expect(host.markdown()).toBe('');
        expect(label()).toBe('Untitled');
    });

    it('reads the file again when it is picked again, even the current one', async () => {
        await start(current);
        host.type('# A, edited');

        emit('editor:nav-change_file-succeeded', { state: current, action: { path: '/notes/a.md' } });
        await settle();

        expect(host.markdown()).toBe('# A');
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

            expect(host.markdown()).toBe('# Imported');
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

            expect(host.markdown()).toBe('');
            expect(label()).toBe('Untitled');
        });

        it('that opened nothing leaves the document as it is', async () => {
            await start(current);
            host.type('# A, edited');

            emit('editor:do-import-succeeded', {
                state: current,
                action: { destination: '/notes/pdfs', openMode: null, ignoredEntries: ['a.pdf'] },
            });
            await settle();

            expect(host.markdown()).toBe('# A, edited');
            expect(label()).toBe('/notes/a.md');
            expect(calls('GET', '/document')).toHaveLength(1);
        });
    });

    describe('an open_path', () => {
        it('that opened a file loads it', async () => {
            await start(current);
            files['/notes/opened.md'] = '# Opened';
            current = { ...current, file: '/notes/opened.md' };

            emit('editor:nav-open_path-succeeded', {
                state: current,
                action: { path: '/notes/opened.md', openMode: 'single' },
            });
            await settle();

            expect(host.markdown()).toBe('# Opened');
            expect(label()).toBe('/notes/opened.md');
        });

        it('that opened a folder empties the editor', async () => {
            await start(current);
            current = { ...current, mode: 'dir', file: null, dir: '/notes/project' };

            emit('editor:nav-open_path-succeeded', {
                state: current,
                action: { path: '/notes/project', openMode: 'dir' },
            });
            await settle();

            expect(host.markdown()).toBe('');
            expect(label()).toBe('Untitled');
        });
    });

    it('loads and empties with a fresh document each time, so undo cannot bring another file back', async () => {
        await start(current);
        click('[data-action="click->editor#newFile"]');

        // The emptying for the load (FRT-04), the load itself, then the one
        // New asked for — each replaces the document; CrepeHost always
        // flushes on replace() (crepe-host.test.ts).
        expect(host.replace).toHaveBeenCalledTimes(3);
    });

    it('New empties at once and asks the master to drop the current file', async () => {
        await start(current);

        click('[data-action="click->editor#newFile"]');

        expect(host.markdown()).toBe('');
        await settle();
        expect(calls('DELETE', '/editor/file')).toHaveLength(1);
        expect(current.file).toBeNull();
    });

    it('Save asks the master with the markdown and the revision it read, and is clean once answered', async () => {
        await start(current);
        host.type('# A, edited');
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
        host.type('# New');
        invoke.mockResolvedValue('/notes/new.md');

        click('[data-editor-target="saveAsButton"]');
        await settle();

        // The dialog opens in the current folder, in dir mode. The proposed
        // name and the filters come from the server's i18n (SET-09, lot 09).
        expect(invoke).toHaveBeenCalledWith('save_path', expect.objectContaining({ fileName: 'untitled.md', directory: '/notes' }));
        const savePathCall = invoke.mock.calls.find(([command]) => command === 'save_path')! as [string, { filters: unknown[] }];
        expect(savePathCall[1].filters).toEqual([
            { name: 'Markdown', extensions: ['md'] },
            { name: 'Text', extensions: ['txt'] },
        ]);
        expect(label()).toBe('/notes/new.md');
        expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.savedAs.replace('{name}', 'new.md') }]);
        expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
    });

    describe('the readonly toggle (UX-10, lot 10)', () => {
        it('follows the title, the pressed state and the hidden label through a toggle and back', async () => {
            await start(current);
            host.type('# A, edited');
            expect(saveButton().disabled).toBe(false);

            const button = $<HTMLButtonElement>('[data-editor-target="toggleButton"]');
            expect(button.title).toBe(EDITOR_I18N.toggle.readonly);
            expect(button.getAttribute('aria-pressed')).toBe('false');

            click('[data-editor-target="toggleButton"]');
            await settle();

            expect(button.title).toBe(EDITOR_I18N.toggle.edit);
            expect(button.getAttribute('aria-pressed')).toBe('true');
            expect($('[data-editor-target="toggleLabel"]').textContent).toBe(EDITOR_I18N.toggle.edit);
            expect(saveButton().disabled).toBe(true);

            click('[data-editor-target="toggleButton"]');
            await settle();

            expect(button.title).toBe(EDITOR_I18N.toggle.readonly);
            expect(button.getAttribute('aria-pressed')).toBe('false');
            expect($('[data-editor-target="toggleLabel"]').textContent).toBe(EDITOR_I18N.toggle.readonly);
            expect(saveButton().disabled).toBe(false);
        });
    });

    describe("the document's links (EDITOR_LINKS.md)", () => {
        /** A link as Crepe renders it, inside the editor's element. */
        const addLink = (href: string): HTMLAnchorElement => {
            const link = document.createElement('a');
            link.setAttribute('href', href);
            link.textContent = href;
            $('[data-controller="editor"]').append(link);

            return link;
        };
        const clickLink = (link: HTMLAnchorElement, ctrl = false): MouseEvent => {
            const event = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: ctrl });
            link.dispatchEvent(event);

            return event;
        };
        const askedPath = (): string =>
            (calls('POST', '/editor/file')[0]?.[1]!.body as FormData).get('path') as string;

        beforeEach(() => {
            files['TODO.md'] = '# TODO';
        });

        it('follows a plain click in read-only, and shows the document asked for', async () => {
            await start(current);
            click('[data-editor-target="toggleButton"]');
            await settle();

            const event = clickLink(addLink('TODO.md'));
            await settle();

            expect(event.defaultPrevented).toBe(true);
            expect(askedPath()).toBe('TODO.md');
            expect(host.markdown()).toBe('# TODO');
            expect(label()).toBe('TODO.md');
        });

        it('ignores a plain click in edit mode, follows a Ctrl+click', async () => {
            await start(current);
            const link = addLink('TODO.md');

            clickLink(link);
            await settle();
            expect(calls('POST', '/editor/file')).toHaveLength(0);

            clickLink(link, true);
            await settle();

            expect(askedPath()).toBe('TODO.md');
        });

        it('sends the path without its fragment, and leaves schemes and lone anchors to the browser', async () => {
            await start(current);
            click('[data-editor-target="toggleButton"]');
            await settle();

            const external = clickLink(addLink('https://example.com/doc.md'));
            const anchor = clickLink(addLink('#section'));
            await settle();

            expect(external.defaultPrevented).toBe(false);
            expect(anchor.defaultPrevented).toBe(false);
            expect(calls('POST', '/editor/file')).toHaveLength(0);

            const followed = clickLink(addLink('TODO.md#section'));
            await settle();

            expect(followed.defaultPrevented).toBe(true);
            expect(askedPath()).toBe('TODO.md');
        });

        it("sends the path decoded, and a malformed escape for the server's refusal", async () => {
            files['mon fichier.md'] = '# Mon fichier';
            await start(current);
            click('[data-editor-target="toggleButton"]');
            await settle();

            const encoded = clickLink(addLink('mon%20fichier.md'));
            await settle();

            expect(encoded.defaultPrevented).toBe(true);
            expect(askedPath()).toBe('mon fichier.md');

            // "%C3" alone is not a valid escape: it travels undecoded, for
            // the server to refuse what it cannot read.
            const malformed = clickLink(addLink('r%C3sum%C3.md'));
            await settle();

            expect(malformed.defaultPrevented).toBe(true);
            const lastAsk = calls('POST', '/editor/file').at(-1)![1]!.body as FormData;
            expect(lastAsk.get('path')).toBe('r%C3sum%C3.md');
        });

        it('carries the Ctrl state as a class, for the pointer cursor in edit mode', async () => {
            await start(current);
            const shell = $('[data-controller="editor"]');

            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Control', ctrlKey: true }));
            expect(shell.classList.contains('is-ctrl-down')).toBe(true);

            window.dispatchEvent(new KeyboardEvent('keyup', { key: 'Control', ctrlKey: false }));
            expect(shell.classList.contains('is-ctrl-down')).toBe(false);

            // Held, then a dialog or the window itself takes the focus: the
            // keyup never reaches the page, blur clears the state.
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Control', ctrlKey: true }));
            window.dispatchEvent(new Event('blur'));
            expect(shell.classList.contains('is-ctrl-down')).toBe(false);
        });

        it('catches up with a lost Ctrl keyup on the next mousemove', async () => {
            await start(current);
            const shell = $('[data-controller="editor"]');

            // Ctrl held, the keyup swallowed by the hub: the class stays.
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Control', ctrlKey: true }));
            expect(shell.classList.contains('is-ctrl-down')).toBe(true);

            // Any mouse move over the editor re-reads the modifier state,
            // and the cursor only matters once the mouse is over it anyway.
            shell.dispatchEvent(new MouseEvent('mousemove', { ctrlKey: false }));
            expect(shell.classList.contains('is-ctrl-down')).toBe(false);

            // And the mouse can hold Ctrl without the keyboard, too.
            shell.dispatchEvent(new MouseEvent('mousemove', { ctrlKey: true }));
            expect(shell.classList.contains('is-ctrl-down')).toBe(true);
        });

        it('asks the leave guard about unsaved changes, and follows only once accepted', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(current);
            host.type('# A, edited');

            clickLink(addLink('TODO.md'), true);
            await settle();

            expect(confirmDialog).toHaveBeenCalledTimes(1);
            expect(calls('POST', '/editor/file')).toHaveLength(0);
            // Refused: the document is still the one being edited.
            expect(host.markdown()).toBe('# A, edited');

            vi.mocked(confirmDialog).mockResolvedValue(true);
            clickLink(addLink('TODO.md'), true);
            await settle();

            expect(confirmDialog).toHaveBeenCalledTimes(2);
            expect(askedPath()).toBe('TODO.md');
        });
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
            host.type('');

            expect(saveButton().disabled).toBe(false);
            click('[data-editor-target="saveButton"]');
            await settle();

            expect(files['/notes/a.md']).toBe('');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        });

        it('stays grayed on a document with no path: Save as is the CTA for that', async () => {
            current = { ...current, file: null };
            await start(current);
            host.type('# New');

            expect(saveButton().disabled).toBe(true);
            expect(saveAsButton().disabled).toBe(false);
            // Even a click forced on the grayed button goes nowhere. The
            // close guard (lot 02) is the only one allowed to the hub here.
            click('[data-editor-target="saveButton"]');
            await settle();

            expect(invoke.mock.calls.filter(([command]) => command !== 'close_guard_context'
                && command !== 'close_guard_register' && command !== 'close_guard_remove')).toEqual([]);
            expect(calls('POST', '/document/save')).toHaveLength(0);
        });

        it('keeps the buttons disabled while a save is in flight, and ignores a second click', async () => {
            await start(current);
            host.type('# A, edited');
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
            host.type('# A, edited');

            click('[data-editor-target="saveButton"]');
            await settle();
            expect((calls('POST', '/document/save')[0][1]!.body as FormData).get('revision')).toBe('r0');

            host.type('# A, edited more');
            click('[data-editor-target="saveButton"]');
            await settle();

            expect((calls('POST', '/document/save')[1][1]!.body as FormData).get('revision')).toBe('r1');
        });

        it('on a 409, asks Save as or Overwrite instead of toasting, and Overwrite writes without a revision', async () => {
            vi.mocked(saveConflictDialog).mockResolvedValue('overwrite');
            await start(current);
            host.type('# A, edited');
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
            host.type('# A, edited');
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

    describe('printing and copying', () => {
        // Loading is not a change, so nothing calls back: both buttons used to
        // wake up only on the first keystroke, or on the empty paragraph
        // Milkdown's trailing plugin appends to a document that doesn't end
        // with one — a file ending on a paragraph stayed unprintable.
        it('enables both buttons on a loaded file, with no edit', async () => {
            await start(current);

            expect(printButton().disabled).toBe(false);
            expect(copyMarkdownButton().disabled).toBe(false);
        });

        it('disables both buttons again on an emptied document', async () => {
            await start(current);

            click('[data-action="click->editor#newFile"]');

            expect(printButton().disabled).toBe(true);
            expect(copyMarkdownButton().disabled).toBe(true);
        });

        it('printing mounts the copy the host renders and calls window.print()', async () => {
            await start(current);
            const copy = document.createElement('div');
            host.printCopy.mockReturnValue(copy);

            click('[data-editor-target="printButton"]');

            expect(copy.isConnected).toBe(true);
            expect(window.print).toHaveBeenCalledTimes(1);
        });

        it('mounts the copy on beforeprint, and stops listening once the editor is gone', async () => {
            await start(current);
            const copy = document.createElement('div');
            host.printCopy.mockReturnValue(copy);

            window.dispatchEvent(new Event('beforeprint'));

            expect(copy.isConnected).toBe(true);

            document.body.innerHTML = '';
            await settle();
            // disconnect() re-initializes the controller, so the host held now
            // is a fresh one: arm it, since a listener left behind would ask
            // that one for the copy.
            host.printCopy.mockReturnValue(copy);
            window.dispatchEvent(new Event('beforeprint'));

            expect(copy.isConnected).toBe(false);
        });

        it('copying the markdown goes through POST /document/copy and writes what the server renders, not the raw markdown', async () => {
            await start(current);
            host.type('# A, edited');

            click('[data-editor-target="copyMarkdownButton"]');
            await settle();

            expect((calls('POST', '/document/copy')[0][1]!.body as FormData).get('content')).toBe('# A, edited');
            expect(navigator.clipboard.writeText).toHaveBeenCalledWith('# A, edited (converted)');
            expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.copiedMarkdown }]);
        });
    });

    describe('intention or anomaly (lot 03)', () => {
        it('keeps the text and drops the path when its file is deleted with unsaved changes', async () => {
            await start(current);
            host.type('# A, edited');

            emit('editor:do-delete-succeeded', { state: { ...current, file: null }, action: { path: '/notes/a.md' } });

            expect(host.markdown()).toBe('# A, edited');
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

            expect(host.markdown()).toBe('# A');
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

            expect(host.markdown()).toBe('# A');
            expect(label()).toBe('Untitled');
            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.toast.currentFileGone.replace('{name}', 'a.md') }]);
        });

        it('same when asking again for the current file finds it gone', async () => {
            await start(current);

            emit('editor:nav-change_file-failed', { state: { ...current, file: null }, action: { path: '/notes/a.md' } });

            expect(host.markdown()).toBe('# A');
            expect(label()).toBe('Untitled');
        });

        it('a different file asked and gone is an intention: the editor empties', async () => {
            await start(current);

            emit('editor:nav-change_file-failed', { state: { ...current, file: null }, action: { path: '/notes/other.md' } });

            expect(host.markdown()).toBe('');
            expect(label()).toBe('Untitled');
        });
    });

    describe('the draft, across a reload (lot 04-brouillon.md)', () => {
        it('writes the draft as the document changes, and clears it back at the saved text', async () => {
            await start(current);
            host.type('# A, edited');

            expect(draft()).toEqual({ path: '/notes/a.md', markdown: '# A, edited', revision: 'r0' });

            host.type('# A');

            expect(draft()).toBeNull();
        });

        it('a successful save clears the draft', async () => {
            await start(current);
            host.type('# A, edited');

            click('[data-editor-target="saveButton"]');
            await settle();

            expect(draft()).toBeNull();
        });

        it('New clears the draft', async () => {
            await start(current);
            host.type('# A, edited');

            emit('editor:nav-new_file-requested', { action: {} });

            expect(draft()).toBeNull();
        });

        it('reading the file again for a keystroke during an in-flight save keeps the draft, with the new revision', async () => {
            await start(current);
            host.type('# A, edited');
            let answer!: (response: Response) => void;
            fetchMock.mockImplementation((url: string) => url === '/document/save'
                ? new Promise((resolve) => { answer = resolve; })
                : Promise.reject(new Error(`Unexpected ${url}`)));

            click('[data-editor-target="saveButton"]');
            await settle();
            host.type('# A, edited more');

            answer(jsonResponse({ state: current, action: { path: '/notes/a.md', revision: 'r1' } }));
            await settle();

            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
            expect(draft()).toEqual({ path: '/notes/a.md', markdown: '# A, edited more', revision: 'r1' });
        });

        it('restores a draft of the same revision at once, with a toast', async () => {
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: '/notes/a.md', markdown: '# A, edited', revision: 'r0' }));

            await start(current);

            expect(host.markdown()).toBe('# A, edited');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
            expect(saveButton().disabled).toBe(false);
            expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.draftRestored }]);
        });

        it('a different revision asks first, and Garder mon brouillon adopts the disk revision', async () => {
            vi.mocked(draftConflictDialog).mockResolvedValue('keep_draft');
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: '/notes/a.md', markdown: '# A, edited', revision: 'r_stale' }));

            await start(current);

            expect(draftConflictDialog).toHaveBeenCalledWith({
                question: EDITOR_I18N.draftConflict.question,
                keepDraftLabel: EDITOR_I18N.draftConflict.keepDraft,
                useDiskLabel: EDITOR_I18N.draftConflict.useDisk,
            });
            expect(host.markdown()).toBe('# A, edited');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);

            // Kept vs the stale revision, so the next Save conflicts with nothing.
            click('[data-editor-target="saveButton"]');
            await settle();

            expect((calls('POST', '/document/save')[0][1]!.body as FormData).get('revision')).toBe('r0');
        });

        it('a different revision keeps the stored draft while the dialog waits, so a reload then still finds it', async () => {
            vi.mocked(draftConflictDialog).mockReturnValue(new Promise(() => {}));
            const stored = { path: '/notes/a.md', markdown: '# A, edited', revision: 'r_stale' };
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify(stored));

            await start(current);

            expect(draftConflictDialog).toHaveBeenCalled();
            expect(host.markdown()).toBe('# A');
            expect(draft()).toEqual(stored);
        });

        it('a different revision, Reprendre la version du disque drops the draft', async () => {
            vi.mocked(draftConflictDialog).mockResolvedValue('use_disk');
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: '/notes/a.md', markdown: '# A, edited', revision: 'r_stale' }));

            await start(current);

            expect(host.markdown()).toBe('# A');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
            expect(draft()).toBeNull();
        });

        it('keeps the draft through a failed load, and restores it on Retry', async () => {
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: '/notes/a.md', markdown: '# A, edited', revision: 'r0' }));
            fetchMock.mockImplementation(async () => jsonResponse({}, 500));
            await start(current);

            expect(draft()).toEqual({ path: '/notes/a.md', markdown: '# A, edited', revision: 'r0' });

            server();
            click('[data-action="click->editor#retryLoad"]');
            await settle();

            expect(host.markdown()).toBe('# A, edited');
            expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.draftRestored }]);
        });

        it('leaving after a failed load drops the draft, and the next edits get their own', async () => {
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: '/notes/a.md', markdown: '# A, edited', revision: 'r0' }));
            fetchMock.mockImplementation(async () => jsonResponse({}, 500));
            await start(current);

            emit('editor:nav-new_file-requested', { action: {} });

            expect(draft()).toBeNull();

            host.type('# New');

            expect(draft()).toEqual({ path: null, markdown: '# New', revision: null });
        });

        it('restores an untitled draft when there is no current file', async () => {
            current = { ...current, file: null };
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: null, markdown: 'scratch', revision: null }));

            await start(current);

            expect(host.markdown()).toBe('scratch');
            expect(label()).toBe('Untitled');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
            expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.draftRestored }]);
        });

        it('a draft of a file gone by the first read restores as untitled instead of the not-found toast', async () => {
            delete files['/notes/a.md'];
            const resynced = vi.fn();
            on('editor:state-resynced', resynced);
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: '/notes/a.md', markdown: 'lost work', revision: 'r0' }));

            await start(current);
            await settle();

            expect(host.markdown()).toBe('lost work');
            expect(label()).toBe('Untitled');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
            expect(toasts).toEqual([{ type: 'success', message: EDITOR_I18N.toast.draftRestored }]);
            expect(resynced).toHaveBeenCalledWith({ state: { ...INITIAL, file: null }, anomaly: { file: '/notes/a.md' } });
        });

        it('abandons a draft of another path than the current file', async () => {
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: '/notes/other.md', markdown: 'x', revision: 'r0' }));

            await start(current);

            expect(host.markdown()).toBe('# A');
            expect(toasts).toEqual([]);
            expect(draft()).toBeNull();
        });

        it('ignores an unreadable draft and loads normally', async () => {
            sessionStorage.setItem(DRAFT_KEY, '{not json');

            await start(current);

            expect(host.markdown()).toBe('# A');
            expect(toasts).toEqual([]);
        });

        it('keeps working when sessionStorage throws', async () => {
            vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
                throw new Error('unavailable');
            });
            vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
                throw new Error('unavailable');
            });

            await start(current);
            host.type('# A, edited');

            expect(host.markdown()).toBe('# A, edited');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
        });
    });

    describe('editor:ready (lot 4b)', () => {
        it('is emitted once, free, on an editor with no file and no draft', async () => {
            current = { ...current, file: null };
            const ready = vi.fn();
            const stop = on('editor:ready', ready);

            await start(current);

            expect(ready).toHaveBeenCalledTimes(1);
            expect(ready).toHaveBeenCalledWith({ free: true });
            stop();
        });

        it('is not free when an untitled draft was restored', async () => {
            current = { ...current, file: null };
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: null, markdown: 'scratch', revision: null }));
            const ready = vi.fn();
            const stop = on('editor:ready', ready);

            await start(current);

            expect(ready).toHaveBeenCalledTimes(1);
            expect(ready).toHaveBeenCalledWith({ free: false });
            stop();
        });

        it('is emitted after the current file has landed, free on a clean document', async () => {
            const ready = vi.fn();
            const stop = on('editor:ready', ready);

            await start(current);
            expect(host.markdown()).toBe('# A');

            expect(ready).toHaveBeenCalledTimes(1);
            expect(ready).toHaveBeenCalledWith({ free: true });
            stop();
        });

        it('waits for a draft dialog to settle before saying whether the space is free', async () => {
            vi.mocked(draftConflictDialog).mockReturnValue(new Promise(() => {}));
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ path: '/notes/a.md', markdown: '# A, edited', revision: 'r_stale' }));
            const ready = vi.fn();
            const stop = on('editor:ready', ready);

            await start(current);

            expect(ready).not.toHaveBeenCalled();
            stop();
        });

        it('is not re-emitted by the loads that follow', async () => {
            files['/notes/b.md'] = '# B';
            const ready = vi.fn();
            const stop = on('editor:ready', ready);

            await start(current);
            current = { ...current, file: '/notes/b.md' };
            emit('editor:nav-change_file-succeeded', { state: current, action: { path: '/notes/b.md' } });
            await settle();

            expect(host.markdown()).toBe('# B');
            expect(ready).toHaveBeenCalledTimes(1);
            stop();
        });
    });

    describe('a load that fails (FRT-04)', () => {
        it('shows an explicit error state with Retry, not the old document', async () => {
            fetchMock.mockImplementation(async () => jsonResponse({ genericErrors: ['Open failed'] }, 500));
            await start(current);

            expect(host.markdown()).toBe('');
            expect($('[data-editor-target="loadErrorMessage"]').textContent).toBe('Open failed');
            expect($('[data-controller="editor"]').classList.contains('is-load-failed')).toBe(true);
            expect(saveButton().disabled).toBe(true);
            expect(saveAsButton().disabled).toBe(true);
            expect(toasts).toEqual([]);

            // Retry: the same read, the same intention.
            server();
            click('[data-action="click->editor#retryLoad"]');
            await settle();

            expect(host.markdown()).toBe('# A');
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
        expect(host.markdown()).toBe('# A');

        // An anomaly (lot 03): the text stays, only the path falls — and it
        // counts as unsaved, its copy on disk having just gone.
        emit('editor:do-delete-succeeded', { state: { ...current, file: null }, action: { path: '/notes/b.md' } });
        expect(host.markdown()).toBe('# A');
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

        expect(host.markdown()).toBe('');
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

        expect(host.markdown()).toBe('');
        expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.toast.fileNotFound }]);
    });

    describe('the AI, following the state', () => {
        const withAi = (aiEnabled: boolean): EditorState => ({ ...current, ai_enabled: aiEnabled });
        const creations = (): CrepeBuild[] => host.builds;

        it('creates Crepe with the AI as the state has it at load', async () => {
            await start(withAi(false));
            expect(creations().map((build) => build.aiEnabled)).toEqual([false]);

            await unmount(application);
            await start(withAi(true));
            expect(creations().map((build) => build.aiEnabled)).toEqual([true]);
        });

        it.each([
            ['do-save_settings-succeeded', {}],
            ['do-set_key-succeeded', { name: 'mistral' }],
            ['do-delete_key-succeeded', { name: 'mistral' }],
        ] as const)('%s turning the AI on recreates Crepe around the same markdown, with no confirmation', async (name, action) => {
            await start(withAi(false));
            host.type('# A, edited');

            emit(`editor:${name}`, { state: withAi(true), action } as never);
            await settle();

            expect(creations()).toEqual([
                { aiEnabled: false, markdown: '' },
                { aiEnabled: true, markdown: '# A, edited' },
            ]);
            expect(confirmDialog).not.toHaveBeenCalled();
            // What was on screen is still there: the file, and the unsaved state.
            expect(host.markdown()).toBe('# A, edited');
            expect(label()).toBe('/notes/a.md');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(false);
        });

        it('turning the AI off recreates it without', async () => {
            await start(withAi(true));

            emit('editor:do-delete_key-succeeded', { state: withAi(false), action: { name: 'anthropic' } });
            await settle();

            expect(creations().map((build) => build.aiEnabled)).toEqual([true, false]);
        });

        it('does nothing when the state agrees with what Crepe was created with', async () => {
            await start(withAi(true));

            emit('editor:do-save_settings-succeeded', { state: withAi(true), action: {} });
            await settle();

            expect(creations()).toHaveLength(1);
        });

        it('a clean document stays clean even if Crepe serializes it differently the second time', async () => {
            await start(withAi(false));
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
            serialize = (markdown) => `${markdown}\n`;

            emit('editor:do-set_key-succeeded', { state: withAi(true), action: { name: 'mistral' } });
            await settle();

            expect(host.markdown()).toBe('# A\n');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        });

        it('changes met one after the other are handled one at a time, the last one wins', async () => {
            await start(withAi(false));

            emit('editor:do-set_key-succeeded', { state: withAi(true), action: { name: 'mistral' } });
            emit('editor:do-delete_key-succeeded', { state: withAi(false), action: { name: 'mistral' } });
            await settle();

            // The serial ordering itself is CrepeHost's own (crepe-host.test.ts):
            // here, only that the controller asks for both, in order.
            expect(host.recreate.mock.calls.map((call) => call[0] as boolean)).toEqual([true, false]);
        });

        it('Save still works on the new Crepe, with its markdown', async () => {
            await start(withAi(false));
            emit('editor:do-save_settings-succeeded', { state: withAi(true), action: {} });
            await settle();
            host.type('# A, edited');

            click('[data-editor-target="saveButton"]');
            await settle();

            const [, init] = calls('POST', '/document/save')[0];
            expect((init!.body as FormData).get('content')).toBe('# A, edited');
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        });

        it('discards the AI when the current file is deleted, even mid-generation', async () => {
            await start(withAi(true));

            emit('editor:do-delete-succeeded', { state: withAi(true), action: { path: '/notes/a.md' } });

            expect(host.discardAi).toHaveBeenCalled();
        });

        it('the leave guard also sees a Crepe session in progress, not just streaming and diff', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(withAi(true));
            // The session read in test mode: the command would abort (IA-04).
            host.isAiBusy.mockReturnValue(true);

            click('[data-mode="dir"]');
            await settle();

            expect(confirmDialog).toHaveBeenCalledTimes(1);
            expect(calls('POST', '/editor/mode')).toHaveLength(0);
        });
    });

    describe('a teardown before the state arrives', () => {
        it('tears the host down without throwing', async () => {
            const errors: unknown[] = [];
            // No editor-state controller registered: the outlet never
            // connects, so connect() is still waiting on the state.
            application = await mount(masterHtml(current, editorHtml()), { 'editor': EditorController });
            application.handleError = (error) => errors.push(error);
            // disconnect() re-initializes the controller, which builds a new
            // host: the torn-down one is the one held now.
            const torn = host;

            document.body.innerHTML = '';
            await settle();

            expect(errors).toEqual([]);
            expect(torn.destroy).toHaveBeenCalledTimes(1);
            expect(torn.create).not.toHaveBeenCalled();
        });
    });

    describe('the leave guard', () => {
        it('lets nothing through while unsaved work is not given up', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(current);
            host.type('# A, edited');

            click('[data-mode="dir"]');
            await settle();

            expect(confirmDialog).toHaveBeenCalledTimes(1);
            expect(calls('POST', '/editor/mode')).toHaveLength(0);
            expect($('[data-mode="single"]').classList.contains('is-active')).toBe(true);
        });

        it('replays the click once confirmed, and the request goes out', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(true);
            await start(current);
            host.type('# A, edited');

            click('[data-mode="dir"]');
            await settle();

            expect(calls('POST', '/editor/mode')).toHaveLength(1);
            expect(host.markdown()).toBe('');
        });

        it('asks nothing with nothing to lose', async () => {
            await start(current);

            click('[data-mode="dir"]');
            await settle();

            expect(confirmDialog).not.toHaveBeenCalled();
            expect(calls('POST', '/editor/mode')).toHaveLength(1);
        });
    });

    describe("the hub's close guard (lot 02)", () => {
        const closeGuardCalls = (command: string): unknown[][] => invoke.mock.calls.filter(([called]) => called === command);

        it('registers once a keystroke makes the document dirty, and never again while it stays dirty', async () => {
            await start(current);

            host.type('# A, edited');
            await settle();
            expect(invoke).toHaveBeenCalledWith('close_guard_register', expect.objectContaining({ id: 'editor' }));

            host.type('# A, edited again');
            await settle();
            expect(closeGuardCalls('close_guard_register')).toHaveLength(1);
        });

        it('removes the guard when the document is saved', async () => {
            await start(current);
            host.type('# A, edited');
            await settle();

            click('[data-editor-target="saveButton"]');
            await settle();

            expect(invoke).toHaveBeenCalledWith('close_guard_remove', expect.objectContaining({ id: 'editor' }));
        });

        it('follows the AI busy state on a clean document too', async () => {
            await start(current);

            host.aiBusy(true);
            await settle();
            expect(invoke).toHaveBeenCalledWith('close_guard_register', expect.objectContaining({ id: 'editor' }));

            host.aiBusy(false);
            await settle();
            expect(invoke).toHaveBeenCalledWith('close_guard_remove', expect.objectContaining({ id: 'editor' }));
        });

        it('removes the guard when the editor is torn down', async () => {
            await start(current);
            host.type('# A, edited');
            await settle();
            invoke.mockClear();

            document.body.innerHTML = '';
            await settle();

            expect(invoke).toHaveBeenCalledWith('close_guard_remove', expect.objectContaining({ id: 'editor' }));
        });
    });

    describe('an image picked (FRT-02 + FRT-08 + HUB-06, lot 08)', () => {
        /** What Crepe's Image entries run: the controller's picker. */
        const insertImage = (origin: 'slash-menu' | 'top-bar'): void => hostCallbacks!.onInsertImage(origin);

        it('asks the picker for images only, under its translated label', async () => {
            invoke.mockResolvedValue(null);
            await start(current);

            insertImage('top-bar');
            await settle();

            expect(invoke).toHaveBeenCalledWith('pick_path', {
                kind: 'file',
                filters: [{ name: EDITOR_I18N.imageFilter, extensions: IMAGE_EXTENSIONS }],
            });
        });

        it('lands from the top bar without clearing, once the route agrees', async () => {
            imageAnswer = 'ok';
            invoke.mockResolvedValue('/img/pic.png');
            await start(current);

            insertImage('top-bar');
            await settle();

            expect(fetchMock.mock.calls.some(([url, init]) => url === '/document/image?path=%2Fimg%2Fpic.png' && init?.method === 'HEAD')).toBe(true);
            expect(host.insertImage).toHaveBeenCalledWith('/document/image?path=%2Fimg%2Fpic.png');
            expect(host.insertImageFromSlashMenu).not.toHaveBeenCalled();
        });

        it('lands from the slash menu through the command-clearing path', async () => {
            imageAnswer = 'ok';
            invoke.mockResolvedValue('/img/pic.png');
            await start(current);

            insertImage('slash-menu');
            await settle();

            expect(host.insertImageFromSlashMenu).toHaveBeenCalledWith('/document/image?path=%2Fimg%2Fpic.png');
            expect(host.insertImage).not.toHaveBeenCalled();
        });

        it('a refusal of the route inserts nothing and says why', async () => {
            await start(current);

            insertImage('top-bar');
            await settle();

            expect(host.insertImage).not.toHaveBeenCalled();
            expect(host.insertImageFromSlashMenu).not.toHaveBeenCalled();
            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.imageInvalid }]);
        });

        it('a network error inserts nothing and says why', async () => {
            imageAnswer = 'network';
            await start(current);

            insertImage('top-bar');
            await settle();

            expect(host.insertImage).not.toHaveBeenCalled();
            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.imageInvalid }]);
        });

        it('a cancelled picker inserts nothing, toasts nothing', async () => {
            invoke.mockResolvedValue(null);
            await start(current);

            insertImage('top-bar');
            await settle();

            expect(fetchMock.mock.calls.some(([url, init]) => url.startsWith('/document/image') && init?.method === 'HEAD')).toBe(false);
            expect(host.insertImage).not.toHaveBeenCalled();
            expect(toasts).toEqual([]);
        });

        it('without IPC, says the hub is required and inserts nothing', async () => {
            delete window.__TAURI__;
            await start(current);

            insertImage('top-bar');
            await settle();

            expect(host.insertImage).not.toHaveBeenCalled();
            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.ipc.unavailable }]);
        });

        it('a rejected invoke says the picker failed', async () => {
            invoke.mockRejectedValue(new Error('no window'));
            await start(current);

            insertImage('top-bar');
            await settle();

            expect(host.insertImage).not.toHaveBeenCalled();
            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.ipc.rejected }]);
        });
    });

    describe('Save as through the picker (HUB-06, lot 08)', () => {
        it('without IPC, says the hub is required, the button comes back, nothing is saved', async () => {
            delete window.__TAURI__;
            await start(current);

            click('[data-editor-target="saveAsButton"]');
            await settle();

            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.ipc.unavailable }]);
            expect(saveAsButton().disabled).toBe(false);
            expect(calls('POST', '/document/save')).toHaveLength(0);
        });

        it('a rejected invoke says the picker failed, the button comes back', async () => {
            invoke.mockRejectedValue(new Error('no window'));
            await start(current);

            click('[data-editor-target="saveAsButton"]');
            await settle();

            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.ipc.rejected }]);
            expect(saveAsButton().disabled).toBe(false);
            expect(calls('POST', '/document/save')).toHaveLength(0);
        });

        it('a cancelled picker saves nothing, toasts nothing', async () => {
            invoke.mockResolvedValue(null);
            await start(current);

            click('[data-editor-target="saveAsButton"]');
            await settle();

            expect(toasts).toEqual([]);
            expect(calls('POST', '/document/save')).toHaveLength(0);
        });
    });

    describe('a creation of Crepe that fails (FRT-11, lot 08)', () => {
        it('shows the error toast instead of an empty editor, and poses no listener', async () => {
            vi.mocked(CrepeHost).mockImplementation(function () {
                host = fakeHost();
                host.create.mockRejectedValue(new Error('no engine'));
                hostCallbacks = null;

                return host as never;
            });

            await start(current);

            expect(toasts).toEqual([{ type: 'error', message: EDITOR_I18N.initFailed }]);
            // Not a single window listener: Ctrl+S goes nowhere.
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true, cancelable: true }));
            await settle();

            expect(calls('POST', '/document/save')).toHaveLength(0);
        });

        it('a teardown while Crepe is being created stops there: nothing is posed after', async () => {
            let resolveCreate: (() => void) | null = null;
            vi.mocked(CrepeHost).mockImplementation(function () {
                host = fakeHost();
                // Only the first build waits: the re-initialization after the
                // teardown would otherwise swallow the resolver.
                if (resolveCreate === null) {
                    host.create.mockReturnValue(new Promise<void>((resolve) => (resolveCreate = resolve)));
                }
                hostCallbacks = null;

                return host as never;
            });

            await start(current);
            document.body.innerHTML = '';
            await settle();
            resolveCreate!();
            await settle();

            expect(toasts).toEqual([]);
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true, cancelable: true }));
            await settle();

            expect(calls('POST', '/document/save')).toHaveLength(0);
        });
    });

    describe('a failed change of file (FRT-03, lot 08)', () => {
        it('loads the file the failure left the state in', async () => {
            await start(current);
            expect(host.markdown()).toBe('# A');

            // The master merges the failed change's state: the server's
            // session answers the reload with that file.
            files['/notes/b.md'] = '# B';
            current = { ...current, file: '/notes/b.md' };
            emit('editor:nav-change_file-failed', { state: current, action: { path: '/notes/b.md' } });
            await settle();

            expect(host.markdown()).toBe('# B');
            expect(label()).toBe('/notes/b.md');
        });
    });

    describe('the Ctrl+S and Ctrl+N shortcuts (FRT-10 + UX-03, lot 08)', () => {
        const key = (k: string): KeyboardEvent => {
            const event = new KeyboardEvent('keydown', { key: k, ctrlKey: true, cancelable: true });
            window.dispatchEvent(event);

            return event;
        };

        it('Ctrl+S on a dirty document asks for a save', async () => {
            await start(current);
            host.type('# A, edited');

            key('s');
            await settle();

            expect(calls('POST', '/document/save')).toHaveLength(1);
            expect($('[data-editor-target="dirtyIndicator"]').hidden).toBe(true);
        });

        it('Ctrl+S works with Caps Lock on', async () => {
            await start(current);
            host.type('# A, edited');

            key('S');
            await settle();

            expect(calls('POST', '/document/save')).toHaveLength(1);
        });

        it('Ctrl+S with Save disabled does nothing, but is still prevented', async () => {
            await start(current);

            const event = key('s');
            await settle();

            expect(event.defaultPrevented).toBe(true);
            expect(calls('POST', '/document/save')).toHaveLength(0);
        });

        it('Ctrl+N on a dirty document goes through the leave guard', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(current);
            host.type('# A, edited');

            key('n');
            await settle();

            expect(confirmDialog).toHaveBeenCalledTimes(1);
            expect(calls('DELETE', '/editor/file')).toHaveLength(0);
        });

        it('nothing happens while a dialog is open, but the combination is still prevented', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(current);
            host.type('# A, edited');
            const dialog = document.createElement('dialog');
            dialog.open = true;
            document.body.append(dialog);

            const event = key('n');
            await settle();

            expect(event.defaultPrevented).toBe(true);
            expect(confirmDialog).not.toHaveBeenCalled();
            expect(calls('DELETE', '/editor/file')).toHaveLength(0);
        });

        it('the listener is gone once the editor is disconnected', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);
            await start(current);
            host.type('# A, edited');
            await unmount(application);

            key('n');
            await settle();

            expect(confirmDialog).not.toHaveBeenCalled();
        });
    });
});
