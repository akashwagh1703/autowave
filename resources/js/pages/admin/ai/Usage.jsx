import { router } from '@inertiajs/react';
import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import LinearProgress from '@mui/material/LinearProgress';
import Pagination from '@mui/material/Pagination';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import AdminLayout from '@/layouts/AdminLayout';
import PageHeader from '@/components/PageHeader';

const number = (value) => new Intl.NumberFormat().format(value ?? 0);
const money = (value) => `$${Number(value ?? 0).toFixed(4)}`;

function LimitDialog({ tenant, defaultCap, onClose }) {
    const [value, setValue] = useState(tenant.custom_cap ? String(tenant.cap) : '');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState(null);

    const save = (monthlyTokens) =>
        router.put(
            `/tenants/${tenant.id}/ai-limit`,
            { monthly_tokens: monthlyTokens },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onClose,
                onError: (errors) => setError(errors.monthly_tokens ?? 'Could not save the allowance.'),
            },
        );

    return (
        <Dialog open onClose={onClose} fullWidth maxWidth="xs">
            <DialogTitle>AI allowance for {tenant.name}</DialogTitle>
            <DialogContent>
                <TextField
                    label="Tokens per month"
                    type="number"
                    fullWidth
                    autoFocus
                    margin="dense"
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    error={Boolean(error)}
                    helperText={error ?? `Leave empty for the default (${number(defaultCap)}). 0 turns AI off for this business.`}
                    slotProps={{ htmlInput: { min: 0, step: 1000 } }}
                />
            </DialogContent>
            <DialogActions>
                {tenant.custom_cap ? (
                    <Button color="inherit" disabled={processing} onClick={() => save(null)}>
                        Use default
                    </Button>
                ) : null}
                <Button onClick={onClose} color="inherit">
                    Cancel
                </Button>
                <Button variant="contained" disabled={processing} onClick={() => save(value === '' ? null : Number(value))}>
                    Save
                </Button>
            </DialogActions>
        </Dialog>
    );
}

export default function Usage({ tenants, totals, features, filters, current, defaultCap, provider }) {
    const [month, setMonth] = useState(filters.month);
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState(null);

    const apply = (overrides = {}) => {
        const query = { month, search, ...overrides };
        router.get('/ai-usage', Object.fromEntries(Object.entries(query).filter(([, value]) => value)), { preserveState: true, replace: true });
    };

    const stats = [
        { label: 'Tokens', value: number(totals.tokens) },
        { label: 'Requests', value: number(totals.requests) },
        { label: 'Failed requests', value: number(totals.failed) },
        { label: 'Provider cost', value: money(totals.cost) },
    ];

    return (
        <AdminLayout title="AI usage">
            <PageHeader
                title="AI usage"
                description={`${provider.name}${provider.model ? ` · ${provider.model}` : ''} · default allowance ${number(defaultCap)} tokens per business per month`}
            />

            {!provider.configured ? (
                <Alert severity="warning" className="mb-4">
                    The AI provider is not configured on this server (set OPENROUTER_API_KEY). Businesses see AI as unavailable.
                </Alert>
            ) : null}

            <form
                className="mb-4 flex flex-wrap gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({ page: undefined });
                }}
            >
                <TextField size="small" type="month" label="Month (UTC)" value={month} onChange={(event) => setMonth(event.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
                <TextField size="small" label="Search business" value={search} onChange={(event) => setSearch(event.target.value)} className="w-full sm:w-64" />
                <Button type="submit" variant="contained">
                    Show
                </Button>
            </form>

            <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((stat) => (
                    <Card key={stat.label} variant="outlined">
                        <CardContent>
                            <p className="text-sm text-slate-500">{stat.label}</p>
                            <p className="mt-2 text-2xl font-semibold text-slate-900">{stat.value}</p>
                        </CardContent>
                    </Card>
                ))}
            </section>

            {features.length ? (
                <div className="mt-4 flex flex-wrap gap-2">
                    {features.map((feature) => (
                        <Chip key={feature.feature} variant="outlined" label={`${feature.label}: ${number(feature.tokens)} tokens · ${number(feature.requests)} requests`} />
                    ))}
                </div>
            ) : null}

            <Card variant="outlined" className="mt-6">
                <div className="overflow-x-auto">
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                <TableCell>Business</TableCell>
                                <TableCell align="right">Requests</TableCell>
                                <TableCell align="right">Failed</TableCell>
                                <TableCell align="right">Tokens</TableCell>
                                <TableCell align="right">Cost</TableCell>
                                <TableCell>Allowance</TableCell>
                                <TableCell align="right">Actions</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {tenants.data.map((tenant) => (
                                <TableRow key={tenant.id}>
                                    <TableCell>
                                        <span className="font-medium text-slate-900">{tenant.name}</span>
                                        <br />
                                        <span className="text-xs text-slate-500">{tenant.slug}</span>
                                    </TableCell>
                                    <TableCell align="right">{number(tenant.requests)}</TableCell>
                                    <TableCell align="right">{number(tenant.failed)}</TableCell>
                                    <TableCell align="right">{number(tenant.tokens)}</TableCell>
                                    <TableCell align="right">{money(tenant.cost)}</TableCell>
                                    <TableCell className="min-w-44">
                                        <span className="text-sm">
                                            {number(tenant.cap)}
                                            {tenant.custom_cap ? ' (custom)' : ''}
                                        </span>
                                        {current && tenant.percent !== null ? (
                                            <LinearProgress
                                                variant="determinate"
                                                value={tenant.percent}
                                                color={tenant.percent >= 90 ? 'error' : tenant.percent >= 70 ? 'warning' : 'primary'}
                                                className="mt-1"
                                                aria-label={`${tenant.percent}% used`}
                                            />
                                        ) : null}
                                    </TableCell>
                                    <TableCell align="right">
                                        <Button size="small" onClick={() => setEditing(tenant)}>
                                            Change allowance
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </Card>

            {tenants.last_page > 1 ? (
                <div className="mt-4 flex justify-center">
                    <Pagination count={tenants.last_page} page={tenants.current_page} onChange={(event, page) => apply({ page })} color="primary" />
                </div>
            ) : null}

            {editing ? <LimitDialog tenant={editing} defaultCap={defaultCap} onClose={() => setEditing(null)} /> : null}
        </AdminLayout>
    );
}
