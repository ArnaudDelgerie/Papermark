import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['imageFolderInput'];

    async pickImageFolder() {
        const tauri = window.__TAURI__;
        if (!tauri?.core?.invoke) {
            console.warn('Tauri IPC is not available — folder picker requires the TFSApp hub.');
            return;
        }

        const path = await tauri.core.invoke('pick_path', { kind: 'directory' });
        if (path === null || !this.hasImageFolderInputTarget) {
            return;
        }

        this.imageFolderInputTarget.value = path;
    }
}
