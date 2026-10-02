import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import OpenWithController from '../../assets/controllers/open_with_controller';
import { emit, on } from '../../assets/editor/events';
import LeaveGuard from '../../assets/editor/leave-guard';
import { confirmDialog } from '../../assets/utils/confirm-dialog';
import { mount, settle, unmount } from './stimulus';
import { attr } from './fixtures';
import EDITOR_I18N from '../contract/i18n/editor.json';
import OPEN_WITH_I18N from '../contract/i18n/open-with.json';

vi.mock('../../assets/utils/confirm-dialog', () => ({ confirmDialog: vi.fn() }));

interface Request {
    id: string;
    paths: string[];
}

/**
 * The « Ouvrir avec » box (lot 4b), with the hub mocked as in close-guard.test.ts:
 * `core.invoke` answers, and the current window's `listen` keeps its handler
 * so a test can ring the bell. editor:ready is emitted by hand — the editor's
 * own emission is editor.test.ts's to check.
 */
describe('the open-with box (lot 4b)', () => {
    let application: Application;
    let invoke: ReturnType<typeof vi.fn>;
    let listen: ReturnType<typeof vi.fn>;
    let bell: (() => void) | null = null;
    /** What the next open_files_pending answers; empty until a test sets it. */
    let requests: Request[] = [];
    /** Deferred read answers, when a test holds the cycle open. */
    let openReads: Array<(answer: { requests: Request[] }) => void> = [];

    const $ = <E extends Element = HTMLElement>(selector: string): E => document.querySelector<E>(selector)!;
    const box = (): HTMLElement => $('[data-controller="open-with"]');
    const question = (): string => $('[data-open-with-target="question"]').textContent ?? '';
    const ignored = (): HTMLElement => $('[data-open-with-target="ignored"]');
    const click = (selector: string): void => {
        $(selector).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    };
    const pendingReads = (): number =>
        invoke.mock.calls.filter(([command]) => command === 'open_files_pending').length;
    const ackIds = (): string[] =>
        invoke.mock.calls.filter(([command]) => command === 'open_files_ack')
            .map(([, args]) => (args as { id: string }).id);

    function openWithHtml(): string {
        return `<div class="open-with" data-controller="open-with" role="status" hidden
            data-open-with-i18n-value="${attr(OPEN_WITH_I18N)}">
            <p data-open-with-target="question"></p>
            <p data-open-with-target="path"></p>
            <p data-open-with-target="ignored" hidden></p>
            <button type="button" data-action="click->open-with#accept" data-editor-leave-guard>Open</button>
            <button type="button" data-action="click->open-with#dismiss">Dismiss</button>
        </div>`;
    }

    async function start(): Promise<void> {
        application = await mount(openWithHtml(), { 'open-with': OpenWithController });
        await settle();
    }

    /** The hub's answer to open_files_pending, from now on. */
    function answer(...pendingRequests: Request[]): void {
        requests = pendingRequests;
        invoke.mockImplementation((command: string) => {
            if (command === 'open_files_pending') {
                return Promise.resolve({ requests });
            }
            if (command === 'open_files_ack') {
                return Promise.resolve(null);
            }

            return Promise.resolve(null);
        });
    }

    beforeEach(() => {
        bell = null;
        requests = [];
        openReads = [];
        invoke = vi.fn((command: string) => {
            if (command === 'open_files_pending') {
                return Promise.resolve({ requests });
            }

            return Promise.resolve(null);
        });
        listen = vi.fn((_event: string, handler: () => void) => {
            bell = handler;

            return Promise.resolve(() => {});
        });
        window.__TAURI__ = {
            core: { invoke: invoke as never },
            webviewWindow: { getCurrentWebviewWindow: () => ({ listen: listen as never }) },
        };
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(async () => {
        await unmount(application);
        delete window.__TAURI__;
        vi.restoreAllMocks();
    });

    it('subscribes first: the first read only happens once listen has resolved', async () => {
        let resolveListen!: (unlisten: () => void) => void;
        listen = vi.fn(() => new Promise<() => void>((resolve) => (resolveListen = resolve)));

        await start();
        expect(listen).toHaveBeenCalledWith('tfsapp://open-files-pending', expect.any(Function));
        // The subscription has not resolved yet: nothing has been read.
        emit('editor:ready', { free: true });
        await settle();
        expect(invoke).not.toHaveBeenCalled();

        resolveListen(() => {});
        await settle();
        expect(pendingReads()).toBe(1);
    });

    it('the first cycle waits for editor:ready before reading', async () => {
        answer({ id: 'r1', paths: ['/notes/a.md'] });

        await start();
        expect(pendingReads()).toBe(0);

        emit('editor:ready', { free: true });
        await settle();
        expect(pendingReads()).toBe(1);
    });

    it('cold start, free editor: acks then opens the newest request directly, box stays hidden', async () => {
        answer(
            { id: 'r1', paths: ['/notes/old.md'] },
            { id: 'r2', paths: ['/notes/new.md'] },
        );
        const opens: string[] = [];
        const stop = on('editor:nav-open_path-requested', ({ action }) => opens.push(action.path));

        await start();
        emit('editor:ready', { free: true });
        await settle();

        expect(ackIds()).toEqual(['r1', 'r2']);
        expect(opens).toEqual(['/notes/new.md']);
        expect(box().hidden).toBe(true);
        stop();
    });

    it('cold start, busy editor: shows the box, acks nothing', async () => {
        answer({ id: 'r1', paths: ['/notes/a.md'] });

        await start();
        emit('editor:ready', { free: false });
        await settle();

        expect(box().hidden).toBe(false);
        expect(question()).toBe('Open “a.md”?');
        expect(ackIds()).toEqual([]);
    });

    it('a bell after the cold start shows the box, even on a free editor', async () => {
        answer();

        await start();
        emit('editor:ready', { free: true });
        await settle();
        expect(pendingReads()).toBe(1);
        expect(box().hidden).toBe(true);

        const opens: string[] = [];
        const stop = on('editor:nav-open_path-requested', ({ action }) => opens.push(action.path));
        answer({ id: 'r1', paths: ['/notes/a.md'] });
        bell!();
        await settle();

        expect(box().hidden).toBe(false);
        expect(opens).toEqual([]);
        expect(ackIds()).toEqual([]);
        stop();
    });

    it('two requests read at once: the oldest is acked, the newest is shown', async () => {
        answer();

        await start();
        emit('editor:ready', { free: true });
        await settle();

        answer(
            { id: 'r1', paths: ['/notes/old.md'] },
            { id: 'r2', paths: ['/notes/new.md'] },
        );
        bell!();
        await settle();

        expect(ackIds()).toEqual(['r1']);
        expect(question()).toBe('Open “new.md”?');
        expect(box().hidden).toBe(false);
    });

    it('a newer request replaces the shown one, which is dismissed; a re-read of the shown one changes nothing', async () => {
        answer();

        await start();
        emit('editor:ready', { free: true });
        await settle();

        answer({ id: 'r1', paths: ['/notes/first.md'] });
        bell!();
        await settle();
        expect(question()).toBe('Open “first.md”?');

        answer({ id: 'r1', paths: ['/notes/first.md'] }, { id: 'r2', paths: ['/notes/second.md'] });
        bell!();
        await settle();
        expect(ackIds()).toEqual(['r1']);
        expect(question()).toBe('Open “second.md”?');

        // The shown request stays pending until decided: reading it again
        // neither re-shows it nor retires it.
        answer({ id: 'r2', paths: ['/notes/second.md'] });
        bell!();
        await settle();
        expect(ackIds()).toEqual(['r1']);
        expect(question()).toBe('Open “second.md”?');
        expect(box().hidden).toBe(false);
    });

    it('three paths offer the first, with a "+ 2" mention; one path hides the mention', async () => {
        answer();

        await start();
        emit('editor:ready', { free: false });
        await settle();

        answer({ id: 'r1', paths: ['/notes/a.md', '/notes/b.md', '/notes/c.md'] });
        bell!();
        await settle();

        expect(question()).toBe('Open “a.md”?');
        expect(ignored().hidden).toBe(false);
        expect(ignored().textContent).toBe('+ 2 other items ignored');

        answer({ id: 'r2', paths: ['/notes/d.md'] });
        bell!();
        await settle();

        expect(ignored().hidden).toBe(true);
    });

    it('« Open » acks first, then asks the master to open, and hides the box', async () => {
        answer();

        await start();
        emit('editor:ready', { free: false });
        await settle();

        answer({ id: 'r1', paths: ['/notes/a.md'] });
        bell!();
        await settle();

        const order: string[] = [];
        invoke.mockImplementation((command: string) => {
            if (command === 'open_files_ack') {
                order.push('ack');

                return Promise.resolve(null);
            }

            return Promise.resolve({ requests });
        });
        const opens: string[] = [];
        const stop = on('editor:nav-open_path-requested', ({ action }) => {
            order.push('open');
            opens.push(action.path);
        });

        click('[data-action="click->open-with#accept"]');
        await settle();

        expect(order).toEqual(['ack', 'open']);
        expect(opens).toEqual(['/notes/a.md']);
        expect(box().hidden).toBe(true);
        stop();
    });

    it('« Open » on a guarded document asks the leave guard first; Cancel keeps the box as it was', async () => {
        answer();

        await start();
        emit('editor:ready', { free: false });
        await settle();

        answer({ id: 'r1', paths: ['/notes/a.md'] });
        bell!();
        await settle();
        expect(box().hidden).toBe(false);

        // The editor's own guard, on a document worth confirming.
        let dirty = true;
        const guard = new LeaveGuard(EDITOR_I18N.unsaved, {
            shouldConfirm: () => dirty,
            onLeave: () => {},
            onStay: () => {},
        });
        guard.listen();
        const opens: string[] = [];
        const stop = on('editor:nav-open_path-requested', ({ action }) => opens.push(action.path));

        vi.mocked(confirmDialog).mockResolvedValue(false);
        click('[data-action="click->open-with#accept"]');
        await settle();

        expect(confirmDialog).toHaveBeenCalledTimes(1);
        expect(ackIds()).toEqual([]);
        expect(opens).toEqual([]);
        expect(box().hidden).toBe(false);

        // Confirmed: the guard replays the click, and only then does the box act.
        dirty = false;
        vi.mocked(confirmDialog).mockResolvedValue(true);
        click('[data-action="click->open-with#accept"]');
        await settle();

        expect(ackIds()).toEqual(['r1']);
        expect(opens).toEqual(['/notes/a.md']);
        expect(box().hidden).toBe(true);

        guard.stop();
        stop();
    });

    it('« Dismiss » acks and hides, without opening anything', async () => {
        answer();

        await start();
        emit('editor:ready', { free: false });
        await settle();

        answer({ id: 'r1', paths: ['/notes/a.md'] });
        bell!();
        await settle();

        const opens: string[] = [];
        const stop = on('editor:nav-open_path-requested', ({ action }) => opens.push(action.path));

        click('[data-action="click->open-with#dismiss"]');
        await settle();

        expect(ackIds()).toEqual(['r1']);
        expect(opens).toEqual([]);
        expect(box().hidden).toBe(true);
        stop();
    });

    it('two bells during a read collapse into one more read', async () => {
        invoke.mockImplementation((command: string) => command === 'open_files_pending'
            ? new Promise<{ requests: Request[] }>((resolve) => openReads.push(resolve))
            : Promise.resolve(null));

        await start();
        emit('editor:ready', { free: true });
        await settle();
        expect(openReads).toHaveLength(1);

        bell!();
        bell!();
        openReads[0]!({ requests: [] });
        await settle();

        // The in-flight read, then exactly one more.
        expect(pendingReads()).toBe(2);
    });

    it('a request opened during a read does not come back, even if the read still lists it', async () => {
        answer();

        await start();
        emit('editor:ready', { free: false });
        await settle();

        answer({ id: 'r1', paths: ['/notes/a.md'] });
        bell!();
        await settle();
        expect(question()).toBe('Open “a.md”?');

        invoke.mockImplementation((command: string) => command === 'open_files_pending'
            ? new Promise<{ requests: Request[] }>((resolve) => openReads.push(resolve))
            : Promise.resolve(null));
        bell!();
        await settle();
        expect(openReads).toHaveLength(1);

        click('[data-action="click->open-with#accept"]');
        await settle();
        // The read left before the click: its answer still lists r1.
        openReads[0]!({ requests: [{ id: 'r1', paths: ['/notes/a.md'] }] });
        await settle();

        expect(box().hidden).toBe(true);
        expect(ackIds()).toEqual(['r1']);
    });

    it('without the hub, nothing is called and nothing is shown', async () => {
        delete window.__TAURI__;

        await start();
        emit('editor:ready', { free: true });
        await settle();

        expect(listen).not.toHaveBeenCalled();
        expect(invoke).not.toHaveBeenCalled();
        expect(console.warn).not.toHaveBeenCalled();
        expect(box().hidden).toBe(true);
    });

    it('a refused ack warns, and the opening still happens', async () => {
        answer();

        await start();
        emit('editor:ready', { free: false });
        await settle();

        answer({ id: 'r1', paths: ['/notes/a.md'] });
        bell!();
        await settle();

        invoke.mockImplementation((command: string) => {
            if (command === 'open_files_pending') {
                return Promise.resolve({ requests });
            }
            if (command === 'open_files_ack') {
                return Promise.reject(new Error('closing'));
            }

            return Promise.resolve(null);
        });
        const opens: string[] = [];
        const stop = on('editor:nav-open_path-requested', ({ action }) => opens.push(action.path));

        click('[data-action="click->open-with#accept"]');
        await settle();

        expect(console.warn).toHaveBeenCalled();
        expect(opens).toEqual(['/notes/a.md']);
        expect(box().hidden).toBe(true);
        stop();
    });
});
