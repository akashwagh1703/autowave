import { Head, usePage } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import AutoModeIcon from '@mui/icons-material/AutoMode';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import LanguageIcon from '@mui/icons-material/Language';
import PeopleAltIcon from '@mui/icons-material/PeopleAlt';
import PublicLayout from '@/layouts/PublicLayout';
import FeatureCard from '@/components/FeatureCard';

const pillars = [
    {
        icon: LanguageIcon,
        title: 'Business website',
        description: 'A website for every business, powered by the same data as the dashboard.',
    },
    {
        icon: PeopleAltIcon,
        title: 'Leads & CRM',
        description: 'Capture leads from every channel and keep a unified customer timeline.',
    },
    {
        icon: EventAvailableIcon,
        title: 'Bookings & orders',
        description: 'Appointments, slots, products and packages on one platform.',
    },
    {
        icon: AutoModeIcon,
        title: 'Automation',
        description: 'Follow-ups, reminders and offers that run on their own.',
    },
];

export default function Welcome({ appUrl }) {
    const { app } = usePage().props;

    return (
        <PublicLayout>
            <Head title="Build, manage and automate your local business" />

            <section className="mx-auto max-w-5xl px-4 pt-16 pb-12 text-center sm:px-6 sm:pt-24">
                <Chip label="Platform foundation · Phase 1" color="primary" variant="outlined" size="small" />
                <h1 className="mt-6 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">
                    Build, manage and automate a local business from one platform.
                </h1>
                <p className="mx-auto mt-4 max-w-2xl text-lg text-slate-600">
                    {app.name} gives salons, clinics, turfs, coaching centres, cafes and local stores a website,
                    CRM, bookings, commerce and automation — in a single workspace.
                </p>
                <div className="mt-8 flex justify-center gap-3">
                    <Button variant="contained" size="large" href={`${appUrl}/register`}>
                        Get started
                    </Button>
                    <Button variant="outlined" size="large" href={`${appUrl}/login`}>
                        Log in
                    </Button>
                </div>
            </section>

            <section className="mx-auto grid max-w-5xl gap-4 px-4 pb-24 sm:grid-cols-2 sm:px-6">
                {pillars.map((pillar) => (
                    <FeatureCard key={pillar.title} {...pillar} />
                ))}
            </section>
        </PublicLayout>
    );
}
