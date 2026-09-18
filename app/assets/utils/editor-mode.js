// The mode switch tells the rest of the page which mode is now showing, then
// that the server has recorded it. The left columns react to the first, so the
// switch feels immediate; the editor waits for the second, whose state has no
// current file (see EDITOR_REACTIVITY.md).
export const MODE_CHANGE_REQUEST_EVENT = 'editor:mode-change-request';

export function dispatchModeChangeRequest(mode) {
    window.dispatchEvent(new CustomEvent(MODE_CHANGE_REQUEST_EVENT, { detail: { mode } }));
}

// Only once the server has answered, with its EditorState. A refused switch
// puts the columns back with a fresh change-request instead, and sends no
// update: the editor keeps what it already had.
export const MODE_UPDATED_EVENT = 'editor:mode-updated';

export function dispatchModeUpdated(state) {
    window.dispatchEvent(new CustomEvent(MODE_UPDATED_EVENT, { detail: { state } }));
}
