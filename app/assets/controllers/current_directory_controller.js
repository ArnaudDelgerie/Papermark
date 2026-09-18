import { Controller } from '@hotwired/stimulus';
import { dispatchCurrentDirChangeRequest, dispatchCurrentDirUpdated } from '../utils/current-directory.js';
import { pickPath } from '../utils/tauri.js';
import { showToast } from '../utils/toast.js';

/**
 * Changes the current folder: picks one, records it through POST /editor/dir,
 * and announces both ends of the round trip. Nothing is rendered by the server
 * in reply — whoever displays the folder's contents listens and refreshes
 * itself (see EDITOR_REACTIVITY.md).
 */
export default class extends Controller {
    static values = { setDirUrl: String, csrfToken: String, i18n: Object };

    static targets = ['openButton', 'path'];

    async change() {
        const path = await pickPath('directory');
        if (path === null) {
            return;
        }

        this.openButtonTarget.disabled = true;
        this.openButtonTarget.classList.add('is-loading');
        dispatchCurrentDirChangeRequest(path);

        let state = null;
        try {
            state = await this.#setDir(path);
            this.pathTarget.textContent = state.dir;
            this.pathTarget.title = state.dir;
            this.pathTarget.hidden = false;
        } catch (err) {
            console.error('Failed to set the current folder:', err);
            showToast('error', err.message || this.i18nValue.failed || 'Could not open the folder');
        }

        // Also on failure: the listeners went into their loading state on the
        // request, and the session still holds the previous folder for them.
        dispatchCurrentDirUpdated(state);
        this.openButtonTarget.disabled = false;
        this.openButtonTarget.classList.remove('is-loading');
    }

    // Resolves to the EditorState, whose folder is the realpath'd one.
    async #setDir(path) {
        const body = new FormData();
        body.append('path', path);

        const response = await fetch(this.setDirUrlValue, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
            body,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.error || `Could not open the folder: ${response.status}`);
        }

        const { state } = await response.json();

        return state;
    }
}
