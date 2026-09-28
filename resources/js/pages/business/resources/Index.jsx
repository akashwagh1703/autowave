import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import AddIcon from '@mui/icons-material/Add';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import useTenant from '@/hooks/useTenant';
import { WEEKDAYS } from '@/utils/booking';

function hoursSummary(hours) {
    const open = WEEKDAYS.filter((day) => hours.some((row) => row.weekday === day.value));

    if (open.length === 0) {
        return 'No working hours';
    }

    const first = hours.find((row) => row.weekday === open[0].value);
    const sameEverywhere = open.every((day) => {
        const rows = hours.filter((row) => row.weekday === day.value);

        return rows.length === 1 && rows[0].starts_at === first.starts_at && rows[0].ends_at === first.ends_at;
    });

    const days = open.length === 7 ? 'Every day' : open.map((day) => day.short).join(', ');

    return sameEverywhere ? `${days} · ${first.starts_at}–${first.ends_at}` : `${days} · varied hours`;
}

export default function Index({ resources, servicesEnabled }) {
    const { can, resourceLabel } = useTenant();
    const addLabel = `Add ${resourceLabel.singular.toLowerCase()}`;

    return (
        <AppLayout title={resourceLabel.plural}>
            <PageHeader
                title={resourceLabel.plural}
                description="Who or what customers book time with: working hours, services and time off."
                actions={
                    can('resources.manage') ? (
                        <Button component={Link} href="/resources/create" variant="contained" startIcon={<AddIcon />}>
                            {addLabel}
                        </Button>
                    ) : null
                }
            />

            {resources.length === 0 ? (
                <EmptyState
                    icon={EventAvailableIcon}
                    title={`No ${resourceLabel.plural.toLowerCase()} yet`}
                    description={`Add a ${resourceLabel.singular.toLowerCase()} with working hours to start taking appointments.`}
                    action={
                        can('resources.manage') ? (
                            <Button component={Link} href="/resources/create" variant="contained" startIcon={<AddIcon />}>
                                {addLabel}
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {resources.map((resource) => (
                        <Card key={resource.id} variant="outlined" className={resource.is_active ? '' : 'opacity-70'}>
                            <CardActionArea component={Link} href={`/resources/${resource.id}`} className="h-full">
                                <CardContent>
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex items-center gap-3">
                                            <span
                                                className="flex h-10 w-10 items-center justify-center rounded-full text-sm font-semibold text-white"
                                                style={{ backgroundColor: resource.color }}
                                            >
                                                {resource.name.charAt(0).toUpperCase()}
                                            </span>
                                            <div>
                                                <p className="font-semibold text-slate-900">{resource.name}</p>
                                                <p className="text-xs text-slate-500">{resource.description ?? resource.member?.name ?? '—'}</p>
                                            </div>
                                        </div>
                                        {!resource.is_active ? <Chip size="small" label="Paused" /> : null}
                                    </div>
                                    <p className="mt-4 text-sm text-slate-700">{hoursSummary(resource.working_hours ?? [])}</p>
                                    <p className="mt-1 text-xs text-slate-500">
                                        {resource.appointments_this_week} {resource.appointments_this_week === 1 ? 'booking' : 'bookings'} this week
                                        {servicesEnabled ? ` · ${resource.services_count} ${resource.services_count === 1 ? 'service' : 'services'}` : ''}
                                        {resource.member ? ` · linked to ${resource.member.name}` : ''}
                                    </p>
                                </CardContent>
                            </CardActionArea>
                        </Card>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
