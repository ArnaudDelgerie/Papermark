export function pickPath(kind) {
    const tauri = window.__TAURI__;
    if (!tauri?.core?.invoke) {
        console.warn('Tauri IPC is not available — file picker requires the TFSApp hub.');
        return Promise.resolve(null);
    }

    return tauri.core.invoke('pick_path', { kind });
}

export function savePath(fileName = 'untitled.md', directory) {
    const tauri = window.__TAURI__;
    if (!tauri?.core?.invoke) {
        console.warn('Tauri IPC is not available — save dialog requires the TFSApp hub.');
        return Promise.resolve(null);
    }

    return tauri.core.invoke('save_path', {
        filters: [
            { name: 'Markdown', extensions: ['md'] },
            { name: 'Text', extensions: ['txt'] },
        ],
        fileName,
        ...(directory ? { directory } : {}),
    });
}
