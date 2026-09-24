import { describe, expect, it, vi, afterEach } from 'vitest';
import ModalController from '../../assets/controllers/modal_controller';
import ToastController from '../../assets/controllers/toast_controller';
import { mount, unmount } from './stimulus';

/**
 * The toast container of base.html.twig: the flash messages of the last
 * redirect, and the runtime toasts. The close button's label is the
 * server's (SET-05, lot 09) — never a hardcoded "Close". UX-06 + UX-16,
 * lot 10: errors stay until closed, successes leave after 4 s unless
 * hovered or focused, and an open modal owns where the toasts land.
 */
const CONTAINER = `
<div data-controller="toast"
     data-toast-messages-value="[{&quot;type&quot;:&quot;error&quot;,&quot;message&quot;:&quot;Invalid token&quot;}]"
     data-toast-close-label-value="Fermer"
     class="toast-container"></div>`;

/** The modal structure of editor.html.twig, with the zone the dialog carries. */
const MODAL = `
<div data-controller="modal">
    <button type="button" id="open" data-action="click->modal#open">Open</button>
    <dialog data-modal-target="dialog">
        <div class="toast-container toast-container--modal" aria-live="polite"></div>
    </dialog>
</div>`;

function toast(): HTMLElement {
    return document.querySelector<HTMLElement>('.toast')!;
}

/** jsdom has no <dialog> machinery: what showModal()/close() would do. */
function patchDialog(dialog: HTMLDialogElement): void {
    dialog.showModal = () => {
        dialog.open = true;
    };
    dialog.close = () => {
        dialog.open = false;
        dialog.dispatchEvent(new Event('close'));
    };
}

afterEach(() => {
    vi.useRealTimers();
});

describe('the toast container', () => {
    it('renders the flashes of the last redirect', async () => {
        const application = await mount(CONTAINER, { toast: ToastController });

        expect(toast().getAttribute('role')).toBe('alert');
        expect(toast().textContent).toContain('Invalid token');

        await unmount(application);
    });

    it('labels its close button from the server, not in hard', async () => {
        const application = await mount(CONTAINER, { toast: ToastController });

        expect(toast().querySelector('button.toast-close')!.getAttribute('aria-label')).toBe('Fermer');

        await unmount(application);
    });

    it('labels the close button of a runtime toast too', async () => {
        const application = await mount('<div data-controller="toast" data-toast-close-label-value="Close"></div>', { toast: ToastController });

        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type: 'success', message: 'Saved' } }));

        expect(toast().querySelector('button.toast-close')!.getAttribute('aria-label')).toBe('Close');

        await unmount(application);
    });
});

describe('the toast timers (UX-16)', () => {
    it('keeps an error until its close button is clicked', async () => {
        const application = await mount('<div data-controller="toast" data-toast-close-label-value="Close"></div>', { toast: ToastController });
        vi.useFakeTimers();

        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type: 'error', message: 'Refused' } }));
        vi.advanceTimersByTime(60_000);

        expect(document.querySelector('.toast')).not.toBeNull();
        expect(toast().classList.contains('toast--visible')).toBe(true);

        toast().querySelector<HTMLButtonElement>('button.toast-close')!.click();
        expect(toast().classList.contains('toast--visible')).toBe(false);

        vi.useRealTimers();
        await unmount(application);
    });

    it('lets a success go after 4 s', async () => {
        const application = await mount('<div data-controller="toast" data-toast-close-label-value="Close"></div>', { toast: ToastController });
        vi.useFakeTimers();

        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type: 'success', message: 'Saved' } }));
        vi.advanceTimersByTime(3999);
        expect(toast().classList.contains('toast--visible')).toBe(true);

        vi.advanceTimersByTime(1);
        expect(toast().classList.contains('toast--visible')).toBe(false);

        vi.useRealTimers();
        await unmount(application);
    });

    it('suspends the timer on hover and resumes after', async () => {
        const application = await mount('<div data-controller="toast" data-toast-close-label-value="Close"></div>', { toast: ToastController });
        vi.useFakeTimers();

        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type: 'success', message: 'Saved' } }));
        vi.advanceTimersByTime(2000);
        toast().dispatchEvent(new MouseEvent('mouseenter'));

        vi.advanceTimersByTime(10_000);
        expect(toast().classList.contains('toast--visible')).toBe(true);

        toast().dispatchEvent(new MouseEvent('mouseleave'));
        vi.advanceTimersByTime(1999);
        expect(toast().classList.contains('toast--visible')).toBe(true);

        vi.advanceTimersByTime(1);
        expect(toast().classList.contains('toast--visible')).toBe(false);

        vi.useRealTimers();
        await unmount(application);
    });

    it('suspends the timer while the toast holds the focus, and resumes after', async () => {
        const application = await mount('<div data-controller="toast" data-toast-close-label-value="Close"></div>', { toast: ToastController });
        vi.useFakeTimers();

        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type: 'success', message: 'Saved' } }));
        toast().dispatchEvent(new FocusEvent('focusin'));

        vi.advanceTimersByTime(10_000);
        expect(toast().classList.contains('toast--visible')).toBe(true);

        toast().dispatchEvent(new FocusEvent('focusout'));
        vi.advanceTimersByTime(4000);
        expect(toast().classList.contains('toast--visible')).toBe(false);

        vi.useRealTimers();
        await unmount(application);
    });
});

describe('the toast zones (UX-06)', () => {
    it('empties the main zone at the opening and lands the toasts in the modal, then back', async () => {
        const application = await mount(`${CONTAINER}${MODAL}`, { toast: ToastController, modal: ModalController });
        const dialog = document.querySelector('dialog') as unknown as HTMLDialogElement;
        patchDialog(dialog);
        const modalZone = dialog.querySelector<HTMLElement>('.toast-container')!;

        // The flash of the last redirect is in the main zone.
        expect(document.querySelector('.toast-container')!.contains(toast())).toBe(true);

        document.getElementById('open')!.click();
        expect(document.querySelector('.toast-container')!.children.length).toBe(0);

        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type: 'success', message: 'Exported' } }));
        expect(modalZone.contains(toast())).toBe(true);

        dialog.close();
        // Its toasts went with it, not kept for the next opening.
        expect(modalZone.children.length).toBe(0);

        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type: 'success', message: 'Saved' } }));
        expect(document.querySelector('.toast-container')!.contains(toast())).toBe(true);

        await unmount(application);
    });
});
