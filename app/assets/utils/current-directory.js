// The current-directory component tells the rest of the sidebar that the
// folder held in session is about to change, then that it is settled —
// mode-dir shows its loader on the first, reloads its tree frame on the
// second (see EDITOR_REACTIVITY.md).
export const CURRENT_DIR_CHANGE_REQUEST_EVENT = 'dir:current-change-request';

export function dispatchCurrentDirChangeRequest(path) {
    window.dispatchEvent(new CustomEvent(CURRENT_DIR_CHANGE_REQUEST_EVENT, { detail: { path } }));
}

// Sent whichever way the request went: the session is what decides, so a
// failed change still means "re-read it", and the listeners restore what was
// on screen before. `path` is null when the change failed.
export const CURRENT_DIR_UPDATED_EVENT = 'dir:current-updated';

export function dispatchCurrentDirUpdated(path) {
    window.dispatchEvent(new CustomEvent(CURRENT_DIR_UPDATED_EVENT, { detail: { path } }));
}
