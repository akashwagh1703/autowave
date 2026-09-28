import { Head, Link, router, usePage } from '@inertiajs/react';
import Avatar from '@mui/material/Avatar';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import ListItemIcon from '@mui/material/ListItemIcon';
import Divider from '@mui/material/Divider';
import LogoutIcon from '@mui/icons-material/Logout';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import { useState } from 'react';
import FlashMessages from '@/components/FlashMessages';

const navigation = [
    { label: 'Dashboard', href: '/dashboard' },
    { label: 'Appointments', href: '/appointments', permission: 'appointments.view', engine: 'booking' },
    { label: 'Orders', href: '/orders', permission: 'orders.view', engine: 'commerce' },
    { label: 'Leads', href: '/leads', permission: 'leads.view', module: 'leads' },
    { label: 'Customers', href: '/customers', permission: 'customers.view', module: 'customers' },
    { label: 'Services', href: '/services', permission: 'services.view', engine: 'service' },
    { label: 'Products', href: '/products', permission: 'products.view', engine: 'commerce' },
    { label: (tenant) => tenant?.resource_label?.plural ?? 'Staff', href: '/resources', permission: 'resources.view', engine: 'booking' },
    { label: 'Automations', href: '/automations', permission: 'automation.view', module: 'automation' },
    { label: 'Website', href: '/website', permission: 'website.view', module: 'website' },
    { label: 'Settings', href: '/settings', permission: 'settings.view' },
];

export default function AppLayout({ title, children }) {
    const { app, auth, tenant, permissions } = usePage().props;
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

            <header className="border-b border-slate-200 bg-white">
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
                        {items.map((item) => (
                            <Button
                                key={item.href}
                                component={Link}
                                href={item.href}
                                size="small"
                                color={isCurrent(item.href) ? 'primary' : 'inherit'}
                            >
                                {typeof item.label === 'function' ? item.label(tenant) : item.label}
                            </Button>
                        ))}
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
