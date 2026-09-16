// Same native <dialog> approach as confirm-dialog.js (the webview handles no
// native browser dialogs, so window.prompt() isn't an option either).
// Resolves to the trimmed new name, or null if cancelled/left unchanged.
export function renameDialog({ currentName, message, cancelLabel = 'Cancel', continueLabel = 'Rename' }) {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'editor-confirm-dialog';

        if (message) {
            const messageEl = document.createElement('p');
            messageEl.className = 'editor-confirm-dialog-message';
            messageEl.textContent = message;
            dialog.append(messageEl);
        }

        const form = document.createElement('form');
        form.method = 'dialog';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'editor-confirm-dialog-input';
        input.value = currentName;
        form.append(input);

        const actions = document.createElement('div');
        actions.className = 'editor-confirm-dialog-actions';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = cancelLabel;
        cancel.addEventListener('click', () => dialog.close('cancel'));

        const cont = document.createElement('button');
        cont.type = 'submit';
        cont.className = 'editor-confirm-continue';
        cont.textContent = continueLabel;

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            dialog.close('rename');
        });

        dialog.addEventListener('close', () => {
            dialog.remove();
            const newName = input.value.trim();
            resolve(dialog.returnValue === 'rename' && newName !== '' && newName !== currentName ? newName : null);
        });

        actions.append(cancel, cont);
        form.append(actions);
        dialog.append(form);
        document.body.append(dialog);
        dialog.showModal();
        input.focus();
        input.select();
    });
}
