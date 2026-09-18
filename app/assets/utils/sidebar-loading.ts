// The loader markup lives once in base.html.twig, translated server-side.
// Controllers use this to show it before a fetch, when no frame is busy yet.
const TEMPLATE_ID = 'sidebar-loading';

export function showLoading(element: Element): void {
    const template = document.getElementById(TEMPLATE_ID);
    if (!(template instanceof HTMLTemplateElement)) {
        return;
    }

    element.replaceChildren(template.content.cloneNode(true));
}
