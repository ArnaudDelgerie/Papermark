import { emit } from './events';

/** `http:`, `mailto:`, a Windows drive letter before a path separator… any scheme. */
const SCHEME = /^[a-z][a-z0-9+.-]*:/i;

interface LinkFollowerOptions {
    /** The editor's own readonly state: the plain click follows only there. */
    isReadonly: () => boolean;
    /** The leave guard's confirmation: the document may hold unsaved work. */
    confirmLeave: (action: () => void) => void;
}

/**
 * Follows the document's links to other documents (EDITOR_LINKS.md): a
 * plain click in read-only, Ctrl+click in edit mode, where the document's
 * own editing owns the plain click. Any `href` without a scheme goes to the
 * master as a nav-change_file — the server resolves it against the current
 * file and refuses what it cannot open, wrong extension included. The
 * leave guard asks before the document is left.
 *
 * Everything else keeps the browser's own behavior: schemes leave the
 * webview as ever, a lone `#anchor` scrolls if it finds its target. The
 * click on a followed link never navigates: the path travels as an event,
 * not as a URL.
 */
export default class LinkFollower {
    readonly #element: HTMLElement;
    readonly #options: LinkFollowerOptions;
    readonly #onClick = (event: MouseEvent): void => this.#follow(event);

    constructor(element: HTMLElement, options: LinkFollowerOptions) {
        this.#element = element;
        this.#options = options;
    }

    listen(): void {
        this.#element.addEventListener('click', this.#onClick);
        this.#element.addEventListener('mousemove', this.#onMouseEvent);
        window.addEventListener('keydown', this.#onModifierKey);
        window.addEventListener('keyup', this.#onModifierKey);
        window.addEventListener('blur', this.#clearModifierKey);
    }

    stop(): void {
        this.#element.removeEventListener('click', this.#onClick);
        this.#element.removeEventListener('mousemove', this.#onMouseEvent);
        window.removeEventListener('keydown', this.#onModifierKey);
        window.removeEventListener('keyup', this.#onModifierKey);
        window.removeEventListener('blur', this.#clearModifierKey);
        this.#clearModifierKey();
    }

    /**
     * Carries the Ctrl state as a class on the editor's element, for the
     * pointer cursor over the document's links in edit mode: CSS cannot see
     * a held modifier. Keydown and keyup both report it, so any key event
     * keeps the class in step; blur drops it (a dialog can swallow the keyup).
     *
     * A lost keyup still leaves the class behind, though, and the hub can
     * swallow Ctrl's own. The mouse reports the modifier too, and the
     * cursor only matters while the pointer is over the editor: a mousemove
     * over it re-reads the state and catches up with whatever the keyboard
     * events missed.
     */
    readonly #onModifierKey = (event: KeyboardEvent): void => {
        this.#element.classList.toggle('is-ctrl-down', event.ctrlKey || event.metaKey);
    };

    readonly #onMouseEvent = (event: MouseEvent): void => {
        this.#element.classList.toggle('is-ctrl-down', event.ctrlKey || event.metaKey);
    };

    readonly #clearModifierKey = (): void => {
        this.#element.classList.remove('is-ctrl-down');
    };

    #follow(event: MouseEvent): void {
        // The main button alone: middle-click stays the browser's.
        if (event.button !== 0 || (!this.#options.isReadonly() && !(event.ctrlKey || event.metaKey))) {
            return;
        }

        const link = (event.target as Element | null)?.closest<HTMLAnchorElement>('a[href]');
        if (!link || !this.#element.contains(link)) {
            return;
        }

        const href = link.getAttribute('href');
        // A scheme is not ours (http:, mailto:…), and neither is a
        // protocol-relative `//host`; an empty href would only reload.
        if (href === null || href === '' || href.startsWith('#') || href.startsWith('//') || SCHEME.test(href)) {
            return;
        }

        // The click is ours from here: no navigation away from the page.
        event.preventDefault();
        // The fragment never belongs to the path — the validator would
        // refuse "TODO.md#section" as a bad extension (décision 4). The rest
        // may still be percent-encoded ("mon%20fichier.md"): the server wants
        // the name, not the URL's spelling of it.
        const path = this.#decodePath(href.split('#')[0]);
        this.#options.confirmLeave(() => emit('editor:nav-change_file-requested', { action: { path } }));
    }

    /** A malformed percent escape throws on decode: it travels as-is, and
     the server refuses a path it cannot read. */
    #decodePath(path: string): string {
        try {
            return decodeURIComponent(path);
        } catch {
            return path;
        }
    }
}
