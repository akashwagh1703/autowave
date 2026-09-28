const STYLES = {
    veg: { color: 'border-emerald-600', dot: 'bg-emerald-600', label: 'Veg' },
    non_veg: { color: 'border-red-700', dot: 'bg-red-700', label: 'Non-veg' },
    egg: { color: 'border-amber-500', dot: 'bg-amber-500', label: 'Contains egg' },
};

/** The Indian menu convention: a dot in a square, green for veg, red for non-veg. */
export default function FoodTypeMark({ type, className = '' }) {
    const style = STYLES[type];

    if (!style) {
        return null;
    }

    return (
        <span title={style.label} className={`inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center border-2 align-middle ${style.color} ${className}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${style.dot}`} />
            <span className="sr-only">{style.label}</span>
        </span>
    );
}
