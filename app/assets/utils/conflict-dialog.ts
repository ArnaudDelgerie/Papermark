// The webview shows no native confirm(): same reason as confirm-dialog.ts.
// Three ways out of a save conflict (lot 03-enregistrement.md): keep the
// changes under another name, overwrite what the disk now holds, or give
// up on saving. The `message` is the server's own words for what happened
// (modified outside of Papermark, or gone).

export interface SaveConflictOptions {
    message: string | null;
    question: string;
    cancelLabel: string;
    saveAsLabel: string;
    overwriteLabel: string;
}

export type SaveConflictChoice = 'cancel' | 'save_as' | 'overwrite';

export function saveConflictDialog({
    message,
    question,
    cancelLabel,
    saveAsLabel,
    overwriteLabel,
}: SaveConflictOptions): Promise<SaveConflictChoice> {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'editor-confirm-dialog';

        if (message !== null) {
            const messageEl = document.createElement('p');
            messageEl.className = 'editor-confirm-dialog-message';
            messageEl.textContent = message;
            dialog.append(messageEl);
        }

        const questionEl = document.createElement('p');
        questionEl.className = 'editor-confirm-dialog-question';
        questionEl.textContent = question;
        dialog.append(questionEl);

        const actions = document.createElement('div');
        actions.className = 'editor-confirm-dialog-actions';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = cancelLabel;
        cancel.addEventListener('click', () => dialog.close('cancel'));

        const saveAs = document.createElement('button');
        saveAs.type = 'button';
        saveAs.textContent = saveAsLabel;
        saveAs.addEventListener('click', () => dialog.close('save_as'));

        const overwrite = document.createElement('button');
        overwrite.type = 'button';
        overwrite.className = 'editor-confirm-continue';
        overwrite.textContent = overwriteLabel;
        overwrite.addEventListener('click', () => dialog.close('overwrite'));

        // Escape closes it too, with an empty returnValue.
        dialog.addEventListener('close', () => {
            dialog.remove();
            resolve(dialog.returnValue === 'save_as' || dialog.returnValue === 'overwrite'
                ? dialog.returnValue
                : 'cancel');
        });

        actions.append(cancel, saveAs, overwrite);
        dialog.append(actions);
        document.body.append(dialog);
        dialog.showModal();
        cancel.focus();
    });
}
