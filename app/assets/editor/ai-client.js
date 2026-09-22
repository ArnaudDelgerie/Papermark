/**
 * Talks to the AI worker on behalf of the editor: subscribes to the Mercure
 * topic, posts instructions, and turns the resulting SSE stream into the
 * async generator Crepe's AI feature expects. Isolated from the Stimulus
 * controller so it only needs URLs/CSRF/Mercure config, not the editor
 * instance itself — aborting the in-editor generation (Crepe commands) stays
 * the controller's job, see #discardAi in editor_controller.js.
 */
export default class AiClient {
    #csrfToken;
    #urls;
    #mercureUrl;
    #topic;
    #requestFailedMessage;
    #source = null;
    #requests = new Map();

    /**
     * @param {Object} options
     * @param {string} options.csrfToken
     * @param {{ subscribe: string, instruct: string, abort: string }} options.urls
     * @param {string} options.mercureUrl
     * @param {string} options.topic
     * @param {string} [options.requestFailedMessage]
     */
    constructor({ csrfToken, urls, mercureUrl, topic, requestFailedMessage }) {
        this.#csrfToken = csrfToken;
        this.#urls = urls;
        this.#mercureUrl = mercureUrl;
        this.#topic = topic;
        this.#requestFailedMessage = requestFailedMessage ?? 'The AI request failed';
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
    createProvider() {
        return async function* aiProvider(context, signal) {
            let id = null;
            let finished = false;

            try {
                await this.#ensureSubscription();

                id = window.crypto.randomUUID();
                const request = this.#createRequest(id);

                const response = await this.#post(this.#urls.instruct, {
                    id,
                    instruction: context.instruction,
                    document: context.document,
                    selection: context.selection,
                });

                if (!response.ok) {
                    finished = true;
                    const data = await response.json().catch(() => ({}));
                    throw new Error(data.genericErrors?.[0] || this.#requestFailedMessage);
                }

                while (true) {
                    const payload = await request.next(signal);
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
                    this.#post(this.#urls.abort, { id }, { keepalive: true }).catch((err) => console.error('Failed to abort AI request:', err));
                }
            }
        }.bind(this);
    }

    close() {
        this.#closeSource();
    }

    /**
     * Asks the server to ensure the subscriber cookie is fresh (it re-mints only
     * if needed), then opens the EventSource and resolves once the hub accepts it.
     */
    async #ensureSubscription() {
        const response = await this.#post(this.#urls.subscribe, {});
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.genericErrors?.[0] || this.#requestFailedMessage);
        }

        await this.#openSource();
    }

    /**
     * Opens the EventSource on the session topic and resolves once the hub accepted it.
     */
    #openSource() {
        this.#closeSource();

        const url = new URL(this.#mercureUrl);
        url.searchParams.append('topic', this.#topic);
        const source = new EventSource(url, { withCredentials: true });
        this.#source = source;

        source.addEventListener('message', (event) => {
            const payload = JSON.parse(event.data);
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
                this.#requests.forEach((request) => request.push({ type: 'error', error, connectionLost: true }));
            });
        });
    }

    #closeSource() {
        this.#source?.close();
        this.#source = null;
    }

    /**
     * Queues the events of one request until the provider consumes them.
     */
    #createRequest(id) {
        const events = [];
        let wake = null;

        const request = {
            push: (payload) => {
                events.push(payload);
                wake?.();
            },
            next: async (signal) => {
                while (events.length === 0) {
                    if (signal?.aborted) {
                        return null;
                    }

                    await new Promise((resolve) => {
                        wake = resolve;
                        signal?.addEventListener('abort', resolve, { once: true });
                    });
                    signal?.removeEventListener('abort', wake);
                    wake = null;
                }

                return events.shift();
            },
        };

        this.#requests.set(id, request);

        return request;
    }

    #post(url, body, options = {}) {
        return fetch(url, {
            ...options,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.#csrfToken,
            },
            body: JSON.stringify(body),
        });
    }
}
