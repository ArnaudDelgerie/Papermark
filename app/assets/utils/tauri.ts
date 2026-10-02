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

/**
 * One entry of a picker's file type filters. Same shape for pick_path and
 * save_path (CONTRACT.md §7): a translated name, and the extensions it
 * covers, without the dot.
 */
export interface PathFilter {
    name: string;
    /** `readonly` so the frozen extension lists fit without a copy. */
    extensions: readonly string[];
}

export function pickPath(kind: PickKind, i18n: IpcI18n, filters: PathFilter[] = []): Promise<string | null> {
    // No filter sent, no `filters` key: a call without filters stays exactly
    // the `{ kind }` it always was, directory pickers the first.
    return invokePathPicker('pick_path', filters.length > 0 ? { kind, filters } : { kind }, i18n);
}

/**
 * Every label — the proposed file name included — is the caller's, always
 * from the server's i18n (SET-09, lot 09): no default text here, the filters
 * least of all ("Markdown", "Texte", "Archive zip").
 */
export function savePath(
    i18n: IpcI18n,
    fileName: string,
    directory?: string | null,
    filters: PathFilter[] = [],
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
