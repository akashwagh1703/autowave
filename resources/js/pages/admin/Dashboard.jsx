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

export default function Dashboard({ stats, businessTypes }) {
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
