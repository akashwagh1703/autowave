import { Head, Link, router, usePage } from '@inertiajs/react';
import Avatar from '@mui/material/Avatar';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import ListItemIcon from '@mui/material/ListItemIcon';
import Divider from '@mui/material/Divider';
import LogoutIcon from '@mui/icons-material/Logout';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import { useState } from 'react';
import FlashMessages from '@/components/FlashMessages';
import VerifyEmailBanner from '@/components/VerifyEmailBanner';
import BillingBanner from '@/modules/billing/BillingBanner';

const navigation = [
    { label: 'Dashboard', href: '/dashboard' },
    { label: 'Inbox', href: '/inbox', permission: 'conversations.view', module: 'messaging', badge: (props) => props.inbox?.unread },
    { label: 'Appointments', href: '/appointments', permission: 'appointments.view', engine: 'booking' },
    { label: 'Reservations', href: '/reservations', permission: 'reservations.view', engine: 'food' },
    { label: 'Orders', href: '/orders', permission: 'orders.view', engine: 'commerce' },
    { label: 'Kitchen', href: '/kitchen', permission: 'orders.view', engine: 'food' },
    { label: 'Tables', href: '/tables', permission: 'reservations.view', engine: 'food' },
    { label: 'Students', href: '/students', permission: 'students.view', engine: 'education' },
    { label: 'Courses', href: '/courses', permission: 'courses.view', engine: 'education' },
    { label: 'Fees', href: '/fees', permission: 'fees.view', engine: 'education' },
    { label: 'Demo classes', href: '/demos', permission: 'students.view', engine: 'education' },
    { label: 'Leads', href: '/leads', permission: 'leads.view', module: 'leads' },
    { label: 'Customers', href: '/customers', permission: 'customers.view', module: 'customers' },
    { label: 'Services', href: '/services', permission: 'services.view', engine: 'service' },
    { label: 'Products', href: '/products', permission: 'products.view', engine: 'commerce' },
    { label: 'Offers', href: '/offers', permission: 'offers.view', module: 'offers' },
    { label: (tenant) => tenant?.resource_label?.plural ?? 'Staff', href: '/resources', permission: 'resources.view', engine: 'booking' },
    { label: 'Automations', href: '/automations', permission: 'automation.view', module: 'automation' },
    { label: 'Website', href: '/website', permission: 'website.view', module: 'website' },
    { label: 'Assistant', href: '/assistant', permission: 'ai.use', module: 'ai' },
    { label: 'Settings', href: '/settings', permission: 'settings.view' },
];

export default function AppLayout({ title, children }) {
    const props = usePage().props;
    const { app, auth, tenant, permissions } = props;
    const [anchor, setAnchor] = useState(null);
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    const items = navigation.filter(
        (item) =>
            (!item.permission || permissions.includes(item.permission)) &&
            (!item.module || tenant?.modules?.includes(item.module)) &&
            (!item.engine || tenant?.engines?.includes(item.engine)),
    );
    const isCurrent = (href) => currentPath === href || currentPath.startsWith(`${href}/`);

    return (
        <div className="min-h-screen bg-slate-50">
            <Head title={title} />

            <VerifyEmailBanner />
            <BillingBanner />

            <header className="border-b border-slate-200 bg-white print:hidden">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
                    <Link href="/dashboard" className="text-lg font-bold text-brand-700">
                        {app.name}
                    </Link>

                    {tenant ? (
                        <span className="rounded-md bg-brand-50 px-2 py-1 text-sm font-medium text-brand-700">
                            {tenant.name}
                        </span>
                    ) : null}

                    <nav className="order-last flex w-full gap-1 overflow-x-auto md:order-none md:w-auto">
                        {items.map((item) => {
                            const badge = item.badge?.(props) ?? 0;

                            return (
                                <Button
                                    key={item.href}
                                    component={Link}
                                    href={item.href}
                                    size="small"
                                    color={isCurrent(item.href) ? 'primary' : 'inherit'}
                                    aria-label={badge ? `${item.label}, ${badge} unread` : undefined}
                                >
                                    {typeof item.label === 'function' ? item.label(tenant) : item.label}
                                    {badge ? (
                                        <span className="ml-1.5 rounded-full bg-brand-600 px-1.5 py-px text-[11px] leading-4 font-semibold text-white">
                                            {badge > 99 ? '99+' : badge}
                                        </span>
                                    ) : null}
                                </Button>
                            );
                        })}
                    </nav>

                    <div className="ml-auto">
                        <IconButton onClick={(event) => setAnchor(event.currentTarget)} aria-label="Account menu">
                            <Avatar sx={{ width: 32, height: 32, bgcolor: 'primary.main', fontSize: 14 }}>
                                {auth.user?.name?.charAt(0).toUpperCase()}
                            </Avatar>
                        </IconButton>
                        <Menu anchorEl={anchor} open={anchor !== null} onClose={() => setAnchor(null)}>
                            <div className="px-4 py-2">
                                <p className="text-sm font-medium text-slate-900">{auth.user?.name}</p>
                                <p className="text-xs text-slate-500">{auth.user?.email}</p>
                            </div>
                            <Divider />
                            <MenuItem component={Link} href="/workspaces" onClick={() => setAnchor(null)}>
                                <ListItemIcon>
                                    <SwapHorizIcon fontSize="small" />
                                </ListItemIcon>
                                Switch business
                            </MenuItem>
                            {permissions.includes('billing.view') ? (
                                <MenuItem component={Link} href="/settings/billing" onClick={() => setAnchor(null)}>
                                    <ListItemIcon>
                                        <ReceiptLongIcon fontSize="small" />
                                    </ListItemIcon>
                                    Plan and billing
                                </MenuItem>
                            ) : null}
                            <MenuItem onClick={() => router.post('/logout')}>
                                <ListItemIcon>
                                    <LogoutIcon fontSize="small" />
                                </ListItemIcon>
                                Log out
                            </MenuItem>
                        </Menu>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">{children}</main>

            <FlashMessages />
        </div>
    );
}
