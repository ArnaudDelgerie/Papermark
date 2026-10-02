// @hotwired/turbo ships no types; only what the controllers use.
interface TurboFrameElement extends HTMLElement {
    reload(): Promise<void>;
    /** The load in flight or last done; settles once its content is rendered. */
    loaded?: Promise<void>;
}
