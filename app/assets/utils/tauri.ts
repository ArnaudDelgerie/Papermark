export type PickKind = 'file' | 'directory';

export function pickPath(kind: PickKind): Promise<string | null> {
    const tauri = window.__TAURI__;
    if (!tauri?.core?.invoke) {
        console.warn('Tauri IPC is not available — file picker requires the TFSApp hub.');
        return Promise.resolve(null);
    }

    return tauri.core.invoke<string | null>('pick_path', { kind });
}

export interface SaveFilter {
    name: string;
    extensions: string[];
}

const DEFAULT_SAVE_FILTERS: SaveFilter[] = [
    { name: 'Markdown', extensions: ['md'] },
    { name: 'Text', extensions: ['txt'] },
];

export function savePath(
    fileName = 'untitled.md',
    directory?: string | null,
    filters: SaveFilter[] = DEFAULT_SAVE_FILTERS,
): Promise<string | null> {
    const tauri = window.__TAURI__;
    if (!tauri?.core?.invoke) {
        console.warn('Tauri IPC is not available — save dialog requires the TFSApp hub.');
        return Promise.resolve(null);
    }

    return tauri.core.invoke<string | null>('save_path', {
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
export type UpdateCheck = { status: string; reason?: string; [key: string]: unknown };

export function checkForUpdate(): Promise<UpdateCheck> {
    const tauri = window.__TAURI__;
    if (!tauri?.core?.invoke) {
        console.warn('Tauri IPC is not available — update check requires the TFSApp hub.');
        return Promise.resolve({ status: 'unavailable', reason: 'local_source' });
    }

    return tauri.core.invoke<UpdateCheck>('update_check');
}
