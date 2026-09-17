// The mode switch tells the rest of the page which mode is now showing, then
// which file the server remembers for it. The left columns and the nav react
// to the first, so the switch feels immediate; the editor waits for the second
// because only the server knows that file (see EDITOR_REACTIVITY.md).
export const MODE_CHANGE_REQUEST_EVENT = 'editor:mode-change-request';

export function dispatchModeChangeRequest(mode) {
    window.dispatchEvent(new CustomEvent(MODE_CHANGE_REQUEST_EVENT, { detail: { mode } }));
}

// Only once the server has recorded the mode, since it carries the file that
// mode remembers. A refused switch puts the columns back with a fresh
// change-request instead, and the editor keeps what it already had.
export const MODE_UPDATED_EVENT = 'editor:mode-updated';

export function dispatchModeUpdated(mode, path, content) {
    window.dispatchEvent(new CustomEvent(MODE_UPDATED_EVENT, { detail: { mode, path, content } }));
}
