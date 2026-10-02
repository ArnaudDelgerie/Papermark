/**
 * The title + "Effacer le rapport" (×) button shown at the top of the export
 * and import report blocks (lot 06-archive.md): shared because both blocks
 * build it identically, only the rest of the report content differs.
 */
export function renderReportHeader(container: HTMLElement, title: string, clearLabel: string, onClear: () => void): void {
    const header = document.createElement('div');
    header.className = 'export-report-header';

    const heading = document.createElement('h2');
    heading.textContent = title;
    header.appendChild(heading);

    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'export-report-clear';
    clearButton.textContent = '×';
    clearButton.title = clearLabel;
    clearButton.setAttribute('aria-label', clearLabel);
    clearButton.addEventListener('click', onClear);
    header.appendChild(clearButton);

    container.appendChild(header);
}
