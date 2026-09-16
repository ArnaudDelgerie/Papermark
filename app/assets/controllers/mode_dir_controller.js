import { Controller } from '@hotwired/stimulus';
import { FILE_SAVED_AS_EVENT, dispatchOpenFile } from '../utils/editor-open.js';
import { pickPath } from '../utils/tauri.js';

export default class extends Controller {
    static values = { treeUrl: String };
    static targets = ['form', 'pathInput', 'openButton', 'tree'];

    #onFileSavedAs = () => this.#refreshTree();

    connect() {
        window.addEventListener(FILE_SAVED_AS_EVENT, this.#onFileSavedAs);
    }

    disconnect() {
        window.removeEventListener(FILE_SAVED_AS_EVENT, this.#onFileSavedAs);
    }

    // A real form submit, not fetch: the server redirects back to this page
    // once the directory is stored in session (see EDITOR_FOLDER_MODE.md).
    // The spinner isn't cleared on success: the page navigates away anyway.
    async openDirectory() {
        const path = await pickPath('directory');
        if (path === null) {
            return;
        }

        this.pathInputTarget.value = path;
        this.openButtonTarget.disabled = true;
        this.openButtonTarget.classList.add('is-loading');

        // Submitting right away can start navigation before the browser has
        // painted the spinner class — on a fast (small-directory) response
        // it never becomes visible. Two rAFs guarantee a paint happened first.
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                this.formTarget.requestSubmit();
            });
        });
    }

    openFile(event) {
        event.preventDefault();
        dispatchOpenFile(event.currentTarget.dataset.path);
    }

    // No path check against the open directory: a re-render showing the same
    // tree is harmless, and re-deriving "is this under the open dir" here
    // would just duplicate what the server already resolves from session.
    async #refreshTree() {
        try {
            const response = await fetch(this.treeUrlValue);
            if (!response.ok) {
                return;
            }
            this.treeTarget.innerHTML = await response.text();
        } catch (err) {
            console.error('Failed to refresh the folder tree:', err);
        }
    }
}
