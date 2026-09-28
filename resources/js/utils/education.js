// Money as integer paise/cents so plans add up exactly, like the server's bcmath.
const toCents = (value) => Math.round((Number(value) || 0) * 100);
const fromCents = (cents) => (cents / 100).toFixed(2);

// "2026-01-31" + 1 month -> "2026-02-28" (Carbon's addMonthsNoOverflow)
export function addMonths(date, months) {
    const [year, month, day] = date.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + months, 1));
    const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    target.setUTCDate(Math.min(day, lastDay));

    return target.toISOString().slice(0, 10);
}

// Equal monthly instalments; the rounding remainder goes on the last one (FeePlan::split).
export function splitFee(net, count, firstDue) {
    const total = toCents(net);

    if (total <= 0 || !firstDue) {
        return [];
    }

    const n = Math.max(1, Number(count) || 1);
    const share = Math.floor(total / n);

    return Array.from({ length: n }, (_, index) => ({
        due_on: addMonths(firstDue, index),
        amount: fromCents(index === n - 1 ? total - share * (n - 1) : share),
    })).filter((row) => Number(row.amount) > 0);
}

export function netFee(feeTotal, discount) {
    return fromCents(Math.max(0, toCents(feeTotal) - toCents(discount)));
}

export function sumAmounts(rows) {
    return fromCents(rows.reduce((sum, row) => sum + toCents(row.amount), 0));
}
