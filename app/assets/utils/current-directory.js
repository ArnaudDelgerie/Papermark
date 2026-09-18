// The current-directory component tells the rest of the page that the current
// folder is about to change, then that it is settled — mode-dir shows its
// loader on the first, reloads its tree frame on the second (see
// EDITOR_REACTIVITY.md).
export const CURRENT_DIR_CHANGE_REQUEST_EVENT = 'dir:current-change-request';

export function dispatchCurrentDirChangeRequest(path) {
    window.dispatchEvent(new CustomEvent(CURRENT_DIR_CHANGE_REQUEST_EVENT, { detail: { path } }));
}

// Sent whichever way the request went: the listeners went into their loading
// state on the request and must come out of it. `state` is the EditorState the
// server returned, or null when the change failed — the session still holds
// the previous folder then.
export const CURRENT_DIR_UPDATED_EVENT = 'dir:current-updated';

export function dispatchCurrentDirUpdated(state) {
    window.dispatchEvent(new CustomEvent(CURRENT_DIR_UPDATED_EVENT, { detail: { state } }));
}
