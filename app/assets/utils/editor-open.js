// Events about files, announced by whoever acts on one and heard by whoever
// shows it: the sidebar (mode-single / mode-dir) and the editor. The `…-updated`
// ones carry the EditorState the server returned; none carries file content,
// the editor fetches that itself (see EDITOR_REACTIVITY.md).

// Someone is about to change the current file: the sidebar making one the
// current one (POST /editor/file), or New leaving none (`path` null,
// DELETE /editor/file)…
export const FILE_CHANGE_REQUEST_EVENT = 'editor:file-change-request';

export function dispatchFileChangeRequest(path) {
    window.dispatchEvent(new CustomEvent(FILE_CHANGE_REQUEST_EVENT, { detail: { path } }));
}

// …and it is: the editor loads the current file, if there is one, even when
// it already was — picking it again means reverting to what is on disk.
export const FILE_UPDATED_EVENT = 'editor:file-updated';

export function dispatchFileUpdated(state) {
    window.dispatchEvent(new CustomEvent(FILE_UPDATED_EVENT, { detail: { state } }));
}

// The editor saved under a new path, which may have landed in the current
// folder (mode-dir's tree) and joins the history (mode-single).
export const FILE_SAVED_AS_EVENT = 'editor:saved-as';

export function dispatchFileSavedAs(path, state) {
    window.dispatchEvent(new CustomEvent(FILE_SAVED_AS_EVENT, { detail: { path, state } }));
}

// This file no longer exists, whether the user deleted it or someone found it
// gone (a 404 from /editor/file): everyone drops what refers to it on screen.
// `state` is absent when the file was found gone — the server returned none.
export const FILE_DELETED_EVENT = 'editor:file-deleted';

export function dispatchFileDeleted(path, state = null) {
    window.dispatchEvent(new CustomEvent(FILE_DELETED_EVENT, { detail: { path, state } }));
}

export const FILE_RENAMED_EVENT = 'editor:file-renamed';

export function dispatchFileRenamed(oldPath, newPath, state) {
    window.dispatchEvent(new CustomEvent(FILE_RENAMED_EVENT, { detail: { oldPath, newPath, state } }));
}
