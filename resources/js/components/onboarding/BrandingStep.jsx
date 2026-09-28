import TextField from '@mui/material/TextField';
import CheckIcon from '@mui/icons-material/Check';
import StepHeading from './StepHeading';

const HEX = /^#[0-9a-fA-F]{6}$/;

export default function BrandingStep({ form, colors }) {
    const color = HEX.test(form.data.primary_color) ? form.data.primary_color : '#4f46e5';

    return (
        <div>
            <StepHeading
                title="Make it yours"
                description="Pick a brand colour and a one-line tagline. You can add your logo later under Website → Design."
            />

            <p className="mb-2 text-sm font-medium text-slate-700">Brand colour</p>
            <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Brand colour">
                {colors.map((preset) => {
                    const selected = form.data.primary_color.toLowerCase() === preset.toLowerCase();

                    return (
                        <button
                            key={preset}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            aria-label={preset}
                            onClick={() => form.setData('primary_color', preset)}
                            className={`flex h-9 w-9 items-center justify-center rounded-full border-2 transition focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:outline-none ${
                                selected ? 'border-slate-900' : 'border-white shadow'
                            }`}
                            style={{ backgroundColor: preset }}
                        >
                            {selected ? <CheckIcon className="text-white" fontSize="small" /> : null}
                        </button>
                    );
                })}

                <label className="ml-1 flex items-center gap-2 text-sm text-slate-600">
                    <input
                        type="color"
                        value={color}
                        onChange={(event) => form.setData('primary_color', event.target.value)}
                        className="h-9 w-9 cursor-pointer rounded border border-slate-200 bg-white p-0.5"
                        aria-label="Custom colour"
                    />
                    Custom
                </label>
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <TextField
                    label="Hex colour"
                    value={form.data.primary_color}
                    onChange={(event) => form.setData('primary_color', event.target.value.trim())}
                    error={Boolean(form.errors.primary_color)}
                    helperText={form.errors.primary_color}
                    slotProps={{ htmlInput: { maxLength: 7, spellCheck: false } }}
                />
                <TextField
                    label="Tagline"
                    value={form.data.tagline}
                    onChange={(event) => form.setData('tagline', event.target.value)}
                    error={Boolean(form.errors.tagline)}
                    helperText={form.errors.tagline ?? `${form.data.tagline.length}/120 · Shown under your name on the website.`}
                    placeholder="Fresh cuts, friendly faces"
                    slotProps={{ htmlInput: { maxLength: 120 } }}
                />
            </div>

            <div className="mt-6 overflow-hidden rounded-xl border border-slate-200">
                <div className="px-5 py-6 text-white" style={{ backgroundColor: color }}>
                    <p className="text-lg font-bold">{form.data.name || 'Your business'}</p>
                    <p className="text-sm opacity-90">{form.data.tagline || 'Your tagline appears here'}</p>
                </div>
                <div className="flex items-center justify-between bg-white px-5 py-3">
                    <span className="text-xs text-slate-500">Preview</span>
                    <span className="rounded-md px-3 py-1 text-xs font-semibold text-white" style={{ backgroundColor: color }}>
                        Contact us
                    </span>
                </div>
            </div>
        </div>
    );
}
