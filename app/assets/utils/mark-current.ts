/**
 * UX-09, lot 10: marks the entry of the file the editor shows, in a tree or
 * in a history: `aria-current` for assistive tech, `.is-current` for the
 * style, and the `<details>` of its ancestors open so it is actually
 * visible. Marks are moved, never stacked: one entry at most carries one.
 */
export function markCurrentFile(root: ParentNode, file: string | null): void {
    let current: HTMLElement | null = null;
    for (const link of root.querySelectorAll<HTMLElement>('a[data-path]')) {
        const isCurrent = link.dataset.path === file;
        link.classList.toggle('is-current', isCurrent);
        if (isCurrent) {
            link.setAttribute('aria-current', 'true');
            current = link;
        } else {
            link.removeAttribute('aria-current');
        }
    }

    if (current === null) {
        return;
    }
    for (let element = current.parentElement; element !== null && element !== root; element = element.parentElement) {
        if (element instanceof HTMLDetailsElement) {
            element.open = true;
        }
    }
}
