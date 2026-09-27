import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import { humanize } from '@/utils/format';

export default function Dashboard({ workspace, modules, engines, widgets }) {
    return (
        <AppLayout title="Dashboard">
            <PageHeader
                title={workspace.name}
                description={workspace.business_type}
                actions={
                    workspace.website_url && modules.includes('website') ? (
                        <Button
                            variant="outlined"
                            href={workspace.website_url}
                            target="_blank"
                            rel="noreferrer"
                            endIcon={<OpenInNewIcon fontSize="small" />}
                        >
                            View website
                        </Button>
                    ) : null
                }
            />

            <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {widgets.map((widget) => (
                    <Card key={widget} variant="outlined">
                        <CardContent>
                            <p className="text-sm text-slate-500">{humanize(widget)}</p>
                            <p className="mt-2 text-2xl font-semibold text-slate-300">—</p>
                        </CardContent>
                    </Card>
                ))}
            </section>
            <p className="mt-3 text-xs text-slate-500">
                Widgets come from your business type. Live figures appear as each module is built.
            </p>

            <section className="mt-8 grid gap-4 md:grid-cols-2">
                <Card variant="outlined">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Engines</h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {engines.map((engine) => (
                                <Chip key={engine} label={humanize(engine)} color="secondary" variant="outlined" />
                            ))}
                        </div>
                    </CardContent>
                </Card>
                <Card variant="outlined">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Enabled modules</h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {modules.map((module) => (
                                <Chip key={module} label={humanize(module)} color="primary" variant="outlined" />
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </section>
        </AppLayout>
    );
}
