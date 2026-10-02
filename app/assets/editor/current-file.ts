import { on } from './events';

/**
 * Follows the file the editor shows (UX-09, lot 10): the one asked for at
 * once, then the one the state ends up with, success or failure (S6), and
 * every later move — Save as, an import, an open_path, a resync after
 * something went gone. Returns the unsubscriber.
 */
export function onCurrentFile(handler: (file: string | null) => void): () => void {
    const unsubscribers = [
        on('editor:nav-change_file-requested', ({ action }) => handler(action.path)),
        on('editor:nav-change_file-succeeded', ({ state }) => handler(state.file)),
        on('editor:nav-change_file-failed', ({ state }) => handler(state.file)),
        on('editor:nav-switch_mode-succeeded', ({ state }) => handler(state.file)),
        on('editor:nav-change_dir-succeeded', ({ state }) => handler(state.file)),
        on('editor:nav-new_file-requested', () => handler(null)),
        on('editor:do-save_as-succeeded', ({ state }) => handler(state.file)),
        on('editor:do-delete-succeeded', ({ state }) => handler(state.file)),
        on('editor:do-rename-succeeded', ({ state }) => handler(state.file)),
        on('editor:do-import-succeeded', ({ state }) => handler(state.file)),
        on('editor:nav-open_path-succeeded', ({ state }) => handler(state.file)),
        on('editor:state-resynced', ({ state }) => handler(state.file)),
    ];

    return () => unsubscribers.forEach((unsubscribe) => unsubscribe());
}
