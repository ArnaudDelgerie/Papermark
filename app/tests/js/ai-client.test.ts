import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AIPromptContext } from '@milkdown/crepe/feature/ai';
import AiClient, { type AiClientOptions } from '../../assets/editor/ai-client';
import { jsonResponse, settle } from './stimulus';

/**
 * A fake EventSource driven by hand: `open()`, `message()` and
 * `errorClosed()` fire the events ai-client.ts listens for. jsdom has no
 * EventSource, and the real one couldn't be driven from a test anyway.
 */
class FakeEventSource {
    static readonly CONNECTING = 0;
    static readonly OPEN = 1;
    static readonly CLOSED = 2;
    static instances: FakeEventSource[] = [];

    readyState = FakeEventSource.CONNECTING;
    closed = false;
    #listeners = new Map<string, Array<{ listener: (event: Event) => void; once: boolean }>>();

    constructor(readonly url: string | URL) {
        FakeEventSource.instances.push(this);
    }

    addEventListener(type: string, listener: (event: Event) => void, options?: boolean | AddEventListenerOptions): void {
        const once = typeof options === 'object' && options !== null && options.once === true;
        const entries = this.#listeners.get(type) ?? [];
        entries.push({ listener, once });
        this.#listeners.set(type, entries);
    }

    close(): void {
        this.readyState = FakeEventSource.CLOSED;
        this.closed = true;
    }

    open(): void {
        this.readyState = FakeEventSource.OPEN;
        this.#dispatch('open', new Event('open'));
    }

    message(data: unknown): void {
        this.#dispatch('message', { data: JSON.stringify(data) } as MessageEvent);
    }

    /** The hub dropping the connection: readyState is CLOSED by the time 'error' fires. */
    errorClosed(): void {
        this.readyState = FakeEventSource.CLOSED;
        this.#dispatch('error', new Event('error'));
    }

    #dispatch(type: string, event: Event): void {
        const entries = this.#listeners.get(type) ?? [];
        this.#listeners.set(type, entries.filter((entry) => !entry.once));
        entries.forEach((entry) => entry.listener(event));
    }
}

const OPTIONS: AiClientOptions = {
    csrfToken: 'tk-app',
    urls: { subscribe: '/ai/subscribe', instruct: '/ai/instruct', abort: '/ai/abort' },
    mercureUrl: 'https://hub.example/.well-known/mercure',
    topic: 'session-1',
    requestFailedMessage: 'The AI request failed',
};

const CONTEXT: AIPromptContext = { instruction: 'Summarize the selection', document: '# Doc', selection: 'Doc' };

