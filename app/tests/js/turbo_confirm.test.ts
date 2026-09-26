import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { config, session } from '@hotwired/turbo';
import { turboConfirm } from '../../assets/utils/turbo-confirm';
import { settle } from './stimulus';

/**
 * Decision 5 of EDITOR_AI_HISTORY.md: data-turbo-confirm goes through the
 * app's dialog (the webview has no native confirm()), and only its answer
 * submits the form. Turbo is the real one here, driving the frame's form
 * submission; only the network stays on a never-resolving fetch, so the
 * test sees the submission start and nothing after.
 */
const FRAME = `
<turbo-frame id="ai-history">
    <form method="post" action="/ai/history/5c0a6ab5-58b3-4d38-8c82-4be411ee6776/delete?page=2"
          data-turbo-confirm="Delete this entry from the history?"
          data-turbo-confirm-cancel="Cancel" data-turbo-confirm-continue="Delete">
        <input type="hidden" name="_token" value="tk-app">
        <button type="submit">Delete</button>
    </form>
</turbo-frame>`;

/** jsdom has no <dialog> machinery: what showModal()/close() would do. */
function patchDialogs(): void {
    const proto = HTMLDialogElement.prototype as unknown as {
        showModal: () => void;
        close: (returnValue?: string) => void;
    };

    proto.showModal = function (this: HTMLDialogElement): void {
        this.open = true;
    };
    proto.close = function (this: HTMLDialogElement, returnValue?: string): void {
        this.open = false;
        this.returnValue = returnValue ?? '';
        this.dispatchEvent(new Event('close'));
    };
}

/**
 * @types/hotwired__turbo types the session without start()/stop(), which the
 * shipped module nevertheless exposes.
 */
const turboSession = session as unknown as { start(): void; stop(): void };

describe('the Turbo confirmation', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        patchDialogs();
        fetchMock = vi.fn(() => new Promise<Response>(() => {}));
        vi.stubGlobal('fetch', fetchMock);
        // jsdom has no IntersectionObserver, which a connected turbo-frame
        // immediately needs (Turbo's AppearanceObserver).
        vi.stubGlobal('IntersectionObserver', class {
            observe(): void {}
            unobserve(): void {}
            disconnect(): void {}
        });

        config.drive.enabled = false;
        config.forms.confirm = turboConfirm;

        document.body.innerHTML = FRAME;
        turboSession.start();
    });

    afterEach(() => {
        turboSession.stop();
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('asks the form question with the form labels, and does not submit while the dialog is open', async () => {
        document.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await settle();

        const dialog = document.querySelector('dialog.editor-confirm-dialog');
        expect(dialog).not.toBeNull();
        expect(dialog!.querySelector('.editor-confirm-dialog-question')!.textContent)
            .toBe('Delete this entry from the history?');
        const buttons = [...dialog!.querySelectorAll('button')].map((button) => button.textContent);
        expect(buttons).toEqual(['Cancel', 'Delete']);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('cancel closes the dialog without submitting, continue submits the form', async () => {
        const submit = (): void => {
            document.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        };

        submit();
        await settle();
        (document.querySelector('dialog.editor-confirm-dialog button') as HTMLElement).click();
        await settle();
        expect(fetchMock).not.toHaveBeenCalled();

        submit();
        await settle();
        ([...document.querySelectorAll('dialog.editor-confirm-dialog button')].at(-1) as HTMLElement).click();
        await settle();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toContain('/ai/history/5c0a6ab5-58b3-4d38-8c82-4be411ee6776/delete');
    });
});
