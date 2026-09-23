// The webview shows no native confirm(): the hub doesn't handle JS dialogs.
// `message` is optional informative context (e.g. what's about to happen);
// `question` is the actual yes/no prompt and is always shown. Both labels
// are required: no call site falls back to an English default (SET-05,
// lot 09) — they come from the server's i18n.
export interface ConfirmDialogOptions {
    message?: string;
    question: string;
    cancelLabel: string;
    continueLabel: string;
}

export function confirmDialog({ message, question, cancelLabel, continueLabel }: ConfirmDialogOptions): Promise<boolean> {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'editor-confirm-dialog';

        if (message) {
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

        const cont = document.createElement('button');
        cont.type = 'button';
        cont.className = 'editor-confirm-continue';
        cont.textContent = continueLabel;
        cont.addEventListener('click', () => dialog.close('continue'));

        // Escape closes it too, with an empty returnValue.
        dialog.addEventListener('close', () => {
            dialog.remove();
            resolve(dialog.returnValue === 'continue');
        });

        actions.append(cancel, cont);
        dialog.append(actions);
        document.body.append(dialog);
        dialog.showModal();
        cancel.focus();
    });
}
