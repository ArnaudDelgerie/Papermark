// Bridges the sidebar (mode-single / mode-dir), which knows which path was
// picked, to the editor controller, which owns fetching and rendering it —
// see EDITOR_FOLDER_MODE.md.
export const OPEN_FILE_EVENT = 'editor:open-file';

export function dispatchOpenFile(path) {
    window.dispatchEvent(new CustomEvent(OPEN_FILE_EVENT, { detail: { path } }));
}

// The reverse direction: the editor tells mode-dir a new file may have
// landed in the open directory (Save as), so its tree can refresh.
export const FILE_SAVED_AS_EVENT = 'editor:saved-as';

export function dispatchFileSavedAs(path) {
    window.dispatchEvent(new CustomEvent(FILE_SAVED_AS_EVENT, { detail: { path } }));
}
