import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import { businessTypeIcon } from './icons';
import StepHeading from './StepHeading';

export default function BusinessTypeStep({ types, value, onChange, error }) {
    return (
        <div>
            <StepHeading
                title="What kind of business do you run?"
                description="We'll set up the right features, website sections and dashboard for this business type. Choose carefully — your setup follows this type."
            />

            <div role="radiogroup" aria-label="Business type" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {types.map((type) => {
                    const Icon = businessTypeIcon(type.icon);
                    const selected = value === type.code;

                    return (
                        <button
                            key={type.code}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            onClick={() => onChange(type.code)}
                            className={`relative flex flex-col items-start rounded-xl border p-4 text-left transition focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none ${
                                selected
                                    ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600'
                                    : 'border-slate-200 bg-white hover:border-brand-500'
                            }`}
                        >
                            {selected ? (
                                <CheckCircleIcon className="absolute top-3 right-3 text-brand-600" fontSize="small" />
                            ) : null}
                            <Icon className="text-brand-600" />
                            <span className="mt-2 font-semibold text-slate-900">{type.name}</span>
                            <span className="mt-1 text-sm text-slate-600">{type.description}</span>
                        </button>
                    );
                })}
            </div>

            {error ? <p className="mt-3 text-sm text-red-600">{error}</p> : null}
        </div>
    );
}
