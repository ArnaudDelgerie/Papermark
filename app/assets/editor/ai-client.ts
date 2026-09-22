import type { AIPromptContext, AIProvider } from '@milkdown/crepe/feature/ai';
import { request } from '../utils/http';

export interface AiClientOptions {
    urls: { subscribe: string; instruct: string; abort: string };
    mercureUrl: string;
    topic: string;
    requestFailedMessage: string;
}

/** The shape AiInstructionHandler::publish() gives every event; only the client sets `connectionLost`. */
type AiMessage =
    | { id: string; type: 'chunk'; content: string }
    | { id: string; type: 'done' }
    | { id: string; type: 'error'; error: string; connectionLost?: boolean };

interface PendingRequest {
    push: (payload: AiMessage) => void;
    next: (signal?: AbortSignal) => Promise<AiMessage | null>;
}

/**
 * Talks to the AI worker on behalf of the editor: subscribes to the Mercure
 * topic, posts instructions, and turns the resulting SSE stream into the
 * async generator Crepe's AI feature expects. Isolated from the Stimulus
 * controller so it only needs URLs/Mercure config, not the editor
 * instance itself — aborting the in-editor generation (Crepe commands) stays
 * the controller's job, see #discardAi in editor_controller.ts.
 */
export default class AiClient {
    #urls: AiClientOptions['urls'];
    #mercureUrl: string;
    #topic: string;
    #requestFailedMessage: string;
    #source: EventSource | null = null;
    #requests = new Map<string, PendingRequest>();

    constructor({ urls, mercureUrl, topic, requestFailedMessage }: AiClientOptions) {
        this.#urls = urls;
        this.#mercureUrl = mercureUrl;
        this.#topic = topic;
        this.#requestFailedMessage = requestFailedMessage;
    }

    /**
     * Creates the AIProvider that Crepe calls when the user triggers an AI action.
     *
     * The EventSource is opened per request: /ai/subscribe is called first (the
     * server decides whether to re-mint the cookie), then the connection waits
     * for `open` before the instruction is sent. The connection is closed in the
     * finally block, so no permanent subscription is kept. Crepe allows only one
     * generation at a time, so there is never a second connection. Each request
     * has its own id: the worker echoes it and events of other requests are ignored.
     */
    createProvider(): AIProvider {
        return this.#aiProvider.bind(this);
    }

    close(): void {
        this.#closeSource();
    }

    async *#aiProvider(context: AIPromptContext, signal: AbortSignal): AsyncGenerator<string> {
        let id: string | null = null;
        let finished = false;

        try {
            await this.#ensureSubscription();

            id = window.crypto.randomUUID();
            const pending = this.#createRequest(id);

            const result = await request(this.#urls.instruct, {
                method: 'POST',
                body: { id, instruction: context.instruction, document: context.document, selection: context.selection },
            });

            if (!result.ok) {
                finished = true;
                throw new Error(result.message ?? this.#requestFailedMessage);
            }

            while (true) {
                const payload = await pending.next(signal);
                if (payload === null) {
                    return;
                }

                if (payload.type === 'chunk') {
                    yield payload.content;
                } else if (payload.type === 'done') {
                    finished = true;
                    return;
                } else if (payload.type === 'error') {
                    // A lost connection leaves the worker running: let finally abort it.
                    finished = !payload.connectionLost;
                    throw new Error(payload.error || this.#requestFailedMessage);
                }
            }
        } finally {
            if (id !== null) {
                this.#requests.delete(id);
            }
            this.#closeSource();
            // Aborted by the user or interrupted: stop the worker, which serves one request at a time.
            // keepalive: the abort must survive leaving the page right after a confirm.
            if (!finished && id !== null) {
                request(this.#urls.abort, { method: 'POST', body: { id }, keepalive: true }).catch((err: unknown) => console.error('Failed to abort AI request:', err));
            }
        }
    }

    /**
     * Asks the server to ensure the subscriber cookie is fresh (it re-mints only
     * if needed), then opens the EventSource and resolves once the hub accepts it.
     */
    async #ensureSubscription(): Promise<void> {
        const result = await request(this.#urls.subscribe, { method: 'POST', body: {} });
        if (!result.ok) {
            throw new Error(result.message ?? this.#requestFailedMessage);
        }

        await this.#openSource();
    }

    /**
     * Opens the EventSource on the session topic and resolves once the hub accepted it.
     */
    #openSource(): Promise<void> {
        this.#closeSource();

        const url = new URL(this.#mercureUrl);
        url.searchParams.append('topic', this.#topic);
        const source = new EventSource(url, { withCredentials: true });
        this.#source = source;

        source.addEventListener('message', (event) => {
            const payload = JSON.parse((event as MessageEvent<string>).data) as AiMessage;
            this.#requests.get(payload.id)?.push(payload);
        });

        return new Promise((resolve, reject) => {
            source.addEventListener('open', () => {
                resolve();
            }, { once: true });

            source.addEventListener('error', () => {
                // Transient errors reconnect on their own; only a closed source is lost.
                if (source.readyState !== EventSource.CLOSED) {
                    return;
                }
                // Ignore errors from a source we already replaced or closed.
                if (this.#source !== source) {
                    return;
                }

                const error = this.#requestFailedMessage;
                reject(new Error(error));
                this.#requests.forEach((request, id) => request.push({ type: 'error', id, error, connectionLost: true }));
            });
        });
    }

    #closeSource(): void {
        this.#source?.close();
        this.#source = null;
    }

    /**
     * Queues the events of one request until the provider consumes them.
     */
    #createRequest(id: string): PendingRequest {
        const events: AiMessage[] = [];
        let wake: (() => void) | null = null;

        const request: PendingRequest = {
            push: (payload) => {
                events.push(payload);
                wake?.();
            },
            next: async (signal) => {
                while (events.length === 0) {
                    if (signal?.aborted) {
                        return null;
                    }

                    await new Promise<void>((resolve) => {
                        const onAbort = (): void => resolve();
                        wake = onAbort;
                        signal?.addEventListener('abort', onAbort, { once: true });
                    });
                    if (wake !== null) {
                        signal?.removeEventListener('abort', wake);
                    }
                    wake = null;
                }

                return events.shift()!;
            },
        };

        this.#requests.set(id, request);

        return request;
    }

}
