import { describe, expect, it } from 'vitest';
import ModalController from '../../assets/controllers/modal_controller';
import { mount, unmount } from './stimulus';

/**
 * UX-06, lot 10: the modal announces its openings and closings on window,
 * with the <dialog> in the payload — toast_controller follows them to know
 * where to publish.
 */
const MODAL = `
<div data-controller="modal">
    <button type="button" id="open" data-action="click->modal#open">Open</button>
    <dialog data-modal-target="dialog">
        <button type="button" id="close" data-action="click->modal#close">Close</button>
    </dialog>
</div>`;

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

describe('the modal', () => {
    it('announces the opening and every closing with its dialog', async () => {
        const application = await mount(MODAL, { modal: ModalController });
        const dialog = document.querySelector('dialog') as unknown as HTMLDialogElement;
        patchDialog(dialog);

        const seen: Array<[string, unknown]> = [];
        const record = (name: string): ((event: Event) => void) => {
            return (event: Event) => seen.push([name, (event as CustomEvent).detail.dialog]);
        };
        const onOpened = record('opened');
        const onClosed = record('closed');
        window.addEventListener('modal:opened', onOpened);
        window.addEventListener('modal:closed', onClosed);

        document.getElementById('open')!.click();
        // Escape, the backdrop, the close button: all end in the dialog's
        // own close event, which is what the controller listens to.
        dialog.close();
        document.getElementById('open')!.click();
        document.getElementById('close')!.click();

        expect(seen).toEqual([
            ['opened', dialog],
            ['closed', dialog],
            ['opened', dialog],
            ['closed', dialog],
        ]);

        window.removeEventListener('modal:opened', onOpened);
        window.removeEventListener('modal:closed', onClosed);

        await unmount(application);
    });

    it('stops listening once disconnected', async () => {
        const application = await mount(MODAL, { modal: ModalController });
        const dialog = document.querySelector('dialog') as unknown as HTMLDialogElement;
        patchDialog(dialog);

        await unmount(application);

        const seen: string[] = [];
        const onClosed = (): void => {
            seen.push('closed');
        };
        window.addEventListener('modal:closed', onClosed);

        dialog.close();
        expect(seen).toEqual([]);

        window.removeEventListener('modal:closed', onClosed);
    });
});
