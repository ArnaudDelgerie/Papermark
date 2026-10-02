import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import FrameReloadController from '../../assets/controllers/frame_reload_controller';
import { mount, unmount } from './stimulus';

/**
 * Decision 7 of EDITOR_AI_HISTORY.md: a turbo-frame reloads on a window
 * event, but only once it has loaded at all — a lazy frame that never opened
 * fetches fresh content by itself on its first opening.
 */
const FRAME = `
<turbo-frame id="ai-history"
    data-controller="frame-reload"
    data-frame-reload-event-value="ai:request-ended"></turbo-frame>`;

describe('frame_reload_controller', () => {
    let application: Awaited<ReturnType<typeof mount>>;
    let reload: ReturnType<typeof vi.fn>;

    beforeEach(async () => {
        application = await mount(FRAME, { 'frame-reload': FrameReloadController });
        reload = vi.fn();
        Object.assign(document.querySelector('turbo-frame') as Element, { reload });
    });

    afterEach(async () => {
        await unmount(application);
    });

    it('does not reload before the frame has loaded once', () => {
        window.dispatchEvent(new Event('ai:request-ended'));

        expect(reload).not.toHaveBeenCalled();
    });

    it('reloads on every event once the frame is loaded', () => {
        const frame = document.querySelector('turbo-frame')!;

        frame.setAttribute('complete', '');
        window.dispatchEvent(new Event('ai:request-ended'));
        window.dispatchEvent(new Event('ai:request-ended'));

        expect(reload).toHaveBeenCalledTimes(2);
    });

    it('stops listening when the frame leaves the DOM', async () => {
        const frame = document.querySelector('turbo-frame')!;

        frame.setAttribute('complete', '');
        await unmount(application);
        window.dispatchEvent(new Event('ai:request-ended'));

        expect(reload).not.toHaveBeenCalled();
    });
});
