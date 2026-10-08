import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Tooltip from '@mui/material/Tooltip';
import LockIcon from '@mui/icons-material/Lock';
import { humanize } from '@/utils/format';
import { lockedModules, withDependencies } from './modules';
import StepHeading from './StepHeading';

export default function CapabilitiesStep({ modules, modulesByCode, businessType, selected, onChange, error }) {
    const required = businessType?.required_modules ?? [];
    const recommended = businessType?.modules ?? [];
    const locked = lockedModules(selected, required, modulesByCode);

    const toggle = (code) => {
        if (selected.includes(code)) {
            if (!locked[code]) {
                onChange(selected.filter((other) => other !== code));
            }
        } else {
            onChange(withDependencies([...selected, code], modulesByCode));
        }
    };

    const groups = modules.reduce((result, module) => {
        (result[module.type] ??= []).push(module);
        return result;
    }, {});

    return (
        <div>
            <StepHeading
                title="Choose your capabilities"
                description="We've ticked what most businesses like yours use. Required features stay locked. You can leave optional ones off for now."
            />

            {businessType?.engines?.length ? (
                <p className="mb-4 text-sm text-slate-600">
                    Included core features:{' '}
                    {businessType.engines.map((engine) => (
                        <Chip key={engine.code} label={engine.name} size="small" className="mr-1 mb-1" />
                    ))}
                </p>
            ) : null}

            {Object.entries(groups).map(([type, items]) => (
                <section key={type} className="mb-5">
                    <h3 className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">{humanize(type)}</h3>
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {items.map((module) => {
                            const checked = selected.includes(module.code);
                            const reason = checked ? locked[module.code] : null;

                            return (
                                <li key={module.code}>
                                    <label
                                        className={`flex h-full cursor-pointer items-start gap-2 rounded-lg border p-3 ${
                                            checked ? 'border-brand-100 bg-brand-50' : 'border-slate-200 bg-white'
                                        } ${reason ? 'cursor-default' : ''}`}
                                    >
                                        <Checkbox
                                            checked={checked}
                                            disabled={Boolean(reason)}
                                            onChange={() => toggle(module.code)}
                                            size="small"
                                            className="-mt-1 -ml-1"
                                            slotProps={{ input: { 'aria-describedby': `module-${module.code}-description` } }}
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-1.5 text-sm font-medium text-slate-900">
                                                {module.name}
                                                {recommended.includes(module.code) ? (
                                                    <Chip label="Recommended" size="small" color="primary" variant="outlined" className="h-5 text-[11px]" />
                                                ) : null}
                                                {reason ? (
                                                    <Tooltip title={reason}>
                                                        <LockIcon className="text-slate-400" sx={{ fontSize: 14 }} aria-label={reason} />
                                                    </Tooltip>
                                                ) : null}
                                            </span>
                                            <span id={`module-${module.code}-description`} className="mt-0.5 block text-xs text-slate-600">
                                                {reason ?? module.description}
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}

            {error ? <p className="text-sm text-red-600">{error}</p> : null}
        </div>
    );
}
