import { showToast } from './toast';

export type PickKind = 'file' | 'directory';

/**
 * The two labels of a picker failure (HUB-06 + FRT-07, lot 08), shown as
 * toasts by the wrapper itself so no call site can forget them. A
 * cancellation (the user closed the picker) shows nothing.
 */
export interface IpcI18n {
    /** No IPC at all: the action needs the hub. */
    unavailable: string;
    /** The invoke itself failed. */
    rejected: string;
}

export function pickPath(kind: PickKind, i18n: IpcI18n): Promise<string | null> {
    return invokePathPicker('pick_path', { kind }, i18n);
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
    i18n: IpcI18n,
    fileName = 'untitled.md',
    directory?: string | null,
    filters: SaveFilter[] = DEFAULT_SAVE_FILTERS,
): Promise<string | null> {
    return invokePathPicker('save_path', { filters, fileName, ...(directory ? { directory } : {}) }, i18n);
}

/**
 * The three outcomes of a native picker, kept apart (HUB-06): **cancelled**
 * — the invoke answered `null`, the user closed the dialog, nothing happens;
 * **unavailable** — no IPC, the action cannot run outside the hub: toast;
 * **rejected** — the invoke failed: toast, the detail in the console.
 */
async function invokePathPicker(
    command: 'pick_path' | 'save_path',
    args: Record<string, unknown>,
    i18n: IpcI18n,
): Promise<string | null> {
    const core = window.__TAURI__?.core;
    if (!core?.invoke) {
        console.warn(`Tauri IPC is not available — ${command} requires the TFSApp hub.`);
        showToast('error', i18n.unavailable);

        return null;
    }

    try {
        return await core.invoke<string | null>(command, args);
    } catch (error) {
        console.error(`${command} failed:`, error);
        showToast('error', i18n.rejected);

        return null;
    }
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
