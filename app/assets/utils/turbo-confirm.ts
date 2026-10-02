import { confirmDialog } from './confirm-dialog';

/**
 * Turbo's `data-turbo-confirm`, on the app's dialog: the webview has no
 * native confirm() (see confirm-dialog.ts). The question is the attribute's
 * own value, as Turbo passes it; the two button labels travel with it on
 * the form (`data-turbo-confirm-cancel` / `data-turbo-confirm-continue`).
 * A promise is all Turbo needs to wait for the dialog's answer.
 */
export function turboConfirm(message: string, element: HTMLElement): Promise<boolean> {
    return confirmDialog({
        question: message,
        // Both are always rendered next to the question (ai_history's rows);
        // an absent one reads as an empty button, never as "undefined".
        cancelLabel: element.dataset.turboConfirmCancel ?? '',
        continueLabel: element.dataset.turboConfirmContinue ?? '',
    });
}
