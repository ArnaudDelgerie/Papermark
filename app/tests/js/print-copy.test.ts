import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PrintCopy from '../../assets/editor/print-copy';

describe('PrintCopy', () => {
    let source: ReturnType<typeof vi.fn<() => HTMLElement | null>>;
    let printCopy: PrintCopy;
    let printSpy: ReturnType<typeof vi.spyOn>;

    function makeCopy(): HTMLElement {
        const el = document.createElement('div');
        el.className = 'print-copy';

        return el;
    }

    beforeEach(() => {
        source = vi.fn(() => makeCopy());
        printCopy = new PrintCopy(source);
        printSpy = vi.spyOn(window, 'print').mockImplementation(() => {});
        printCopy.listen();
    });

    afterEach(() => {
        printCopy.stop();
        printSpy.mockRestore();
    });

    it('mounts what source() renders on beforeprint, and afterprint removes it', () => {
        window.dispatchEvent(new Event('beforeprint'));

        expect(document.querySelectorAll('.print-copy')).toHaveLength(1);

        window.dispatchEvent(new Event('afterprint'));

        expect(document.querySelectorAll('.print-copy')).toHaveLength(0);
    });

    it('print() mounts and calls window.print()', () => {
        printCopy.print();

        expect(document.querySelectorAll('.print-copy')).toHaveLength(1);
        expect(printSpy).toHaveBeenCalledTimes(1);
    });

    it('two prints in a row leave only one node', () => {
        printCopy.print();
        printCopy.print();

        expect(document.querySelectorAll('.print-copy')).toHaveLength(1);
    });

    it('mounts nothing when source() is null', () => {
        source.mockReturnValue(null);

        printCopy.print();

        expect(document.querySelectorAll('.print-copy')).toHaveLength(0);
    });

    it('stop() removes the mounted node and the listeners', () => {
        window.dispatchEvent(new Event('beforeprint'));
        expect(document.querySelectorAll('.print-copy')).toHaveLength(1);

        printCopy.stop();

        expect(document.querySelectorAll('.print-copy')).toHaveLength(0);

        window.dispatchEvent(new Event('beforeprint'));
        expect(document.querySelectorAll('.print-copy')).toHaveLength(0);
    });
});
