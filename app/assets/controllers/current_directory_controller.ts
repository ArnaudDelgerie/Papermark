import { Controller } from '@hotwired/stimulus';
import { emit, on } from '../editor/events';
import { pickPath } from '../utils/tauri';

/**
 * Open folder, and the current folder's path. It picks a folder and asks the
 * master to make it the current one; the label follows the state it answers
 * with. Whoever shows the folder's contents listens on its own.
 */
export default class extends Controller {
    static targets = ['openButton', 'path'];

    declare readonly openButtonTarget: HTMLButtonElement;
    declare readonly pathTarget: HTMLElement;

    #unsubscribers: Array<() => void> = [];

    connect(): void {
        this.#unsubscribers = [
            on('editor:nav-change_dir-requested', () => this.#busy(true)),
            on('editor:nav-change_dir-succeeded', ({ state }) => this.#settle(state.dir)),
            on('editor:nav-change_dir-failed', ({ state }) => this.#settle(state.dir)),
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
        const path = await pickPath('directory');
        if (path === null) {
            return;
        }

        emit('editor:nav-change_dir-requested', { action: { path } });
    }

    #settle(dir: string | null): void {
        this.#show(dir);
        this.#busy(false);
    }

    #show(dir: string | null): void {
        this.pathTarget.textContent = dir ?? '';
        this.pathTarget.title = dir ?? '';
        this.pathTarget.hidden = dir === null;
    }

    #busy(busy: boolean): void {
        this.openButtonTarget.disabled = busy;
        this.openButtonTarget.classList.toggle('is-loading', busy);
    }
}
