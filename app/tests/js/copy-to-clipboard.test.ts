import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { copyToClipboard } from '../../assets/utils/copy-to-clipboard';

describe('copyToClipboard', () => {
    let writeText: ReturnType<typeof vi.fn<(text: string) => Promise<void>>>;
    const toasts: Array<{ type: string; message: string }> = [];
    const onToast = (event: Event): number => toasts.push((event as CustomEvent).detail);

    beforeEach(() => {
        writeText = vi.fn(() => Promise.resolve());
        Object.assign(navigator, { clipboard: { writeText } });
        vi.spyOn(console, 'error').mockImplementation(() => {});
        toasts.length = 0;
        window.addEventListener('toast:show', onToast);
    });

    afterEach(() => {
        window.removeEventListener('toast:show', onToast);
        vi.restoreAllMocks();
    });

    it('writes the text and shows the success toast', async () => {
        await copyToClipboard('# hello', { success: 'Copied', failure: 'Failed' });

        expect(writeText).toHaveBeenCalledWith('# hello');
        expect(toasts).toEqual([{ type: 'success', message: 'Copied' }]);
    });

    it('accepts a promise, and writes what it resolves to', async () => {
        await copyToClipboard(Promise.resolve('# converted'), { success: 'Copied', failure: 'Failed' });

        expect(writeText).toHaveBeenCalledWith('# converted');
        expect(toasts).toEqual([{ type: 'success', message: 'Copied' }]);
    });

    it('a clipboard that refuses gives the failure toast and writes nothing', async () => {
        writeText.mockRejectedValue(new Error('denied'));

        await copyToClipboard('# hello', { success: 'Copied', failure: 'Failed' });

        expect(toasts).toEqual([{ type: 'error', message: 'Failed' }]);
    });

    it('a rejected promise gives the failure toast and never reaches the clipboard', async () => {
        await copyToClipboard(Promise.reject(new Error('conversion failed')), { success: 'Copied', failure: 'Failed' });

        expect(writeText).not.toHaveBeenCalled();
        expect(toasts).toEqual([{ type: 'error', message: 'Failed' }]);
    });
});