describe('AiClient', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    const callsTo = (url: string): Array<[string, RequestInit]> =>
        (fetchMock.mock.calls as Array<[string, RequestInit]>).filter(([calledUrl]) => calledUrl === url);

    /**
     * Drives a generation up to where it waits for stream events: subscribes,
     * opens the fake source, sends the instruction, and reads back the id the
     * client generated from the /ai/instruct request body.
     */
    async function startGeneration(context = CONTEXT): Promise<{
        iterator: AsyncIterator<string>;
        first: Promise<IteratorResult<string>>;
        controller: AbortController;
        source: FakeEventSource;
        id: string;
    }> {
        const client = new AiClient(OPTIONS);
        const provider = client.createProvider();
        const controller = new AbortController();
        const iterator = provider(context, controller.signal)[Symbol.asyncIterator]();

        const first = iterator.next();
        await settle();
        const source = FakeEventSource.instances.at(-1)!;
        source.open();
        await settle();

        const [, init] = callsTo(OPTIONS.urls.instruct).at(-1)!;
        const { id } = JSON.parse(init.body as string) as { id: string };

        return { iterator, first, controller, source, id };
    }

    beforeEach(() => {
        FakeEventSource.instances.length = 0;
        vi.stubGlobal('EventSource', FakeEventSource as unknown as typeof EventSource);
        fetchMock = vi.fn(async () => jsonResponse({}));
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('yields chunks in order, and done ends the generation without aborting the request', async () => {
        const { iterator, first, id, source } = await startGeneration();

        source.message({ id, type: 'chunk', content: 'Hello ' });
        await settle();
        await expect(first).resolves.toEqual({ value: 'Hello ', done: false });

        const second = iterator.next();
        source.message({ id, type: 'chunk', content: 'world' });
        await settle();
        await expect(second).resolves.toEqual({ value: 'world', done: false });

        const third = iterator.next();
        source.message({ id, type: 'done' });
        await settle();
        await expect(third).resolves.toEqual({ value: undefined, done: true });

        expect(callsTo(OPTIONS.urls.abort)).toHaveLength(0);
        expect(source.closed).toBe(true);
    });

    it('ignores a message for another id', async () => {
        const { first, id, source } = await startGeneration();

        source.message({ id: 'another-request', type: 'chunk', content: 'not for us' });
        source.message({ id, type: 'chunk', content: 'for us' });
        await settle();

        await expect(first).resolves.toEqual({ value: 'for us', done: false });
    });

    it('a refused /ai/subscribe throws its genericErrors[0]', async () => {
        fetchMock.mockImplementation(async (url: string) =>
            url === OPTIONS.urls.subscribe ? jsonResponse({ genericErrors: ['Not allowed'] }, 403) : jsonResponse({}),
        );

        const provider = new AiClient(OPTIONS).createProvider();
        const iterator = provider(CONTEXT, new AbortController().signal)[Symbol.asyncIterator]();

        await expect(iterator.next()).rejects.toThrow('Not allowed');
    });

    it('a refused /ai/instruct without a body falls back to requestFailedMessage', async () => {
        fetchMock.mockImplementation(async (url: string) =>
            url === OPTIONS.urls.instruct ? new Response('', { status: 500 }) : jsonResponse({}),
        );

        const provider = new AiClient(OPTIONS).createProvider();
        const iterator = provider(CONTEXT, new AbortController().signal)[Symbol.asyncIterator]();
        const first = iterator.next();
        await settle();
        FakeEventSource.instances.at(-1)!.open();

        await expect(first).rejects.toThrow(OPTIONS.requestFailedMessage);
        expect(callsTo(OPTIONS.urls.abort)).toHaveLength(0);
    });

    it('an error message throws its own text, without an abort', async () => {
        const { first, id, source } = await startGeneration();
        // Attached before the rejection actually happens (below), so the
        // promise is never briefly unhandled.
        const rejection = expect(first).rejects.toThrow('Provider exploded');

        source.message({ id, type: 'error', error: 'Provider exploded' });
        await settle();

        await rejection;
        expect(callsTo(OPTIONS.urls.abort)).toHaveLength(0);
        expect(source.closed).toBe(true);
    });

    it('a source closed by the hub throws requestFailedMessage and sends the abort with keepalive', async () => {
        const { first, id, source } = await startGeneration();
        const rejection = expect(first).rejects.toThrow(OPTIONS.requestFailedMessage);

        source.errorClosed();
        await settle();

        await rejection;
        const abortCalls = callsTo(OPTIONS.urls.abort);
        expect(abortCalls).toHaveLength(1);
        const [, init] = abortCalls[0];
        expect(JSON.parse(init.body as string)).toEqual({ id });
        expect(init.keepalive).toBe(true);
        expect(source.closed).toBe(true);
    });

    it('an aborted signal ends the generation and sends the abort', async () => {
        const { first, controller, id, source } = await startGeneration();

        controller.abort();
        await settle();

        await expect(first).resolves.toEqual({ value: undefined, done: true });
        const abortCalls = callsTo(OPTIONS.urls.abort);
        expect(abortCalls).toHaveLength(1);
        expect(JSON.parse(abortCalls[0][1].body as string)).toEqual({ id });
        expect(source.closed).toBe(true);
    });

    it('sends id, instruction, document and selection with X-CSRF-TOKEN on /ai/instruct', async () => {
        const { first, id, source } = await startGeneration();
        const [, init] = callsTo(OPTIONS.urls.instruct).at(-1)!;

        expect(JSON.parse(init.body as string)).toEqual({
            id,
            instruction: CONTEXT.instruction,
            document: CONTEXT.document,
            selection: CONTEXT.selection,
        });
        expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe(OPTIONS.csrfToken);
        expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json');

        source.message({ id, type: 'done' });
        await settle();
        await first;
    });
});
