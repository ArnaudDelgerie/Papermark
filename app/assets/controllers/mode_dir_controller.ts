import { Controller } from '@hotwired/stimulus';
import { emit, on, requested } from '../editor/events';
import { FileEntries, type FileEntryI18n, entryPath } from '../editor/file-entries';
import { onModeShown } from '../editor/mode-shown';

/**
 * The dir column: the tree of the current folder, behind a Turbo frame. It
 * doesn't change the folder — current-directory does — so it mostly listens:
 * emptied then reloaded once the folder changed. It also reloads when a
 * file of the current folder may have appeared, gone or changed name,
 * whoever acted on it — not for a file elsewhere, nor on a mode switch: the
 * tree only depends on the folder. The frame reloads with morphing
 * (refresh="morph"): only the entries that changed are touched, and the
 * folders keep the open/closed state the user gave them.
 */
export default class extends Controller<HTMLElement> {
    static values = { i18n: Object };
    static targets = ['treeFrame', 'refreshButton', 'toolbar'];

    declare readonly i18nValue: FileEntryI18n;
    declare readonly treeFrameTarget: TurboFrameElement;
    declare readonly refreshButtonTarget: HTMLButtonElement;
    declare readonly toolbarTarget: HTMLElement;

    #entries = new FileEntries(() => this.i18nValue);
    #unsubscribers: Array<() => void> = [];
    /** Between the refresh request and its answer. */
    #refreshing = false;
    /** The load in flight, 0 when none; see #track(). */
    #loading = 0;
    #loads = 0;

    connect(): void {
        this.#entries.connect();
        // The frame starts empty and loads by itself (eager): the button
        // turns until the first tree is in, as after a folder change.
        const frame = this.treeFrameTarget;
        if (!frame.hasAttribute('complete') && frame.loaded) {
            this.#track(frame.loaded);
        }
        const reload = (): void => this.#reload();
        const reloadIfIn = (dir: string | null, path: string): void => {
            if (isIn(dir, path)) {
                reload();
            }
        };
        this.#unsubscribers = [
            onModeShown((mode) => {
                this.element.hidden = mode !== 'dir';
            }),
            // The old tree goes as the new path shows, the refresh button
            // turns until the new one is in. Nothing to morph from: a new
            // folder shares no entry with the old one. On failure the old
            // tree is still the right one, nothing to do. A reload cancels
            // the frame's own request still in flight (Turbo 8).
            on('editor:nav-change_dir-succeeded', ({ state }) => {
                this.toolbarTarget.hidden = state.dir === null;
                this.treeFrameTarget.replaceChildren();
                reload();
            }),
            on('editor:do-save_as-succeeded', ({ state, action }) => reloadIfIn(state.dir, action.path)),
            on('editor:do-delete-succeeded', ({ state, action }) => reloadIfIn(state.dir, action.path)),
            // Renaming stays in the same folder: the old path tells.
            on('editor:do-rename-succeeded', ({ state, action }) => reloadIfIn(state.dir, action.oldPath)),
            on('editor:nav-refresh_dir-requested', () => {
                this.#refreshing = true;
                this.#showBusy();
            }),
            on('editor:nav-refresh_dir-succeeded', () => {
                this.#refreshing = false;
                reload();
            }),
            on('editor:nav-refresh_dir-failed', () => {
                this.#refreshing = false;
                this.#showBusy();
            }),
            on('editor:state-resynced', ({ state, anomaly }) => {
                if ('dir' in anomaly) {
                    this.toolbarTarget.hidden = state.dir === null;
                    reload();
                }
            }),
        ];
    }

    disconnect(): void {
        this.#entries.disconnect();
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
    }

    /** Walks the folder again: the tree only follows the in-app changes. */
    refresh(): void {
        emit(requested('nav-refresh_dir'), { action: {} });
    }

    openFile(event: Event): void {
        event.preventDefault();
        this.#entries.open(entryPath(event));
    }

    async deleteEntry(event: Event): Promise<void> {
        event.preventDefault();
        await this.#entries.delete(entryPath(event));
    }

    async renameEntry(event: Event): Promise<void> {
        event.preventDefault();
        await this.#entries.rename(entryPath(event));
    }

    /**
     * The server renders every folder closed: a morph would close the ones
     * the user opened. Folders that appear come closed, those that stay keep
     * their state.
     */
    keepFolderState(event: CustomEvent<{ attributeName: string }>): void {
        if (event.target instanceof HTMLDetailsElement && event.detail.attributeName === 'open') {
            event.preventDefault();
        }
    }

    #reload(): void {
        this.#track(this.treeFrameTarget.reload());
    }

    /**
     * The refresh button turns until the tree has been rendered, whatever the
     * load is for: when nothing changed, that is the only sign it happened.
     * The frame's own `busy` ends too early for this — Turbo drops it once
     * the response headers are in, before the body is read and morphed —
     * while the promise of a load settles after the render. A load cancelled
     * by the next one never settles: only the last one clears.
     */
    #track(load: Promise<void>): void {
        const id = ++this.#loads;
        this.#loading = id;
        this.#showBusy();
        void load.finally(() => {
            if (this.#loading === id) {
                this.#loading = 0;
                this.#showBusy();
            }
        });
    }

    #showBusy(): void {
        const busy = this.#refreshing || this.#loading !== 0;
        this.refreshButtonTarget.disabled = busy;
        this.refreshButtonTarget.classList.toggle('is-refreshing', busy);
    }
}

/**
 * Whether `path` is somewhere under `dir`. Plain string prefix: both come
 * realpath'd from the server (`state.dir`, the paths of `action`).
 */
function isIn(dir: string | null, path: string): boolean {
    if (dir === null) {
        return false;
    }

    return path.startsWith(dir.endsWith('/') ? dir : `${dir}/`);
}
