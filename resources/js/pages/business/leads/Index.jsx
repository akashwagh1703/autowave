import { Link, router, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Checkbox from '@mui/material/Checkbox';
import LinearProgress from '@mui/material/LinearProgress';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import TuneIcon from '@mui/icons-material/Tune';
import PersonSearchIcon from '@mui/icons-material/PersonSearch';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import SearchField from '@/components/SearchField';
import ConfirmDialog from '@/components/ConfirmDialog';
import StageChip from '@/modules/leads/StageChip';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatDate, formatDateTime, formatMoney, formatRelative, isOverdue } from '@/utils/format';

const views = [
    { value: 'open', label: 'Open' },
    { value: 'followup', label: 'Follow-ups due' },
    { value: 'closed', label: 'Closed' },
    { value: 'all', label: 'All' },
];

const sorts = [
    { value: 'newest', label: 'Newest first' },
    { value: 'oldest', label: 'Oldest first' },
    { value: 'followup', label: 'Next follow-up' },
    { value: 'value', label: 'Highest value' },
    { value: 'name', label: 'Name A–Z' },
];

function FilterSelect({ label, value, onChange, children, className = 'min-w-40' }) {
    return (
        <TextField select size="small" label={label} value={value ?? ''} onChange={(event) => onChange(event.target.value || null)} className={className}>
            {children}
        </TextField>
    );
}

