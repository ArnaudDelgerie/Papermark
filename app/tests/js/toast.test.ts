import { describe, expect, it } from 'vitest';
import ToastController from '../../assets/controllers/toast_controller';
import { mount, unmount } from './stimulus';

/**
 * The toast container of base.html.twig: the flash messages of the last
 * redirect, and the runtime toasts. The close button's label is the
 * server's (SET-05, lot 09) — never a hardcoded "Close".
 */
const CONTAINER = `
<div data-controller="toast"
     data-toast-messages-value="[{&quot;type&quot;:&quot;error&quot;,&quot;message&quot;:&quot;Invalid token&quot;}]"
     data-toast-close-label-value="Fermer"
     class="toast-container"></div>`;

function toast(): HTMLElement {
    return document.querySelector<HTMLElement>('.toast')!;
}

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
