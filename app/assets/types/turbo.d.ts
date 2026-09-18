// @hotwired/turbo ships no types; only what the controllers use.
interface TurboFrameElement extends HTMLElement {
    reload(): Promise<void>;
}
