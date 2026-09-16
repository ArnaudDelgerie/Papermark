// Bridges the sidebar (mode-single / mode-dir), which knows which path was
// picked, to the editor controller, which owns fetching and rendering it —
// see EDITOR_FOLDER_MODE.md.
export const OPEN_FILE_EVENT = 'editor:open-file';

export function dispatchOpenFile(path) {
    window.dispatchEvent(new CustomEvent(OPEN_FILE_EVENT, { detail: { path } }));
}

// The reverse direction: the editor tells mode-dir a new file may have
// landed in the open directory (Save as), so its tree can refresh — and
// mode-single, so the new path joins the history (see EDITOR_FIX.md).
export const FILE_SAVED_AS_EVENT = 'editor:saved-as';

export function dispatchFileSavedAs(path) {
    window.dispatchEvent(new CustomEvent(FILE_SAVED_AS_EVENT, { detail: { path } }));
}

// The sidebar (mode-single / mode-dir) tells the editor a file was deleted or
// renamed from under it, so it can clear or relabel itself if that file
// happens to be the one currently open (see EDITOR_FIX.md #5).
export const FILE_DELETED_EVENT = 'editor:file-deleted';

export function dispatchFileDeleted(path) {
    window.dispatchEvent(new CustomEvent(FILE_DELETED_EVENT, { detail: { path } }));
}

export const FILE_RENAMED_EVENT = 'editor:file-renamed';

export function dispatchFileRenamed(oldPath, newPath) {
    window.dispatchEvent(new CustomEvent(FILE_RENAMED_EVENT, { detail: { oldPath, newPath } }));
}
