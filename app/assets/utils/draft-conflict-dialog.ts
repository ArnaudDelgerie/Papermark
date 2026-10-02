// The webview shows no native confirm(): same reason as confirm-dialog.ts.
// Two ways out of a draft whose file changed on disk since (lot
// 04-brouillon.md): keep the recovered draft — its revision then becomes
// the disk's, so the next Save has nothing to conflict with — or go back to
// what's on disk now. No Cancel: both are complete choices, so closing the
// dialog (Escape) keeps the draft, which loses nothing.

export interface DraftConflictOptions {
    question: string;
    keepDraftLabel: string;
    useDiskLabel: string;
}

export type DraftConflictChoice = 'keep_draft' | 'use_disk';

let nextId = 0;

export function draftConflictDialog({ question, keepDraftLabel, useDiskLabel }: DraftConflictOptions): Promise<DraftConflictChoice> {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'editor-confirm-dialog';

        // UX-12, lot 10: the question is the dialog's accessible name.
        const questionId = `editor-draft-conflict-question-${++nextId}`;
        const questionEl = document.createElement('p');
        questionEl.className = 'editor-confirm-dialog-question';
        questionEl.id = questionId;
        questionEl.textContent = question;
        dialog.append(questionEl);
        dialog.setAttribute('aria-labelledby', questionId);

        const actions = document.createElement('div');
        actions.className = 'editor-confirm-dialog-actions';

        const useDisk = document.createElement('button');
        useDisk.type = 'button';
        useDisk.textContent = useDiskLabel;
        useDisk.addEventListener('click', () => dialog.close('use_disk'));

        const keepDraft = document.createElement('button');
        keepDraft.type = 'button';
        keepDraft.className = 'editor-confirm-continue';
        keepDraft.textContent = keepDraftLabel;
        keepDraft.addEventListener('click', () => dialog.close('keep_draft'));

        // Escape closes it with an empty returnValue too, which also keeps the draft.
        dialog.addEventListener('close', () => {
            dialog.remove();
            resolve(dialog.returnValue === 'use_disk' ? 'use_disk' : 'keep_draft');
        });

        actions.append(useDisk, keepDraft);
        dialog.append(actions);
        document.body.append(dialog);
        dialog.showModal();
        keepDraft.focus();
    });
}
