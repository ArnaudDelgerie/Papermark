/**
 * Mounts the printable copy an editor renders (see CrepeHost.printCopy())
 * around window.print(), whether it fires from beforeprint or is called
 * directly.
 */
export default class PrintCopy {
    #source: () => HTMLElement | null;
    #mounted: HTMLElement | null = null;
    #onBeforePrint = (): void => this.#mount();
    #onAfterPrint = (): void => this.#unmount();

    constructor(source: () => HTMLElement | null) {
        this.#source = source;
    }

    listen(): void {
        window.addEventListener('beforeprint', this.#onBeforePrint);
        window.addEventListener('afterprint', this.#onAfterPrint);
    }

    stop(): void {
        window.removeEventListener('beforeprint', this.#onBeforePrint);
        window.removeEventListener('afterprint', this.#onAfterPrint);
        this.#unmount();
    }

    /**
     * Mounts here too: beforeprint alone doesn't rely on the webview firing
     * it for a print triggered by code.
     */
    print(): void {
        this.#mount();
        window.print();
    }

    #mount(): void {
        const copy = this.#source();
        this.#unmount();
        if (copy === null) {
            return;
        }

        document.body.append(copy);
        this.#mounted = copy;
    }

    #unmount(): void {
        this.#mounted?.remove();
        this.#mounted = null;
    }
}
