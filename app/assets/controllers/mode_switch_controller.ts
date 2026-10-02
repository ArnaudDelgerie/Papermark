import { Controller } from '@hotwired/stimulus';
import { type EditorMode, emit } from '../editor/events';
import { onModeShown } from '../editor/mode-shown';

/**
 * The mode switch. It only asks: the columns show or hide themselves on the
 * request, and everyone settles on the state the master answers with, a
 * failure included — no replay of the previous mode (S6).
 */
export default class extends Controller {
    static targets = ['link'];

    declare readonly linkTargets: HTMLElement[];

    #unsubscribe: (() => void) | null = null;

    connect(): void {
        this.#unsubscribe = onModeShown((mode) => this.#show(mode));
    }

    disconnect(): void {
        this.#unsubscribe?.();
    }

    change(event: Event): void {
        event.preventDefault();
        const mode = (event.currentTarget as HTMLElement).dataset.mode as EditorMode;
        if (mode === this.#shown()) {
            return;
        }

        emit('editor:nav-switch_mode-requested', { action: { mode } });
    }

    #show(mode: EditorMode): void {
        for (const link of this.linkTargets) {
            const active = link.dataset.mode === mode;
            link.classList.toggle('is-active', active);
            // UX-11, lot 10: the pressed state follows the class, for what the
            // colour now also says.
            link.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
    }

    #shown(): string | undefined {
        return this.linkTargets.find((link) => link.classList.contains('is-active'))?.dataset.mode;
    }
}
