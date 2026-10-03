import { Link, router, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlined';
import EditIcon from '@mui/icons-material/Edit';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import PaletteIcon from '@mui/icons-material/Palette';
import RadioButtonUncheckedIcon from '@mui/icons-material/RadioButtonUnchecked';
import StorefrontIcon from '@mui/icons-material/Storefront';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';
import PageHeader from '@/components/PageHeader';
import AppLayout from '@/layouts/AppLayout';
import { formatDuration } from '@/utils/booking';

function SectionRow({ section, index, movable, canMoveUp, canMoveDown, onMove, onRemove, canManage }) {
    const toggle = (enabled) => router.patch(`/website/sections/${section.id}/toggle`, { enabled }, { preserveScroll: true });
    const status = !section.available
        ? { label: 'Not available', color: 'default' }
        : !section.enabled
          ? { label: 'Hidden', color: 'default' }
          : section.live
            ? { label: 'On your site', color: 'success' }
            : { label: 'Waiting for content', color: 'warning' };

    return (
        <li className="flex flex-wrap items-center gap-3 py-3">
            {canManage ? (
                <div className="flex flex-col">
                    <IconButton size="small" aria-label={`Move ${section.label} up`} disabled={!movable || !canMoveUp} onClick={() => onMove(index, -1)}>
                        <ArrowUpwardIcon fontSize="small" />
                    </IconButton>
                    <IconButton size="small" aria-label={`Move ${section.label} down`} disabled={!movable || !canMoveDown} onClick={() => onMove(index, 1)}>
                        <ArrowDownwardIcon fontSize="small" />
                    </IconButton>
                </div>
            ) : null}
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-slate-900">{section.label}</span>
                    <Chip size="small" label={status.label} color={status.color} variant="outlined" />
                    {section.pinned ? <span className="text-xs text-slate-500">Always {section.pinned === 'first' ? 'at the top' : 'at the bottom'}</span> : null}
                </div>
                <p className="text-sm text-slate-600">{section.enabled && section.available && !section.live && section.empty_hint ? section.empty_hint : section.description}</p>
            </div>
            <div className="flex items-center gap-1">
                <Tooltip title={section.enabled ? 'Shown on the website' : 'Hidden from the website'}>
                    <Switch checked={section.enabled} disabled={!canManage || !section.available} onChange={(event) => toggle(event.target.checked)} slotProps={{ input: { 'aria-label': `Show ${section.label}` } }} />
                </Tooltip>
                {section.editable && section.available ? (
                    <Button component={Link} href={`/website/sections/${section.id}/edit`} size="small" startIcon={<EditIcon />}>
                        {canManage ? 'Edit' : 'View'}
                    </Button>
                ) : null}
                {canManage && section.removable ? (
                    <IconButton size="small" aria-label={`Remove ${section.label}`} onClick={() => onRemove(section)}>
                        <DeleteOutlineIcon fontSize="small" />
                    </IconButton>
                ) : null}
            </div>
        </li>
    );
}

function OnlineBookingCard({ onlineBooking, canManage }) {
    const form = useForm({ ...onlineBooking.settings });
    const labels = onlineBooking.resourceLabel;

    const submit = (event) => {
        event.preventDefault();
        form.put('/website/booking', { preserveScroll: true });
    };

    return (
        <Card variant="outlined" id="online-booking">
            <CardContent>
                <h2 className="font-semibold text-slate-900">Online booking</h2>
                <p className="mb-3 text-sm text-slate-600">Let visitors book from the website. Free times come from your {labels.plural.toLowerCase()}' working hours and existing bookings.</p>
                {!onlineBooking.hasResources ? (
                    <Alert severity="info" className="mb-3">
                        Add at least one active {labels.singular.toLowerCase()} with working hours to take bookings online. <Link href="/resources">Go to {labels.plural}</Link>
                    </Alert>
                ) : null}
                <form onSubmit={submit} className="space-y-3">
                    <FormControlLabel
                        control={<Switch checked={form.data.enabled} disabled={!canManage} onChange={(event) => form.setData('enabled', event.target.checked)} />}
                        label="Accept bookings from the website"
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField
                            select
                            label="Minimum notice"
                            disabled={!canManage}
                            value={form.data.min_notice_minutes}
                            onChange={(event) => form.setData('min_notice_minutes', Number(event.target.value))}
                            error={Boolean(form.errors.min_notice_minutes)}
                            helperText={form.errors.min_notice_minutes ?? 'How soon before the start time visitors can book.'}
                        >
                            {onlineBooking.noticeOptions.map((minutes) => (
                                <MenuItem key={minutes} value={minutes}>
                                    {minutes === 0 ? 'No notice' : formatDuration(minutes)}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField
                            select
                            label="Book up to"
                            disabled={!canManage}
                            value={form.data.max_days_ahead}
                            onChange={(event) => form.setData('max_days_ahead', Number(event.target.value))}
                            error={Boolean(form.errors.max_days_ahead)}
                            helperText={form.errors.max_days_ahead ?? 'How far ahead visitors can book.'}
                        >
                            {onlineBooking.daysAheadOptions.map((days) => (
                                <MenuItem key={days} value={days}>
                                    {days} days ahead
                                </MenuItem>
                            ))}
                        </TextField>
                    </div>
                    <div>
                        <FormControlLabel
                            control={<Switch checked={form.data.auto_confirm} disabled={!canManage} onChange={(event) => form.setData('auto_confirm', event.target.checked)} />}
                            label="Confirm online bookings automatically"
                        />
                        <p className="text-sm text-slate-600">When off, online bookings start as pending until someone confirms them.</p>
                    </div>
                    <FormControlLabel
                        control={<Switch checked={form.data.allow_any_resource} disabled={!canManage} onChange={(event) => form.setData('allow_any_resource', event.target.checked)} />}
                        label={`Offer "Any available ${labels.singular.toLowerCase()}"`}
                    />
                    {canManage ? (
                        <div className="flex justify-end">
                            <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                                Save online booking
                            </Button>
                        </div>
                    ) : null}
                </form>
            </CardContent>
        </Card>
    );
}

export default function Index({ website, sections, addable, checklist, onlineBooking, canManage }) {
    const [removing, setRemoving] = useState(null);
    const [adding, setAdding] = useState('');
    const published = website.status === 'published';
    const movableIds = sections.filter((section) => !section.pinned).map((section) => section.id);
    const done = checklist.filter((item) => item.done).length;

    const move = (index, offset) => {
        const ids = sections.map((section) => section.id);
        const target = index + offset;
        if (!movableIds.includes(ids[target])) {
            return;
        }
        [ids[index], ids[target]] = [ids[target], ids[index]];
        router.put('/website/sections/order', { ids }, { preserveScroll: true });
    };

    const publish = (value) => router.put('/website/publish', { published: value }, { preserveScroll: true });
    const add = () => adding && router.post('/website/sections', { type: adding }, { onSuccess: () => setAdding('') });
    const remove = () => router.delete(`/website/sections/${removing.id}`, { preserveScroll: true, onFinish: () => setRemoving(null) });

    return (
        <AppLayout title="Website">
            <PageHeader
                title="Website"
                description={canManage ? 'Choose what your website shows. Services, prices and staff come straight from your business data.' : 'You have view-only access.'}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {website.url && published ? (
                            <Button href={website.url} target="_blank" rel="noopener" startIcon={<OpenInNewIcon />} variant="outlined">
                                View website
                            </Button>
                        ) : null}
                        {website.preview_url ? (
                            <Button href={website.preview_url} target="_blank" rel="noopener" startIcon={<VisibilityIcon />} variant="outlined">
                                Preview
                            </Button>
                        ) : null}
                        {canManage ? (
                            <Button variant="contained" color={published ? 'inherit' : 'primary'} onClick={() => publish(!published)}>
                                {published ? 'Unpublish' : 'Publish website'}
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card variant="outlined">
                        <CardContent>
                            <div className="flex flex-wrap items-center gap-2">
                                <Chip label={published ? 'Live' : 'Draft'} color={published ? 'success' : 'default'} size="small" />
                                {website.url ? <span className="text-sm break-all text-slate-600">{website.url.replace(/^\/\//, '')}</span> : null}
                                <span className="text-sm text-slate-500">· {website.template} template</span>
                            </div>
                            {!published ? <p className="mt-2 text-sm text-slate-600">Visitors see a "not found" page until you publish. Use Preview to check it first.</p> : null}
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button component={Link} href="/website/design" startIcon={<PaletteIcon />} size="small" variant="outlined">
                                    Design and logo
                                </Button>
                                <Button component={Link} href="/website/details" startIcon={<StorefrontIcon />} size="small" variant="outlined">
                                    Business details
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Sections</h2>
                            <p className="text-sm text-slate-600">Top to bottom, as visitors see them. Sections without content stay off the site until they have some.</p>
                            <ul className="divide-y divide-slate-100">
                                {sections.map((section, index) => (
                                    <SectionRow
                                        key={section.id}
                                        section={section}
                                        index={index}
                                        movable={!section.pinned}
                                        canMoveUp={index > 0 && movableIds.includes(sections[index - 1].id)}
                                        canMoveDown={index < sections.length - 1 && movableIds.includes(sections[index + 1].id)}
                                        onMove={move}
                                        onRemove={setRemoving}
                                        canManage={canManage}
                                    />
                                ))}
                            </ul>
                            {canManage && addable.length ? (
                                <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                                    <TextField select size="small" label="Add a section" value={adding} onChange={(event) => setAdding(event.target.value)} className="min-w-56">
                                        {addable.map((option) => (
                                            <MenuItem key={option.type} value={option.type}>
                                                {option.label}
                                            </MenuItem>
                                        ))}
                                    </TextField>
                                    <Button variant="outlined" disabled={!adding} onClick={add}>
                                        Add
                                    </Button>
                                    {adding ? <span className="text-sm text-slate-600">{addable.find((option) => option.type === adding)?.description}</span> : null}
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    {onlineBooking ? <OnlineBookingCard onlineBooking={onlineBooking} canManage={canManage} /> : null}
                </div>

                <Card variant="outlined" className="self-start">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Get your website ready</h2>
                        <p className="mb-3 text-sm text-slate-600">
                            {done} of {checklist.length} done
                        </p>
                        <ul className="space-y-2">
                            {checklist.map((item) => (
                                <li key={item.key} className="flex items-center gap-2 text-sm">
                                    {item.done ? <CheckCircleIcon fontSize="small" className="text-emerald-600" /> : <RadioButtonUncheckedIcon fontSize="small" className="text-slate-400" />}
                                    {item.href && !item.done ? (
                                        <Link href={item.href} className="text-brand-700 hover:underline">
                                            {item.label}
                                        </Link>
                                    ) : (
                                        <span className={item.done ? 'text-slate-500' : 'text-slate-800'}>{item.label}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </div>

            <ConfirmDialog
                open={Boolean(removing)}
                title={`Remove the ${removing?.label.toLowerCase()} section?`}
                description={
                    removing?.has_files
                        ? 'Its text and uploaded files are deleted for good. Hide it instead to keep them.'
                        : 'Its text is deleted. You can add the section again later, or hide it instead to keep the text.'
                }
                confirmLabel="Remove"
                destructive
                onConfirm={remove}
                onClose={() => setRemoving(null)}
            />
        </AppLayout>
    );
}
