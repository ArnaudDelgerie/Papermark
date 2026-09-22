import { Application, type ControllerConstructor } from '@hotwired/stimulus';

/** What every test's `<meta name="csrf-token">` carries, as base.html.twig renders it. */
export const CSRF_TOKEN = 'tk-app';

/** Sets `<meta name="csrf-token">` in `document.head`, as base.html.twig renders it. */
export function setCsrfToken(token = CSRF_TOKEN): void {
    let meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.setAttribute('name', 'csrf-token');
        document.head.appendChild(meta);
    }
    meta.setAttribute('content', token);
}

export function clearCsrfToken(): void {
    document.querySelector('meta[name="csrf-token"]')?.remove();
}

/**
 * Mounts `html` with the given controllers registered by hand (no
 * stimulus-bridge), and resolves once Stimulus has connected them. Sets the
 * CSRF meta tag every controller's calls read (assets/utils/http.ts).
 */
export async function mount(html: string, controllers: Record<string, ControllerConstructor>): Promise<Application> {
    setCsrfToken();
    document.body.innerHTML = html;
    const application = Application.start();
    for (const [identifier, controller] of Object.entries(controllers)) {
        application.register(identifier, controller);
    }
    await settle();

    return application;
}

/** Lets pending promises and Stimulus' mutation observers run. */
export async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

export function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/**
 * Empties the DOM first so every controller disconnects (and unsubscribes
 * from window): `application.stop()` alone leaves them listening.
 */
export async function unmount(application: Application): Promise<void> {
    document.body.innerHTML = '';
    await settle();
    application.stop();
    clearCsrfToken();
}
