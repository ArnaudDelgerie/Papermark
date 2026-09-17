export function pickPath(kind) {
    const tauri = window.__TAURI__;
    if (!tauri?.core?.invoke) {
        console.warn('Tauri IPC is not available — file picker requires the TFSApp hub.');
        return Promise.resolve(null);
    }

    return tauri.core.invoke('pick_path', { kind });
}

const DEFAULT_SAVE_FILTERS = [
    { name: 'Markdown', extensions: ['md'] },
    { name: 'Text', extensions: ['txt'] },
];

export function savePath(fileName = 'untitled.md', directory, filters = DEFAULT_SAVE_FILTERS) {
    const tauri = window.__TAURI__;
    if (!tauri?.core?.invoke) {
        console.warn('Tauri IPC is not available — save dialog requires the TFSApp hub.');
        return Promise.resolve(null);
    }

    return tauri.core.invoke('save_path', {
        filters,
        fileName,
        ...(directory ? { directory } : {}),
    });
}

/**
 * Asks the hub whether a newer version of this app exists (CONTRACT.md §7
 * `actions.update`). Outside the hub, there is no update mechanism at all —
 * treated the same as `local_source`, not as an error.
 */
export function checkForUpdate() {
    const tauri = window.__TAURI__;
    if (!tauri?.core?.invoke) {
        console.warn('Tauri IPC is not available — update check requires the TFSApp hub.');
        return Promise.resolve({ status: 'unavailable', reason: 'local_source' });
    }

    return tauri.core.invoke('update_check');
}