function BulkBar({ selected, stages, members, can, onDone }) {
    const [menu, setMenu] = useState(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [processing, setProcessing] = useState(false);

    const run = (payload) => {
        setMenu(null);
        router.post('/leads/bulk', { ids: selected, ...payload }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmDelete(false);
            },
            onSuccess: onDone,
        });
    };

    return (
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-brand-50 px-4 py-2">
            <span className="text-sm font-medium text-brand-700">{selected.length} selected</span>
            {can('leads.assign') ? (
                <Button size="small" disabled={processing} onClick={(event) => setMenu({ type: 'assign', anchor: event.currentTarget })}>
                    Assign
                </Button>
            ) : null}
            {can('leads.update') ? (
                <Button size="small" disabled={processing} onClick={(event) => setMenu({ type: 'stage', anchor: event.currentTarget })}>
                    Move to stage
                </Button>
            ) : null}
            {can('leads.delete') ? (
                <Button size="small" color="error" disabled={processing} onClick={() => setConfirmDelete(true)}>
                    Delete
                </Button>
            ) : null}

            <Menu anchorEl={menu?.anchor} open={menu?.type === 'assign'} onClose={() => setMenu(null)}>
                <MenuItem onClick={() => run({ action: 'assign', assigned_tenant_user_id: null })}>
                    <em>Unassign</em>
                </MenuItem>
                {members.map((member) => (
                    <MenuItem key={member.id} onClick={() => run({ action: 'assign', assigned_tenant_user_id: member.id })}>
                        {member.name}
                    </MenuItem>
                ))}
            </Menu>
            <Menu anchorEl={menu?.anchor} open={menu?.type === 'stage'} onClose={() => setMenu(null)}>
                {stages.map((stage) => (
                    <MenuItem key={stage.id} onClick={() => run({ action: 'stage', lead_stage_id: stage.id })}>
                        {stage.name}
                    </MenuItem>
                ))}
            </Menu>

            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${selected.length} lead${selected.length === 1 ? '' : 's'}?`}
                description="Deleted leads disappear from lists. Their history stays on linked customers."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={() => run({ action: 'delete' })}
                onClose={() => setConfirmDelete(false)}
            />
        </div>
    );
}

export default function Index({ leads, filters: initialFilters, counts, stages, sources, members }) {
    const { timezone, currency, can } = useTenant();
    const { errors } = usePage().props;
    const bulkError = errors.lead_stage_id ?? errors.assigned_tenant_user_id ?? errors.ids ?? errors.action;
    const { filters, apply, applyDebounced, loading } = useFilters('/leads', initialFilters);
    const [selected, setSelected] = useState([]);

    const pageIds = leads.data.map((lead) => lead.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const toggle = (id) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
    const canBulk = can('leads.assign') || can('leads.update') || can('leads.delete');
    const filtering = Boolean(filters.search || filters.stage || filters.source || filters.assignee);
    const change = (changes) => {
        setSelected([]);
        apply(changes);
    };

    return (
        <AppLayout title="Leads">
            <PageHeader
                title="Leads"
                description="Capture enquiries, follow up on time and convert them into customers."
                actions={
                    <>
                        {can('settings.view') ? (
                            <Button component={Link} href="/settings/crm" startIcon={<TuneIcon />} color="inherit">
                                Pipeline
                            </Button>
                        ) : null}
                        {can('leads.create') ? (
                            <Button component={Link} href="/leads/create" variant="contained" startIcon={<AddIcon />}>
                                Add lead
                            </Button>
                        ) : null}
                    </>
                }
            />

            <div className="mb-4 flex flex-wrap gap-2" aria-label="Pipeline">
                {stages.map((stage) => {
                    const active = Number(filters.stage) === stage.id;

                    return (
                        <button
                            key={stage.id}
                            type="button"
                            onClick={() => change({ stage: active ? null : stage.id })}
                            className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition ${active ? 'border-brand-500 bg-brand-50' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                        >
                            <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: stage.color }} />
                            <span className="text-slate-700">{stage.name}</span>
                            <span className="font-semibold text-slate-900">{counts.by_stage[stage.id] ?? 0}</span>
                        </button>
                    );
                })}
            </div>

            <Card variant="outlined">
                <Tabs
                    value={filters.stage ? false : filters.view}
                    onChange={(_, view) => change({ view, stage: null })}
                    variant="scrollable"
                    className="border-b border-slate-200 px-2"
                >
                    {views.map((view) => (
                        <Tab
                            key={view.value}
                            value={view.value}
                            label={
                                view.value === 'followup' && counts.followup_due > 0 ? `${view.label} (${counts.followup_due})` : view.label
                            }
                        />
                    ))}
                </Tabs>

                <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
                    <SearchField
                        value={filters.search}
                        onChange={(search) => applyDebounced({ search })}
                        placeholder="Search name, phone, email"
                        loading={loading}
                        className="min-w-64 flex-1"
                    />
                    <FilterSelect label="Source" value={filters.source} onChange={(source) => change({ source })}>
                        <MenuItem value="">Any source</MenuItem>
                        {sources.map((source) => (
                            <MenuItem key={source.id} value={source.id}>
                                {source.name}
                            </MenuItem>
                        ))}
                    </FilterSelect>
                    <FilterSelect label="Assigned to" value={filters.assignee} onChange={(assignee) => change({ assignee })}>
                        <MenuItem value="">Anyone</MenuItem>
                        <MenuItem value="me">Me{counts.mine ? ` (${counts.mine} open)` : ''}</MenuItem>
                        <MenuItem value="unassigned">Unassigned</MenuItem>
                        {members.map((member) => (
                            <MenuItem key={member.id} value={String(member.id)}>
                                {member.name}
                            </MenuItem>
                        ))}
                    </FilterSelect>
                    <FilterSelect label="Sort" value={filters.sort} onChange={(sort) => change({ sort: sort ?? 'newest' })}>
                        {sorts.map((sort) => (
                            <MenuItem key={sort.value} value={sort.value}>
                                {sort.label}
                            </MenuItem>
                        ))}
                    </FilterSelect>
                </div>

                {loading ? <LinearProgress /> : <div className="h-1" />}

                {bulkError ? (
                    <Alert severity="error" className="m-4">
                        {bulkError}
                    </Alert>
                ) : null}

                {selected.length > 0 && canBulk ? (
                    <BulkBar
                        selected={selected}
                        stages={stages}
                        members={members}
                        can={can}
                        onDone={() => setSelected([])}
                    />
                ) : null}

                {leads.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={PersonSearchIcon}
                            title={filtering || filters.view !== 'open' ? 'No leads match' : 'No open leads yet'}
                            description={
                                filtering || filters.view !== 'open'
                                    ? 'Try a different search or filter.'
                                    : 'Add your first enquiry. Leads from your website and campaigns will appear here too.'
                            }
                            action={
                                !filtering && can('leads.create') ? (
                                    <Button component={Link} href="/leads/create" variant="contained" startIcon={<AddIcon />}>
                                        Add lead
                                    </Button>
                                ) : null
                            }
                        />
                    </div>
                ) : (
                    <TableContainer>
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    {canBulk ? (
                                        <TableCell padding="checkbox">
                                            <Checkbox
                                                checked={allSelected}
                                                indeterminate={!allSelected && selected.length > 0}
                                                onChange={() => setSelected(allSelected ? [] : pageIds)}
                                                slotProps={{ input: { 'aria-label': 'Select all leads on this page' } }}
                                            />
                                        </TableCell>
                                    ) : null}
                                    <TableCell>Lead</TableCell>
                                    <TableCell>Stage</TableCell>
                                    <TableCell className="hidden md:table-cell">Interest</TableCell>
                                    <TableCell align="right" className="hidden md:table-cell">
                                        Value
                                    </TableCell>
                                    <TableCell className="hidden lg:table-cell">Assigned</TableCell>
                                    <TableCell>Follow-up</TableCell>
                                    <TableCell className="hidden lg:table-cell">Added</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {leads.data.map((lead) => {
                                    const overdue = lead.stage?.outcome === 'open' && isOverdue(lead.next_followup_at);

                                    return (
                                        <TableRow key={lead.id} hover selected={selected.includes(lead.id)}>
                                            {canBulk ? (
                                                <TableCell padding="checkbox">
                                                    <Checkbox
                                                        checked={selected.includes(lead.id)}
                                                        onChange={() => toggle(lead.id)}
                                                        slotProps={{ input: { 'aria-label': `Select ${lead.name}` } }}
                                                    />
                                                </TableCell>
                                            ) : null}
                                            <TableCell>
                                                <Link href={`/leads/${lead.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                                                    {lead.name}
                                                </Link>
                                                <p className="text-xs text-slate-500">
                                                    {[lead.phone, lead.email].filter(Boolean).join(' · ')}
                                                    {lead.source ? ` · ${lead.source.name}` : ''}
                                                </p>
                                            </TableCell>
                                            <TableCell>
                                                <StageChip stage={lead.stage} />
                                            </TableCell>
                                            <TableCell className="hidden max-w-48 truncate md:table-cell">{lead.interest ?? '—'}</TableCell>
                                            <TableCell align="right" className="hidden md:table-cell">
                                                {formatMoney(lead.estimated_value, currency)}
                                            </TableCell>
                                            <TableCell className="hidden lg:table-cell">{lead.assignee?.name ?? <span className="text-slate-400">Unassigned</span>}</TableCell>
                                            <TableCell>
                                                {lead.next_followup_at ? (
                                                    <span
                                                        className={overdue ? 'font-medium text-red-600' : 'text-slate-700'}
                                                        title={formatDateTime(lead.next_followup_at, timezone)}
                                                    >
                                                        {formatRelative(lead.next_followup_at)}
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="hidden text-slate-600 lg:table-cell">{formatDate(lead.created_at, timezone)}</TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={leads.meta} noun="leads" />
        </AppLayout>
    );
}
