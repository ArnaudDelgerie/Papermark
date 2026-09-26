import { Controller } from '@hotwired/stimulus';
import type { FrameElement } from '@hotwired/turbo';

/**
 * Reloads a turbo-frame when a window event announces its content went stale
 * (the AI history frame, on `ai:request-ended`; EDITOR_AI_HISTORY.md,
 * decision 7). Only once the frame has loaded at all: until then its lazy
 * `src` has never been fetched, so the first opening gets the fresh content
 * by itself. reload() re-fetches the current `src`, so the page the frame
 * shows is kept.
 */
export default class extends Controller<FrameElement> {
    static values = {
        event: String,
    };

    declare readonly eventValue: string;

    /** Bound so disconnect() can remove it; see connect(). */
    readonly #onEvent = (): void => {
        if (this.element.hasAttribute('complete')) {
            void this.element.reload();
        }
    };

    connect(): void {
        window.addEventListener(this.eventValue, this.#onEvent);
    }

    disconnect(): void {
        window.removeEventListener(this.eventValue, this.#onEvent);
    }
}
