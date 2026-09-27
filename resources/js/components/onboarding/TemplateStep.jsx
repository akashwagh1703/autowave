import Chip from '@mui/material/Chip';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import { fontFamily, heroStyle, radius } from '@/utils/websiteTheme';
import StepHeading from './StepHeading';

function TemplatePreview({ template, color, name }) {
    const hero = heroStyle(template.theme, color);
    const button = hero.accent ?? '#ffffff';

    return (
        <div className="overflow-hidden border-b border-slate-200" style={{ fontFamily: fontFamily(template.theme) }} aria-hidden="true">
            <div className="px-4 py-6" style={{ background: hero.background, color: hero.color }}>
                <p className="truncate text-sm font-bold">{name || 'Your business'}</p>
                <div className="mt-2 h-1.5 w-3/4 rounded-full opacity-60" style={{ backgroundColor: hero.color }} />
                <div className="mt-1 h-1.5 w-1/2 rounded-full opacity-40" style={{ backgroundColor: hero.color }} />
                <span
                    className="mt-3 inline-block px-2.5 py-1 text-[10px] font-semibold"
                    style={{
                        borderRadius: radius(template.theme),
                        backgroundColor: button,
                        color: hero.accent ? '#ffffff' : color,
                    }}
                >
                    Book now
                </span>
            </div>
            <div className="grid grid-cols-3 gap-1.5 bg-white p-3">
                {[0, 1, 2].map((index) => (
                    <div key={index} className="h-6 bg-slate-100" style={{ borderRadius: radius(template.theme) }} />
                ))}
            </div>
        </div>
    );
}

export default function TemplateStep({ templates, recommended, value, onChange, color, name, error }) {
    const ordered = [
        ...recommended.map((code) => templates.find((template) => template.code === code)).filter(Boolean),
        ...templates.filter((template) => !recommended.includes(template.code)),
    ];

    return (
        <div>
            <StepHeading
                title="Pick a website style"
                description="Templates only change the look. You can switch any time without losing content."
            />

            <div role="radiogroup" aria-label="Website template" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {ordered.map((template) => {
                    const selected = value === template.code;

                    return (
                        <button
                            key={template.code}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            onClick={() => onChange(template.code)}
                            className={`relative overflow-hidden rounded-xl border text-left transition focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none ${
                                selected ? 'border-brand-600 ring-1 ring-brand-600' : 'border-slate-200 hover:border-brand-500'
                            }`}
                        >
                            <TemplatePreview template={template} color={color} name={name} />
                            <div className="p-3">
                                <div className="flex items-center gap-2">
                                    <span className="font-semibold text-slate-900">{template.name}</span>
                                    {recommended.includes(template.code) ? (
                                        <Chip label="Recommended" size="small" color="primary" variant="outlined" className="h-5 text-[11px]" />
                                    ) : null}
                                </div>
                                <p className="mt-1 text-xs text-slate-600">{template.description}</p>
                            </div>
                            {selected ? (
                                <CheckCircleIcon className="absolute top-2 right-2 rounded-full bg-white text-brand-600" fontSize="small" />
                            ) : null}
                        </button>
                    );
                })}
            </div>

            {error ? <p className="mt-3 text-sm text-red-600">{error}</p> : null}
        </div>
    );
}
