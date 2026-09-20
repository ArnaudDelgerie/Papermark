import { confirmDialog } from '../utils/confirm-dialog';
import { renameDialog } from '../utils/rename-dialog';
import { showToast } from '../utils/toast';
import { emit, on } from './events';

export interface FileEntryI18n {
    rename: string;
    delete: string;
    renamePrompt: string;
    deleteConfirmMessage: string;
    /** When the target is the current file and it has unsaved changes (lot 03). */
    deleteConfirmMessageCurrent: string;
    deleteConfirmQuestion: string;
    deleted: string;
    renamed: string;
}

/**
 * Open, delete and rename a file entry: shared by mode-single's history and
 * mode-dir's tree. Only what comes before the request lives here (the
 * confirmation, the rename dialog); the master writes. Both columns hear the
 * same `…-succeeded`, so the success toast is shown by the one that asked —
 * failures are toasted by the master (S7).
 */
export class FileEntries {
    readonly #i18n: () => FileEntryI18n;
    readonly #asked = new Set<string>();
    #unsubscribers: Array<() => void> = [];

    constructor(i18n: () => FileEntryI18n) {
        this.#i18n = i18n;
    }

    connect(): void {
        this.#unsubscribers = [
            on('editor:do-delete-succeeded', ({ action }) => this.#answered(`delete:${action.path}`, this.#i18n().deleted)),
            on('editor:do-delete-failed', ({ action }) => this.#answered(`delete:${action.path}`, null)),
            on('editor:do-rename-succeeded', ({ action }) => this.#answered(`rename:${action.oldPath}`, this.#i18n().renamed)),
            on('editor:do-rename-failed', ({ action }) => this.#answered(`rename:${action.path}`, null)),
        ];
    }

    disconnect(): void {
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
        this.#asked.clear();
    }

    open(path: string): void {
        emit('editor:nav-change_file-requested', { action: { path } });
    }

    async delete(path: string): Promise<void> {
        const i18n = this.#i18n();
        // One question for both things (lot 03): the leave guard is not on
        // these buttons, it would ask twice in a row. The editor answers
        // synchronously — `unsaved` is read back once the event returned.
        const query: { path: string; unsaved: boolean } = { path, unsaved: false };
        emit('editor:unsaved-file-query', query);
        const confirmed = await confirmDialog({
            message: query.unsaved ? i18n.deleteConfirmMessageCurrent : i18n.deleteConfirmMessage,
            question: i18n.deleteConfirmQuestion.replace('{name}', basename(path)),
            continueLabel: i18n.delete,
        });
        if (!confirmed) {
            return;
        }

        this.#asked.add(`delete:${path}`);
        emit('editor:do-delete-requested', { action: { path } });
    }

    async rename(path: string): Promise<void> {
        const i18n = this.#i18n();
        const currentName = basename(path);
        const name = await renameDialog({
            currentName,
            message: i18n.renamePrompt.replace('{name}', currentName),
            continueLabel: i18n.rename,
        });
        if (name === null) {
            return;
        }

        this.#asked.add(`rename:${path}`);
        emit('editor:do-rename-requested', { action: { path, name } });
    }

    #answered(key: string, success: string | null): void {
        if (this.#asked.delete(key) && success !== null) {
            showToast('success', success);
        }
    }
}

export function basename(path: string): string {
    return path.split('/').pop() ?? path;
}

/** The path an entry's link or button carries in `data-path`. */
export function entryPath(event: Event): string {
    return (event.currentTarget as HTMLElement).dataset.path ?? '';
}
