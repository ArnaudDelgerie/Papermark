// Same native <dialog> approach as confirm-dialog.ts (the webview handles no
// native browser dialogs, so window.prompt() isn't an option either).
// Resolves to the trimmed new name, or null if cancelled/left unchanged.
// The message and both labels are required (SET-05, lot 09): no English
// default, the server's i18n is the only source. The message is also the
// field's label (UX-12, lot 10).
export interface RenameDialogOptions {
    currentName: string;
    message: string;
    cancelLabel: string;
    continueLabel: string;
}

let nextId = 0;

export function renameDialog({ currentName, message, cancelLabel, continueLabel }: RenameDialogOptions): Promise<string | null> {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'editor-confirm-dialog';

        const messageId = `editor-rename-dialog-message-${++nextId}`;
        const messageEl = document.createElement('p');
        messageEl.className = 'editor-confirm-dialog-message';
        messageEl.id = messageId;
        messageEl.textContent = message;
        dialog.append(messageEl);
        dialog.setAttribute('aria-labelledby', messageId);

        const form = document.createElement('form');
        form.method = 'dialog';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'editor-confirm-dialog-input';
        input.setAttribute('aria-labelledby', messageId);
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
