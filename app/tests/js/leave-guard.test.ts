import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LeaveGuard, { type LeaveGuardI18n } from '../../assets/editor/leave-guard';
import { confirmDialog } from '../../assets/utils/confirm-dialog';

vi.mock('../../assets/utils/confirm-dialog', () => ({ confirmDialog: vi.fn() }));

const I18N: LeaveGuardI18n = { confirm: 'Continue and lose unsaved changes?', cancel: 'Cancel', continue: 'Continue' };

describe('LeaveGuard', () => {
    let shouldConfirm: ReturnType<typeof vi.fn<() => boolean>>;
    let onLeave: ReturnType<typeof vi.fn<() => void>>;
    let onStay: ReturnType<typeof vi.fn<() => void>>;
    let guard: LeaveGuard;
    let button: HTMLButtonElement;
    let clicked: ReturnType<typeof vi.fn<() => void>>;

    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = '<button data-editor-leave-guard>Leave</button><button data-plain>Plain</button>';
        button = document.querySelector('[data-editor-leave-guard]')!;
        clicked = vi.fn();
        button.addEventListener('click', clicked as EventListener);
        shouldConfirm = vi.fn(() => true);
        onLeave = vi.fn();
        onStay = vi.fn();
        guard = new LeaveGuard(I18N, { shouldConfirm, onLeave, onStay });
        guard.listen();
    });

    afterEach(() => {
        guard.stop();
    });

    function click(element: Element): void {
        element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }

    it('asks nothing for a click outside a guarded element', () => {
        click(document.querySelector('[data-plain]')!);

        expect(confirmDialog).not.toHaveBeenCalled();
    });

    it('asks nothing and lets the click pass when shouldConfirm() is false', () => {
        shouldConfirm.mockReturnValue(false);

        click(button);

        expect(confirmDialog).not.toHaveBeenCalled();
        expect(clicked).toHaveBeenCalledTimes(1);
    });

    it('stops a guarded click and asks once', () => {
        vi.mocked(confirmDialog).mockReturnValue(new Promise(() => {}));

        click(button);

        expect(clicked).not.toHaveBeenCalled();
        expect(confirmDialog).toHaveBeenCalledTimes(1);
        expect(confirmDialog).toHaveBeenCalledWith({ question: I18N.confirm, cancelLabel: I18N.cancel, continueLabel: I18N.continue });
    });

    it('calls onStay() and does not replay when refused', async () => {
        vi.mocked(confirmDialog).mockResolvedValue(false);

        click(button);
        await Promise.resolve();
        await Promise.resolve();

        expect(onStay).toHaveBeenCalledTimes(1);
        expect(onLeave).not.toHaveBeenCalled();
        expect(clicked).not.toHaveBeenCalled();
    });

    it('calls onLeave() before replaying once accepted, and a guard still listening does not catch the replay', async () => {
        vi.mocked(confirmDialog).mockResolvedValue(true);

        click(button);
        await Promise.resolve();
        await Promise.resolve();

        expect(onLeave).toHaveBeenCalledTimes(1);
        expect(clicked).toHaveBeenCalledTimes(1);
        expect(confirmDialog).toHaveBeenCalledTimes(1);
    });

    it('stop() frees subsequent clicks', () => {
        guard.stop();

        click(button);

        expect(confirmDialog).not.toHaveBeenCalled();
        expect(clicked).toHaveBeenCalledTimes(1);
    });

    /** A leave that is not a click on a marked element (EDITOR_LINKS.md): a followed link. */
    describe('confirmLeave()', () => {
        let action: ReturnType<typeof vi.fn<() => void>>;

        beforeEach(() => {
            action = vi.fn();
        });

        it('runs the action at once when shouldConfirm() is false', () => {
            shouldConfirm.mockReturnValue(false);

            guard.confirmLeave(action);

            expect(confirmDialog).not.toHaveBeenCalled();
            expect(action).toHaveBeenCalledTimes(1);
        });

        it('asks, and runs nothing while the dialog hangs', () => {
            vi.mocked(confirmDialog).mockReturnValue(new Promise(() => {}));

            guard.confirmLeave(action);

            expect(confirmDialog).toHaveBeenCalledTimes(1);
            expect(action).not.toHaveBeenCalled();
        });

        it('refused: calls onStay() and never runs the action', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(false);

            guard.confirmLeave(action);
            await Promise.resolve();
            await Promise.resolve();

            expect(onStay).toHaveBeenCalledTimes(1);
            expect(onLeave).not.toHaveBeenCalled();
            expect(action).not.toHaveBeenCalled();
        });

        it('accepted: calls onLeave() before running the action once', async () => {
            vi.mocked(confirmDialog).mockResolvedValue(true);

            guard.confirmLeave(action);
            await Promise.resolve();
            await Promise.resolve();

            expect(onLeave).toHaveBeenCalledTimes(1);
            expect(action).toHaveBeenCalledTimes(1);
            expect(vi.mocked(onLeave).mock.invocationCallOrder[0])
                .toBeLessThan(vi.mocked(action).mock.invocationCallOrder[0]);
        });
    });
});
