// The webview shows no native confirm(): the hub doesn't handle JS dialogs.
export function confirmDialog({ message, cancelLabel = 'Cancel', continueLabel = 'Continue' }) {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'editor-confirm-dialog';
        dialog.textContent = message;

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
