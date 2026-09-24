import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { confirmDialog } from '../../assets/utils/confirm-dialog';
import { renameDialog } from '../../assets/utils/rename-dialog';
import { saveConflictDialog } from '../../assets/utils/conflict-dialog';
import { draftConflictDialog } from '../../assets/utils/draft-conflict-dialog';

/** jsdom has no <dialog> machinery: what showModal()/close() would do. */
beforeEach(() => {
    HTMLDialogElement.prototype.showModal = function (): void {
        this.open = true;
    };
    HTMLDialogElement.prototype.close = function (returnValue?: string): void {
        if (returnValue !== undefined) {
            this.returnValue = returnValue;
        }
        this.open = false;
        this.dispatchEvent(new Event('close'));
    };
});

afterEach(() => {
    delete (HTMLDialogElement.prototype as Partial<HTMLDialogElement>).showModal;
    delete (HTMLDialogElement.prototype as Partial<HTMLDialogElement>).close;
});

describe('the dialogs created in JS (UX-12, lot 10)', () => {
    it('the confirm dialog is named by its question and described by its message', async () => {
        const promise = confirmDialog({ message: 'The file is open', question: 'Delete it?', cancelLabel: 'Cancel', continueLabel: 'Delete' });
        const dialog = document.querySelector('dialog')!;

        const labelledBy = dialog.getAttribute('aria-labelledby')!;
        const describedBy = dialog.getAttribute('aria-describedby')!;
        expect(document.getElementById(labelledBy)?.textContent).toBe('Delete it?');
        expect(document.getElementById(describedBy)?.textContent).toBe('The file is open');
        expect(labelledBy).not.toBe(describedBy);

        (dialog.querySelector('button') as HTMLButtonElement).click();
        expect(await promise).toBe(false);
    });

    it('the confirm dialog drops aria-describedby when there is no message', async () => {
        const promise = confirmDialog({ question: 'Leave?', cancelLabel: 'Cancel', continueLabel: 'Leave' });
        const dialog = document.querySelector('dialog')!;

        expect(dialog.getAttribute('aria-describedby')).toBeNull();

        (dialog.querySelector('button') as HTMLButtonElement).click();
        expect(await promise).toBe(false);
    });

    it('the rename dialog labels its field with its message, which also names it', async () => {
        const promise = renameDialog({ currentName: 'a.md', message: 'Rename a.md', cancelLabel: 'Cancel', continueLabel: 'Rename' });
        const dialog = document.querySelector('dialog')!;
        const input = dialog.querySelector('input')!;

        const labelledBy = dialog.getAttribute('aria-labelledby')!;
        expect(document.getElementById(labelledBy)?.textContent).toBe('Rename a.md');
        expect(input.getAttribute('aria-labelledby')).toBe(labelledBy);

        (dialog.querySelector('button') as HTMLButtonElement).click();
        expect(await promise).toBeNull();
    });

    it('the save conflict dialog is named by its question and described by what happened', async () => {
        const promise = saveConflictDialog({
            message: 'Changed on disk',
            question: 'What now?',
            cancelLabel: 'Cancel',
            saveAsLabel: 'Save as…',
            overwriteLabel: 'Overwrite',
        });
        const dialog = document.querySelector('dialog')!;

        expect(document.getElementById(dialog.getAttribute('aria-labelledby')!)?.textContent).toBe('What now?');
        expect(document.getElementById(dialog.getAttribute('aria-describedby')!)?.textContent).toBe('Changed on disk');

        (dialog.querySelector('button') as HTMLButtonElement).click();
        expect(await promise).toBe('cancel');
    });

    it('the save conflict dialog survives a null message', async () => {
        const promise = saveConflictDialog({
            message: null,
            question: 'What now?',
            cancelLabel: 'Cancel',
            saveAsLabel: 'Save as…',
            overwriteLabel: 'Overwrite',
        });
        const dialog = document.querySelector('dialog')!;

        expect(dialog.getAttribute('aria-describedby')).toBeNull();

        (dialog.querySelector('button') as HTMLButtonElement).click();
        expect(await promise).toBe('cancel');
    });

    it('the draft conflict dialog is named by its question', async () => {
        const promise = draftConflictDialog({ question: 'Which one?', keepDraftLabel: 'Keep draft', useDiskLabel: 'Use disk' });
        const dialog = document.querySelector('dialog')!;

        expect(document.getElementById(dialog.getAttribute('aria-labelledby')!)?.textContent).toBe('Which one?');

        (dialog.querySelector('button') as HTMLButtonElement).click();
        expect(await promise).toBe('use_disk');
    });

    it('two dialogs opening one after the other never share ids', async () => {
        const first = confirmDialog({ question: 'First?', cancelLabel: 'Cancel', continueLabel: 'Go' });
        const firstDialog = document.querySelector('dialog')!;
        (firstDialog.querySelector('button') as HTMLButtonElement).click();
        await first;

        const second = confirmDialog({ question: 'Second?', cancelLabel: 'Cancel', continueLabel: 'Go' });
        const secondDialog = document.querySelector('dialog')!;
        expect(secondDialog.getAttribute('aria-labelledby')).not.toBe(firstDialog.getAttribute('aria-labelledby'));

        (secondDialog.querySelector('button') as HTMLButtonElement).click();
        await second;
    });
});
