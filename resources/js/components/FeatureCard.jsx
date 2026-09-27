import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';

export default function FeatureCard({ icon: Icon, title, description }) {
    return (
        <Card variant="outlined" className="h-full">
            <CardContent className="flex gap-4">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-100 text-brand-700">
                    <Icon fontSize="small" />
                </span>
                <div>
                    <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                    <p className="mt-1 text-sm text-slate-600">{description}</p>
                </div>
            </CardContent>
        </Card>
    );
}
