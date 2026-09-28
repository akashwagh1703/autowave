import { Link } from '@inertiajs/react';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import RunStatusChip from '@/modules/automations/RunStatusChip';
import { formatDateTime, formatRelative } from '@/utils/format';

function Subject({ subject }) {
    const type = <span className="text-xs text-slate-500 capitalize">{subject.type}</span>;

    if (!subject.url) {
        return (
            <span className="text-slate-500">
                {subject.label} {type} {subject.deleted ? <span className="text-xs">(deleted)</span> : null}
            </span>
        );
    }

    return (
        <span>
            <Link href={subject.url} className="text-brand-700 hover:underline">
                {subject.label}
            </Link>{' '}
            {type}
        </span>
    );
}

export default function RunsTable({ runs, timezone, showAutomation = true }) {
    return (
        <TableContainer>
            <Table size="small">
                <TableHead>
                    <TableRow>
                        <TableCell>Run</TableCell>
                        {showAutomation ? <TableCell>Automation</TableCell> : null}
                        <TableCell>For</TableCell>
                        <TableCell>Status</TableCell>
                        <TableCell className="hidden md:table-cell">Started</TableCell>
                    </TableRow>
                </TableHead>
                <TableBody>
                    {runs.map((run) => (
                        <TableRow key={run.id} hover>
                            <TableCell>
                                <Link href={`/automations/runs/${run.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                                    #{run.id}
                                </Link>
                                <p className="text-xs text-slate-500">{run.trigger_label}</p>
                            </TableCell>
                            {showAutomation ? (
                                <TableCell>
                                    {run.automation ? (
                                        run.automation.deleted ? (
                                            <span className="text-slate-500">{run.automation.name} (deleted)</span>
                                        ) : (
                                            <Link href={`/automations/${run.automation.id}`} className="hover:text-brand-700">
                                                {run.automation.name}
                                            </Link>
                                        )
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                            ) : null}
                            <TableCell>
                                <Subject subject={run.subject} />
                            </TableCell>
                            <TableCell>
                                <RunStatusChip status={run.status} label={run.status_label} />
                                {run.error ? <p className="mt-1 max-w-xs truncate text-xs text-red-700" title={run.error}>{run.error}</p> : null}
                            </TableCell>
                            <TableCell className="hidden md:table-cell" title={formatDateTime(run.created_at, timezone)}>
                                {formatRelative(run.created_at)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </TableContainer>
    );
}
