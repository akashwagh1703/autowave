import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import RunsTable from '@/modules/automations/RunsTable';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';

export default function Runs({ runs, filters: initialFilters, statuses, automations }) {
    const { timezone } = useTenant();
    const { filters, apply, loading } = useFilters('/automations/runs', initialFilters);

    return (
        <AppLayout title="Run history">
            <Button component={Link} href="/automations" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Automations
            </Button>
            <PageHeader title="Run history" description="Every time an automation ran, what it did, and why it stopped." />

            <Card variant="outlined">
                <div className="flex flex-wrap gap-3 border-b border-slate-200 p-4">
                    <TextField select size="small" label="Automation" value={filters.automation ?? ''} onChange={(event) => apply({ automation: event.target.value || null })} className="min-w-56">
                        <MenuItem value="">All automations</MenuItem>
                        {automations.map((automation) => (
                            <MenuItem key={automation.id} value={automation.id}>
                                {automation.name}
                                {automation.deleted ? ' (deleted)' : ''}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField select size="small" label="Status" value={filters.status ?? ''} onChange={(event) => apply({ status: event.target.value || null })} className="min-w-40">
                        <MenuItem value="">Any status</MenuItem>
                        {statuses.map((status) => (
                            <MenuItem key={status.value} value={status.value}>
                                {status.label}
                            </MenuItem>
                        ))}
                    </TextField>
                </div>
                {loading ? <LinearProgress /> : <div className="h-1" />}
                {runs.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState title="No runs" description={filters.status || filters.automation ? 'Try another filter.' : 'Runs appear here when an automation starts.'} />
                    </div>
                ) : (
                    <RunsTable runs={runs.data} timezone={timezone} />
                )}
            </Card>
            <Pagination meta={runs.meta} noun="runs" />
        </AppLayout>
    );
}
