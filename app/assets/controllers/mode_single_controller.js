import { Controller } from '@hotwired/stimulus';
import { dispatchOpenFile } from '../utils/editor-open.js';
import { pickPath } from '../utils/tauri.js';

const STORAGE_KEY = 'editor.single.history';

/**
 * History of files opened in single mode: sessionStorage only (lost on app
 * restart, accepted — see EDITOR_FOLDER_MODE.md). Entries are added as soon
 * as the user picks or clicks a path, whether the open that follows succeeds
 * or not; the leave guard (editor_controller, window-level) runs first, so a
 * cancelled open never reaches here.
 */
export default class extends Controller {
    static values = {
        maxEntries: { type: Number, default: 50 },
    };

    static targets = ['list', 'empty'];

    connect() {
        this.#render();
    }

    async openFile() {
        const path = await pickPath('file');
        if (path === null) {
            return;
        }

        this.#pushHistory(path);
        dispatchOpenFile(path);
    }

    openHistoryEntry(event) {
        event.preventDefault();
        this.#pushHistory(event.currentTarget.dataset.path);
        dispatchOpenFile(event.currentTarget.dataset.path);
    }

    #pushHistory(path) {
        const history = this.#readHistory().filter((entry) => entry !== path);
        history.unshift(path);
        this.#writeHistory(history.slice(0, this.maxEntriesValue));
        this.#render();
    }

    #readHistory() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    #writeHistory(history) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        } catch {
            // sessionStorage unavailable (private mode, quota…): history just won't persist.
        }
    }

    #render() {
        const history = this.#readHistory();

        this.listTarget.innerHTML = '';
        for (const path of history) {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = '#';
            link.title = path;
            link.dataset.path = path;
            link.dataset.action = 'click->mode-single#openHistoryEntry';
            link.setAttribute('data-editor-leave-guard', '');

            const label = document.createElement('span');
            label.className = 'mode-tree-label';
            label.textContent = path.split('/').pop();
            link.append(label);

            li.append(link);
            this.listTarget.append(li);
        }

        this.emptyTarget.hidden = history.length > 0;
    }
}
