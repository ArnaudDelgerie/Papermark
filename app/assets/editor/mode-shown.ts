import { type EditorMode, on } from './events';

/**
 * Follows the mode on screen: the one asked for at once, then the one the
 * state ends up with, success or failure (S6), or an import that opened
 * something. Returns the unsubscriber.
 */
export function onModeShown(handler: (mode: EditorMode) => void): () => void {
    const unsubscribers = [
        on('editor:nav-switch_mode-requested', ({ action }) => handler(action.mode)),
        on('editor:nav-switch_mode-succeeded', ({ state }) => handler(state.mode)),
        on('editor:nav-switch_mode-failed', ({ state }) => handler(state.mode)),
        // An import that opened something may switch the mode; one that
        // opened nothing left it as it was.
        on('editor:do-import-succeeded', ({ state, action }) => {
            if (action.openMode !== null) {
                handler(state.mode);
            }
        }),
    ];

    return () => unsubscribers.forEach((unsubscribe) => unsubscribe());
}
