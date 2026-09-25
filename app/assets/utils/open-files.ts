/**
 * The hub's `open_files` wire (CONTRACT.md §7, lot 4b — « Ouvrir avec »):
 * the paths the desktop or `tfsapp-hub open` handed to this window, kept by
 * the hub until the app acks them. Nothing here is shown and nothing here
 * decides: the open-with controller reads, shows and acks; this module only
 * talks to the hub, and degrades to "no requests" outside it — a read is
 * not a user action, so failures warn in the console and never toast.
 */

/** One unacknowledged request of this window, as `open_files_pending` gives it. */
export interface OpenRequest {
    id: string;
    /** Oldest first; the first one is what the app opens. */
    paths: string[];
}

/** The bell the hub rings whenever new requests may wait: it carries nothing, it only says "read again". */
const PENDING_EVENT = 'tfsapp://open-files-pending';

/**
 * Subscribes to the hub's bell, on the current window (a global `listen()`
 * would hear every window's). Resolves with the unsubscriber, or `null`
 * outside the hub — the caller then asks for nothing at all.
 */
export function subscribeOpenFiles(onBell: () => void): Promise<(() => void) | null> {
    const webviewWindow = window.__TAURI__?.webviewWindow;
    if (!webviewWindow?.getCurrentWebviewWindow) {
        return Promise.resolve(null);
    }

    return webviewWindow.getCurrentWebviewWindow().listen(PENDING_EVENT, onBell).catch(() => {
        console.warn('The hub refused the open-files subscription — requests will not be received.');
        return null;
    });
}

/**
 * The unacknowledged requests of this window, oldest first. Reading takes
 * nothing away: only an ack retires a request. Any failure or malformed
 * answer is an empty list — a warned read, never a thrown one.
 */
export async function readPendingOpenRequests(): Promise<OpenRequest[]> {
    const core = window.__TAURI__?.core;
    if (!core?.invoke) {
        return [];
    }

    try {
        const answer = await core.invoke<{ requests?: unknown }>('open_files_pending');
        const requests = answer?.requests;
        if (!Array.isArray(requests)) {
            console.warn('open_files_pending answered without a requests list.');
            return [];
        }

        return requests.filter((request): request is OpenRequest => {
            if (typeof request !== 'object' || request === null) {
                return false;
            }
            const { id, paths } = request as { id?: unknown; paths?: unknown };
            return typeof id === 'string' && id !== '' && Array.isArray(paths)
                && paths.every((path) => typeof path === 'string' && path !== '');
        });
    } catch (error) {
        console.warn('open_files_pending failed:', error);
        return [];
    }
}

/**
 * Retires one request, idempotently. A refusal (the request was already
 * acked, the hub is closing, …) is the hub's to explain: warned here, never
 * raised towards the caller — an ack that failed must not stop the flow
 * that already decided.
 */
export async function ackOpenRequest(id: string): Promise<void> {
    const core = window.__TAURI__?.core;
    if (!core?.invoke) {
        return;
    }

    try {
        await core.invoke('open_files_ack', { id });
    } catch (error) {
        console.warn('open_files_ack was refused:', error);
    }
}
