export default function EmptyState({ icon: Icon, title, description, action }) {
    return (
        <div className="flex flex-col items-center rounded-xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            {Icon ? <Icon className="text-brand-600" sx={{ fontSize: 40 }} /> : null}
            <h2 className="mt-3 text-lg font-semibold text-slate-900">{title}</h2>
            {description ? <p className="mt-1 max-w-md text-sm text-slate-600">{description}</p> : null}
            {action ? <div className="mt-5">{action}</div> : null}
        </div>
    );
}
