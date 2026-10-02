import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { request } from '../../assets/utils/http';
import { CSRF_TOKEN, clearCsrfToken, jsonResponse, setCsrfToken } from './stimulus';

describe('request', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        setCsrfToken();
    });

    afterEach(() => {
        clearCsrfToken();
        vi.unstubAllGlobals();
    });

    it('carries the token of the meta tag in X-CSRF-TOKEN, on GET as on POST', async () => {
        fetchMock.mockResolvedValue(jsonResponse({}));

        await request('/a');
        await request('/b', { method: 'POST', body: {} });

        expect(fetchMock.mock.calls[0][1].headers).toEqual({ 'X-CSRF-TOKEN': CSRF_TOKEN });
        expect(fetchMock.mock.calls[1][1].headers).toEqual({ 'X-CSRF-TOKEN': CSRF_TOKEN });
    });

    it('defaults to GET', async () => {
        fetchMock.mockResolvedValue(jsonResponse({}));

        await request('/a');

        expect(fetchMock.mock.calls[0][1].method).toBe('GET');
    });

    it('turns an object body into a FormData with the same fields', async () => {
        fetchMock.mockResolvedValue(jsonResponse({}));

        await request('/a', { method: 'POST', body: { name: 'a', value: 'b' } });

        const body = fetchMock.mock.calls[0][1].body as FormData;
        expect(body).toBeInstanceOf(FormData);
        expect(body.get('name')).toBe('a');
        expect(body.get('value')).toBe('b');
    });

    it('sends a FormData body as it is', async () => {
        fetchMock.mockResolvedValue(jsonResponse({}));
        const form = new FormData();
        form.append('field', 'value');

        await request('/a', { method: 'POST', body: form });

        expect(fetchMock.mock.calls[0][1].body).toBe(form);
    });

    it('passes signal and keepalive to fetch', async () => {
        fetchMock.mockResolvedValue(jsonResponse({}));
        const controller = new AbortController();

        await request('/a', { signal: controller.signal, keepalive: true });

        expect(fetchMock.mock.calls[0][1].signal).toBe(controller.signal);
        expect(fetchMock.mock.calls[0][1].keepalive).toBe(true);
    });

    it('reads data as the parsed JSON, null on an empty or unreadable body', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ foo: 'bar' }));
        const withBody = await request('/a');
        expect(withBody.data).toEqual({ foo: 'bar' });

        fetchMock.mockResolvedValue(new Response('', { status: 200 }));
        const noBody = await request('/b');
        expect(noBody.data).toBeNull();

        fetchMock.mockResolvedValue(new Response('not json', { status: 200 }));
        const badBody = await request('/c');
        expect(badBody.data).toBeNull();
    });

    it('message is the first genericErrors entry, else the first mappedErrors message, else null', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ genericErrors: ['Generic'], mappedErrors: [{ field: 'x', message: 'Mapped' }] }, 422));
        expect((await request('/a')).message).toBe('Generic');

        fetchMock.mockResolvedValue(jsonResponse({ genericErrors: [], mappedErrors: [{ field: 'x', message: 'Mapped' }] }, 422));
        expect((await request('/b')).message).toBe('Mapped');

        fetchMock.mockResolvedValue(jsonResponse({}, 500));
        expect((await request('/c')).message).toBeNull();
    });

    it('does not throw on a response the server refused, and carries its status', async () => {
        fetchMock.mockResolvedValue(jsonResponse({ genericErrors: ['Nope'] }, 409));

        const result = await request('/a');

        expect(result.ok).toBe(false);
        expect(result.status).toBe(409);
    });

    it('rethrows a network error as it is', async () => {
        fetchMock.mockRejectedValue(new TypeError('Network down'));

        await expect(request('/a')).rejects.toThrow('Network down');
    });

    it('rethrows an abort as it is', async () => {
        fetchMock.mockRejectedValue(new DOMException('Aborted', 'AbortError'));

        await expect(request('/a')).rejects.toThrow('Aborted');
    });
});
