import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import StepHeading from './StepHeading';

function Row({ label, children, onEdit }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 py-3 last:border-b-0">
            <div className="min-w-0">
                <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
                <dd className="mt-1 text-sm break-words text-slate-900">{children}</dd>
            </div>
            <Button size="small" onClick={onEdit}>
                Edit
            </Button>
        </div>
    );
}

export default function ReviewStep({ data, businessType, modulesByCode, template, domain, generalError, onEdit }) {
    return (
        <div>
            <StepHeading
                title="Ready to go?"
                description="We'll create your workspace, website and free web address in a few seconds."
            />

            {generalError ? (
                <Alert severity="error" className="mb-4">
                    {generalError}
                </Alert>
            ) : null}

            <dl className="rounded-xl border border-slate-200 px-4">
                <Row label="Business type" onEdit={() => onEdit(0)}>
                    {businessType?.name}
                </Row>
                <Row label="Business" onEdit={() => onEdit(1)}>
                    <span className="font-medium">{data.name}</span>
                    <span className="block text-slate-600">
                        {[data.phone, data.email, data.city].filter(Boolean).join(' · ')}
                    </span>
                    <span className="block text-slate-600">{domain}</span>
                </Row>
                <Row label="Capabilities" onEdit={() => onEdit(2)}>
                    {data.modules.map((code) => modulesByCode[code]?.name ?? code).join(', ') || 'Core features only'}
                </Row>
                <Row label="Branding" onEdit={() => onEdit(3)}>
                    <span className="inline-flex items-center gap-2">
                        <span className="inline-block h-4 w-4 rounded border border-slate-200" style={{ backgroundColor: data.primary_color }} />
                        {data.primary_color}
                    </span>
                    {data.tagline ? <span className="block text-slate-600">“{data.tagline}”</span> : null}
                </Row>
                <Row label="Website style" onEdit={() => onEdit(4)}>
                    {template?.name}
                </Row>
            </dl>
        </div>
    );
}
