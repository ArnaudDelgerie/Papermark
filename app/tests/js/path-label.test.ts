import { describe, expect, it } from 'vitest';
import { showPath } from '../../assets/utils/path-label';

function parts(target: HTMLElement): Array<[string, string]> {
    return [...target.children].map((child) => [child.className, child.textContent ?? '']);
}

describe('a path cut from its start', () => {
    it('keeps the file whole after its folders', () => {
        const target = document.createElement('span');
        showPath(target, '/home/me/notes/readme.md');

        expect(parts(target)).toEqual([
            ['path-label-head', '/home/me/notes/'],
            ['path-label-tail', 'readme.md'],
        ]);
        expect(target.classList.contains('path-label')).toBe(true);
        expect(target.textContent).toBe('/home/me/notes/readme.md');
        expect(target.title).toBe('/home/me/notes/readme.md');
    });

    it('keeps the last folder whole, a trailing slash included', () => {
        const target = document.createElement('p');
        showPath(target, '/home/me/project/');

        expect(parts(target)).toEqual([
            ['path-label-head', '/home/me/'],
            ['path-label-tail', 'project/'],
        ]);
    });

    it('shows a name without folders as the tail alone', () => {
        const target = document.createElement('span');
        showPath(target, 'Untitled');

        expect(parts(target)).toEqual([['path-label-tail', 'Untitled']]);
    });

    it('replaces what the last call showed, and drops the title when empty', () => {
        const target = document.createElement('p');
        showPath(target, '/a/b');
        showPath(target, '');

        expect(target.textContent).toBe('');
        expect(target.hasAttribute('title')).toBe(false);
    });
});
