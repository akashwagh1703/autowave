import { Link } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import AdminLayout from '@/layouts/AdminLayout';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';

const statCards = [
    { key: 'tenants', label: 'Tenants' },
    { key: 'active_tenants', label: 'Active tenants' },
    { key: 'suspended_tenants', label: 'Suspended tenants' },
    { key: 'users', label: 'Users' },
];

const number = (value) => new Intl.NumberFormat().format(value ?? 0);

export default function Dashboard({ stats, ai, businessTypes }) {
    return (
        <AdminLayout title="Admin dashboard">
            <PageHeader title="Platform overview" />

            <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {statCards.map((card) => (
                    <Card key={card.key} variant="outlined">
                        <CardContent>
                            <p className="text-sm text-slate-500">{card.label}</p>
                            <p className="mt-2 text-3xl font-semibold text-slate-900">{stats[card.key]}</p>
                        </CardContent>
                    </Card>
                ))}
            </section>

            {stats.pending_payments > 0 ? (
                <Alert
                    severity="warning"
                    sx={{ mt: 2 }}
                    action={
                        <Button component={Link} href="/billing/payments" color="inherit" size="small">
                            Review
                        </Button>
                    }
                >
                    {stats.pending_payments} {stats.pending_payments === 1 ? 'payment is' : 'payments are'} waiting to be checked.
                </Alert>
            ) : null}

            {stats.new_demo_requests > 0 ? (
                <Alert
                    severity="info"
                    sx={{ mt: 2 }}
                    action={
                        <Button component={Link} href="/demo-requests" color="inherit" size="small">
                            Open
                        </Button>
                    }
                >
                    {stats.new_demo_requests} new {stats.new_demo_requests === 1 ? 'demo request is' : 'demo requests are'} waiting for a call.
                </Alert>
            ) : null}

            {ai ? (
                <Card variant="outlined" className="mt-4">
                    <CardContent className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p className="text-sm text-slate-500">AI this month</p>
                            <p className="mt-1 text-lg font-semibold text-slate-900">
                                {number(ai.tokens)} tokens · {number(ai.requests)} requests · ${ai.cost.toFixed(2)}
                            </p>
                        </div>
                        <Button component={Link} href="/ai-usage" size="small">
                            View AI usage
                        </Button>
                    </CardContent>
                </Card>
            ) : null}

            <Card variant="outlined" className="mt-8">
                <CardContent>
                    <h2 className="font-semibold text-slate-900">Business types</h2>
                    <div className="mt-3 overflow-x-auto">
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Name</TableCell>
                                    <TableCell>Code</TableCell>
                                    <TableCell>Version</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Onboarding</TableCell>
                                    <TableCell align="right">Tenants</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {businessTypes.map((type) => (
                                    <TableRow key={`${type.code}-${type.version}`}>
                                        <TableCell>{type.name}</TableCell>
                                        <TableCell>
                                            <code className="text-xs">{type.code}</code>
                                        </TableCell>
                                        <TableCell>{type.version}</TableCell>
                                        <TableCell>
                                            <StatusChip status={type.status} />
                                        </TableCell>
                                        <TableCell>{type.is_public ? 'Public' : 'Hidden'}</TableCell>
                                        <TableCell align="right">{type.tenants_count}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
