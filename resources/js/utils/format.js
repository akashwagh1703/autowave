// "revenue_today" -> "Revenue today"
export function humanize(code) {
    const text = String(code ?? '').replace(/[_-]+/g, ' ').trim();

    return text.charAt(0).toUpperCase() + text.slice(1);
}
