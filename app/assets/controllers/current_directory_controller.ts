import { Controller } from '@hotwired/stimulus';
import { emit, on } from '../editor/events';
import { showPath } from '../utils/path-label';
import { type IpcI18n, pickPath } from '../utils/tauri';

/** The component's own texts. */
export interface CurrentDirectoryI18n {
    ipc: IpcI18n;
}

/**
 * Open folder, and the current folder's path. It picks a folder and asks the
 * master to make it the current one; the label follows the state it answers
 * with. Whoever shows the folder's contents listens on its own.
 */
export default class extends Controller {
    static targets = ['openButton', 'path'];
    static values = { i18n: Object };

    declare readonly openButtonTarget: HTMLButtonElement;
    declare readonly pathTarget: HTMLElement;
    declare readonly i18nValue: CurrentDirectoryI18n;

    #unsubscribers: Array<() => void> = [];

    connect(): void {
        // The server writes the whole path; cut from its start from here on.
        if (!this.pathTarget.hidden) {
            this.#show(this.pathTarget.textContent);
        }
        this.#unsubscribers = [
            on('editor:nav-change_dir-requested', () => this.#busy(true)),
            on('editor:nav-change_dir-succeeded', ({ state }) => this.#settle(state.dir)),
            on('editor:nav-change_dir-failed', ({ state }) => this.#settle(state.dir)),
            // An archive or an open_path that opened a folder is a change of folder.
            on('editor:do-import-succeeded', ({ state, action }) => {
                if (action.openMode === 'dir') {
                    this.#settle(state.dir);
                }
            }),
            on('editor:nav-open_path-succeeded', ({ state, action }) => {
                if (action.openMode === 'dir') {
                    this.#settle(state.dir);
                }
            }),
            on('editor:state-resynced', ({ state, anomaly }) => {
                if ('dir' in anomaly) {
                    this.#show(state.dir);
                }
            }),
        ];
    }

    disconnect(): void {
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
    }

    async change(): Promise<void> {
        // Down from the picker to the change's answer: #busy() covers the
        // request on its own, the picker part ends here (FRT-07, lot 08) —
        // the wrapper never throws, cancelled or failed alike.
        this.#busy(true);
        const path = await pickPath('directory', this.i18nValue.ipc);
        if (path === null) {
            this.#busy(false);

            return;
        }

        emit('editor:nav-change_dir-requested', { action: { path } });
    }

    #settle(dir: string | null): void {
        this.#show(dir);
        this.#busy(false);
    }

    #show(dir: string | null): void {
        showPath(this.pathTarget, dir ?? '');
        this.pathTarget.hidden = dir === null;
    }

    #busy(busy: boolean): void {
        this.openButtonTarget.disabled = busy;
        this.openButtonTarget.classList.toggle('is-loading', busy);
    }
}
