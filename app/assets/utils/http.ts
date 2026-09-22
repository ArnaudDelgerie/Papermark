export interface HttpOptions {
    method?: 'GET' | 'POST' | 'DELETE';
    body?: FormData | Record<string, string>;
    signal?: AbortSignal;
    keepalive?: boolean;
}

export interface HttpResult<T> {
    ok: boolean;
    status: number;
    data: T | null;
    message: string | null;
}

/** What every route answers on a refusal (S6): only `message` reads it here. */
interface ErrorBody {
    genericErrors?: string[];
    mappedErrors?: { message: string }[];
}

/**
 * The one client every call to the server goes through (CODE_REVIEW_3,
 * décision 4). Poses the CSRF token from `<meta name="csrf-token">`, read at
 * every call so a test can set it after the module loads; sends the body as
 * `FormData`, an object converted to one; reads the response as JSON.
 *
 * A network error or an abort is rethrown as is — the caller keeps its
 * `catch`. A response the server refused (4xx/5xx) never throws: `ok` says
 * so, and `message` is the first of `genericErrors`, else the first
 * `mappedErrors[].message`, else `null`.
 */
export async function request<T>(url: string, options: HttpOptions = {}): Promise<HttpResult<T>> {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const response = await fetch(url, {
        method: options.method ?? 'GET',
        headers: { 'X-CSRF-TOKEN': token },
        body: options.body === undefined ? undefined : toFormData(options.body),
        signal: options.signal,
        keepalive: options.keepalive,
    });

    const data: (T & ErrorBody) | null = await response.json().catch(() => null);
    const message = data?.genericErrors?.[0] ?? data?.mappedErrors?.[0]?.message ?? null;

    return { ok: response.ok, status: response.status, data: data as T | null, message };
}

function toFormData(body: FormData | Record<string, string>): FormData {
    if (body instanceof FormData) {
        return body;
    }

    const form = new FormData();
    for (const [key, value] of Object.entries(body)) {
        form.append(key, value);
    }

    return form;
}
