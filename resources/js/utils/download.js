/** Trigger a browser download from a data URI (SVG/PNG QR codes, etc.). */
export function downloadDataUri(dataUri, filename) {
    if (!dataUri) {
        return;
    }

    const link = document.createElement('a');
    link.href = dataUri;
    link.download = filename;
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    link.remove();
}
