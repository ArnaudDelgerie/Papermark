/**
 * Shows a path cut from its start, not its end: the last segment (the file,
 * or the folder) stays whole, the part before it gives way first with a
 * leading ellipsis. CSS only cuts at the end, hence the two spans laid out by
 * `.path-label` (layout.css). The whole path stays in the text content and
 * in the title.
 */
export function showPath(target: HTMLElement, path: string): void {
    // A trailing slash is not a segment: "/a/b/" keeps "b" whole.
    const trimmed = path.length > 1 ? path.replace(/\/+$/, '') : path;
    const cut = trimmed.lastIndexOf('/') + 1;

    const head = document.createElement('span');
    head.className = 'path-label-head';
    head.textContent = path.slice(0, cut);

    const tail = document.createElement('span');
    tail.className = 'path-label-tail';
    tail.textContent = path.slice(cut);

    target.classList.add('path-label');
    target.replaceChildren(...(cut > 0 ? [head, tail] : [tail]));
    if (path) {
        target.title = path;
    } else {
        target.removeAttribute('title');
    }
}
