import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The close guard module, lot 02 (gardes de fermeture): one hub guard per
 * thing a window close would lose, with the hub's invoke mocked as in
 * archive.test.ts. The module caches the document's context at module
 * level, so each test takes a fresh copy of it (vi.resetModules + a
 * dynamic import), as a reload would.
 */
let invoke: ReturnType<typeof vi.fn>;

/** A fresh module, its context promise empty again. */
async function freshModule(): Promise<typeof import('../../assets/utils/close-guard')> {
    vi.resetModules();

    return import('../../assets/utils/close-guard');
}

/** Lets the module's promise chain run to its end. */
async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

const commands = (): string[] => invoke.mock.calls.map(([command]) => command as string);

beforeEach(() => {
    invoke = vi.fn();
    window.__TAURI__ = { core: { invoke: invoke as never } };
    vi.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => {
    delete window.__TAURI__;
    vi.restoreAllMocks();
});

describe('the close guard module (lot 02)', () => {
    it('set(true) fetches the context then registers, a repeated set does nothing, set(false) removes', async () => {
        invoke.mockResolvedValue({ context: 'doc-1' });
        const { default: CloseGuard } = await freshModule();
        const guard = new CloseGuard('editor');

        guard.set(true);
        await settle();
        expect(invoke).toHaveBeenCalledWith('close_guard_context');
        expect(invoke).toHaveBeenCalledWith('close_guard_register', { context: 'doc-1', id: 'editor' });

        guard.set(true);
        await settle();
        expect(commands()).toEqual(['close_guard_context', 'close_guard_register']);

        guard.set(false);
        await settle();
        expect(invoke).toHaveBeenCalledWith('close_guard_remove', { context: 'doc-1', id: 'editor' });
    });

    it('a set that flips again while the register is in flight ends on the last state, in order', async () => {
        let resolveRegister!: (value: null) => void;
        invoke.mockImplementation((command: string) => {
            if (command === 'close_guard_context') {
                return Promise.resolve({ context: 'doc-1' });
            }
            if (command === 'close_guard_register') {
                return new Promise((resolve) => (resolveRegister = resolve));
            }

            return Promise.resolve(null);
        });
        const { default: CloseGuard } = await freshModule();
        const guard = new CloseGuard('editor');

        guard.set(true);
        // Until the register itself is in flight, so the flip lands on it.
        while (resolveRegister === undefined) {
            await Promise.resolve();
        }
        guard.set(false);
        resolveRegister(null);
        await settle();

        // The remove waits for the register it follows, and the queue
        // converges on the wanted state: removed.
        expect(commands()).toEqual(['close_guard_context', 'close_guard_register', 'close_guard_remove']);
        expect(invoke).toHaveBeenCalledWith('close_guard_remove', { context: 'doc-1', id: 'editor' });
    });

    it('asks for the context once, whatever the guards and the transitions', async () => {
        invoke.mockResolvedValue({ context: 'doc-1' });
        const { default: CloseGuard } = await freshModule();
        const guard = new CloseGuard('editor');
        const other = new CloseGuard('export');

        guard.set(true);
        other.set(true);
        guard.set(false);
        guard.set(true);
        await settle();

        expect(commands().filter((command) => command === 'close_guard_context')).toHaveLength(1);
        expect(invoke).toHaveBeenCalledWith('close_guard_register', { context: 'doc-1', id: 'editor' });
        expect(invoke).toHaveBeenCalledWith('close_guard_register', { context: 'doc-1', id: 'export' });
    });

    it('without the hub, nothing is called and nothing is warned', async () => {
        delete window.__TAURI__;
        const { default: CloseGuard } = await freshModule();
        const guard = new CloseGuard('editor');

        guard.set(true);
        guard.set(false);
        await settle();

        expect(invoke).not.toHaveBeenCalled();
        expect(console.warn).not.toHaveBeenCalled();
    });

    it('a refused context disables the guards for the whole document', async () => {
        invoke.mockRejectedValue(new Error('no close_guard group'));
        const { default: CloseGuard } = await freshModule();
        const guard = new CloseGuard('editor');

        guard.set(true);
        await settle();
        guard.set(false);
        guard.set(true);
        await settle();

        expect(commands()).toEqual(['close_guard_context']);
        expect(console.warn).toHaveBeenCalledTimes(1);
    });

    it('a refused register is a warning, never an exception, and the state follows anyway', async () => {
        invoke.mockImplementation((command: string) => {
            if (command === 'close_guard_context') {
                return Promise.resolve({ context: 'doc-1' });
            }

            return Promise.reject(new Error('too many guards'));
        });
        const { default: CloseGuard } = await freshModule();
        const guard = new CloseGuard('editor');

        guard.set(true);
        await settle();

        expect(commands()).toEqual(['close_guard_context', 'close_guard_register']);
        expect(console.warn).toHaveBeenCalledTimes(1);

        // The wanted state settled without the hub: the next transition
        // still starts from it.
        guard.set(false);
        await settle();
        expect(invoke).toHaveBeenCalledWith('close_guard_remove', { context: 'doc-1', id: 'editor' });
    });
});
