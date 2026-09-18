import { Controller } from '@hotwired/stimulus';
import { on } from '../editor/events';
import { FileEntries, type FileEntryI18n, entryPath } from '../editor/file-entries';
import { onModeShown } from '../editor/mode-shown';
import { showLoading } from '../utils/sidebar-loading';

/**
 * The dir column: the tree of the current folder, behind a Turbo frame. It
 * doesn't change the folder — current-directory does — so it only listens:
 * loader on the request, frame reload once answered. It also reloads when a
 * file of the current folder may have appeared, gone or changed name,
 * whoever acted on it — not for a file elsewhere, nor on a mode switch: the
 * tree only depends on the folder. In-place updates come later (S9,
 * EDITOR_TS_MIGRATION.md).
 */
export default class extends Controller<HTMLElement> {
    static values = { i18n: Object };
    static targets = ['treeFrame'];

    declare readonly i18nValue: FileEntryI18n;
    declare readonly treeFrameTarget: TurboFrameElement;

    #entries = new FileEntries(() => this.i18nValue);
    #unsubscribers: Array<() => void> = [];

    connect(): void {
        this.#entries.connect();
        const reload = (): void => void this.treeFrameTarget.reload();
        const reloadIfIn = (dir: string | null, path: string): void => {
            if (isIn(dir, path)) {
                reload();
            }
        };
        this.#unsubscribers = [
            onModeShown((mode) => {
                this.element.hidden = mode !== 'dir';
            }),
            // Up before the request leaves: the wait shows from the first
            // moment, not once the frame turns busy.
            on('editor:nav-change_dir-requested', () => showLoading(this.treeFrameTarget)),
            // Also on failure, to leave the loader: the session still holds
            // the previous folder. A reload cancels the frame's own request
            // still in flight (Turbo 8).
            on('editor:nav-change_dir-succeeded', reload),
            on('editor:nav-change_dir-failed', reload),
            on('editor:do-save_as-succeeded', ({ state, action }) => reloadIfIn(state.dir, action.path)),
            on('editor:do-delete-succeeded', ({ state, action }) => reloadIfIn(state.dir, action.path)),
            // Renaming stays in the same folder: the old path tells.
            on('editor:do-rename-succeeded', ({ state, action }) => reloadIfIn(state.dir, action.oldPath)),
            on('editor:state-resynced', ({ anomaly }) => {
                if ('dir' in anomaly) {
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
