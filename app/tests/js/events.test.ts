import { describe, expect, it, vi } from 'vitest';
import { emit, on, requested } from '../../assets/editor/events';

describe('emit / on', () => {
    it('delivers the payload to a listener until it unsubscribes', () => {
        const handler = vi.fn();
        const off = on('editor:nav-switch_mode-requested', handler);

        emit('editor:nav-switch_mode-requested', { action: { mode: 'dir' } });
        off();
        emit('editor:nav-switch_mode-requested', { action: { mode: 'single' } });

        expect(handler).toHaveBeenCalledTimes(1);
        expect(handler).toHaveBeenCalledWith({ action: { mode: 'dir' } });
    });

    it('rejects unknown names and incomplete payloads at compile time', () => {
        // @ts-expect-error unknown event
        emit('editor:nav-switch_modes-requested', { action: { mode: 'dir' } });
        // @ts-expect-error `rename` asks for a name, not a new path
        emit('editor:do-rename-requested', { action: { path: '/a.md', newPath: '/b.md' } });
        const state = { mode: 'single', file: null, dir: null, readonly: false, ai_enabled: false } as const;
        // @ts-expect-error a failure never carries the markdown back
        emit('editor:do-save-failed', { state, action: { path: '/a.md', content: '#' } });
    });

    it('builds the event names from the action', () => {
        expect(requested('do-rename')).toBe('editor:do-rename-requested');
    });
});
