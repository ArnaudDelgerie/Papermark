import { Controller } from '@hotwired/stimulus';
import { dispatchCurrentDirChangeRequest, dispatchCurrentDirUpdated } from '../utils/current-directory.js';
import { pickPath } from '../utils/tauri.js';
import { showToast } from '../utils/toast.js';

/**
 * Owns the folder held in session: picks one, records it through /dir/current,
 * and announces both ends of the round trip. Nothing is rendered by the server
 * in reply — whoever displays the folder's contents listens and refreshes
 * itself (see EDITOR_REACTIVITY.md).
 */
export default class extends Controller {
    static values = { setCurrentUrl: String, csrfToken: String, i18n: Object };

    static targets = ['openButton', 'path'];

    async change() {
        const path = await pickPath('directory');
        if (path === null) {
            return;
        }

        this.openButtonTarget.disabled = true;
        this.openButtonTarget.classList.add('is-loading');
        dispatchCurrentDirChangeRequest(path);

        let newPath = null;
        try {
            newPath = await this.#setCurrent(path);
            this.pathTarget.textContent = newPath;
            this.pathTarget.title = newPath;
            this.pathTarget.hidden = false;
        } catch (err) {
            console.error('Failed to set the current folder:', err);
            showToast('error', err.message || this.i18nValue.failed || 'Could not open the folder');
        }

        // Also on failure: the listeners went into their loading state on the
        // request, and the session still holds the previous folder for them.
        dispatchCurrentDirUpdated(newPath);
        this.openButtonTarget.disabled = false;
        this.openButtonTarget.classList.remove('is-loading');
    }

    // Resolves to the path the server actually recorded (realpath'd).
    async #setCurrent(path) {
        const body = new FormData();
        body.append('path', path);

        const response = await fetch(this.setCurrentUrlValue, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
            body,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.error || `Could not open the folder: ${response.status}`);
        }

        const { open_directory: openDirectory } = await response.json();

        return openDirectory;
    }
}
