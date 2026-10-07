import { Head, Link, usePage } from '@inertiajs/react';
import CloseIcon from '@mui/icons-material/Close';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import MenuIcon from '@mui/icons-material/Menu';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import Drawer from '@mui/material/Drawer';
import { useEffect, useState } from 'react';
import BrandLogo from '@/components/BrandLogo';
import { Container } from '@/modules/marketing/blocks';

const companyLinks = [
    { href: '/contact', label: 'Contact' },
    { href: '/terms', label: 'Terms' },
    { href: '/privacy', label: 'Privacy' },
    { href: '/refunds', label: 'Refunds and cancellation' },
];

const navLink = 'rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-ink';

/** The marketing site (autowave.co.in): sticky header, mobile menu and footer. Sets the page title from `meta`. */
export default function PublicLayout({ children }) {
    const { app, appUrl, meta, industryLinks = [], whatsappUrl } = usePage().props;
    const [menuOpen, setMenuOpen] = useState(false);
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 8);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <div className="flex min-h-screen flex-col bg-white text-ink">
            {meta ? <Head title={meta.title} /> : null}
            <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">
                Skip to content
            </a>

            <header className={`sticky top-0 z-40 transition ${scrolled ? 'border-b border-slate-200/80 bg-white/90 backdrop-blur-md' : 'bg-transparent'}`}>
                <Container className="flex h-16 items-center justify-between gap-4">
                    <Link href="/" aria-label={`${app.name} home`}>
                        <BrandLogo name={app.name} />
                    </Link>

                    <nav aria-label="Main" className="hidden items-center gap-1 lg:flex">
                        <a href="/#features" className={navLink}>
                            Features
                        </a>
                        <a href="/#whatsapp-assistant" className={navLink}>
                            WhatsApp assistant
                        </a>
                        <div className="group relative">
                            <button type="button" className={`${navLink} inline-flex items-center gap-0.5`} aria-haspopup="true">
                                Industries <ExpandMoreIcon sx={{ fontSize: 18 }} />
                            </button>
                            <div className="invisible absolute top-full left-0 w-60 translate-y-1 rounded-xl border border-slate-200 bg-white p-2 opacity-0 shadow-xl transition group-focus-within:visible group-focus-within:translate-y-0 group-focus-within:opacity-100 group-hover:visible group-hover:translate-y-0 group-hover:opacity-100">
                                {industryLinks.map((item) => (
                                    <Link key={item.slug} href={`/for/${item.slug}`} className="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-800">
                                        {item.name}
                                    </Link>
                                ))}
                            </div>
                        </div>
                        <Link href="/pricing" className={navLink}>
                            Pricing
                        </Link>
                        <Link href="/demo" className={navLink}>
                            Book a demo
                        </Link>
                    </nav>

                    <div className="flex items-center gap-2">
                        <a href={`${appUrl}/login`} className={`${navLink} hidden sm:inline-flex`}>
                            Log in
                        </a>
                        <a
                            href={`${appUrl}/register`}
                            className="hidden rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-brand-600/20 transition hover:bg-brand-700 sm:inline-flex"
                        >
                            Start free trial
                        </a>
                        <button type="button" className="rounded-lg p-2 text-slate-700 hover:bg-slate-100 lg:hidden" aria-label="Open menu" onClick={() => setMenuOpen(true)}>
                            <MenuIcon />
                        </button>
                    </div>
                </Container>
            </header>

            <Drawer anchor="right" open={menuOpen} onClose={() => setMenuOpen(false)} slotProps={{ paper: { sx: { width: 300 } } }}>
                <div className="flex h-full flex-col p-5">
                    <div className="flex items-center justify-between">
                        <BrandLogo name={app.name} size={28} />
                        <button type="button" className="rounded-lg p-2 hover:bg-slate-100" aria-label="Close menu" onClick={() => setMenuOpen(false)}>
                            <CloseIcon />
                        </button>
                    </div>
                    <nav aria-label="Mobile" className="mt-6 flex flex-col gap-1" onClick={() => setMenuOpen(false)}>
                        <a href="/#features" className={navLink}>
                            Features
                        </a>
                        <a href="/#whatsapp-assistant" className={navLink}>
                            WhatsApp assistant
                        </a>
                        <Link href="/pricing" className={navLink}>
                            Pricing
                        </Link>
                        <Link href="/demo" className={navLink}>
                            Book a demo
                        </Link>
                        <p className="mt-4 px-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">Industries</p>
                        {industryLinks.map((item) => (
                            <Link key={item.slug} href={`/for/${item.slug}`} className={navLink}>
                                {item.name}
                            </Link>
                        ))}
                    </nav>
                    <div className="mt-auto flex flex-col gap-2 pt-6">
                        <a href={`${appUrl}/register`} className="rounded-xl bg-brand-600 px-4 py-3 text-center text-sm font-semibold text-white">
                            Start free trial
                        </a>
                        <a href={`${appUrl}/login`} className="rounded-xl px-4 py-3 text-center text-sm font-semibold text-slate-700 ring-1 ring-slate-200">
                            Log in
                        </a>
                    </div>
                </div>
            </Drawer>

            <main id="main" className="flex-1">
                {children}
            </main>

            <footer className="bg-ink text-slate-400">
                <Container className="grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-5">
                    <div className="lg:col-span-2">
                        <BrandLogo name={app.name} tone="light" />
                        <p className="mt-4 max-w-sm text-sm leading-relaxed">
                            Website, bookings, orders, customers and WhatsApp for local businesses in India, in one simple app.
                        </p>
                        {whatsappUrl ? (
                            <a
                                href={whatsappUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-5 inline-flex items-center gap-2 rounded-xl bg-white/5 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/10 hover:bg-white/10"
                            >
                                <WhatsAppIcon sx={{ fontSize: 18, color: '#25D366' }} /> Chat with us
                            </a>
                        ) : null}
                    </div>
                    <FooterColumn
                        title="Product"
                        links={[
                            { href: '/#features', label: 'Features', plain: true },
                            { href: '/#whatsapp-assistant', label: 'WhatsApp assistant', plain: true },
                            { href: '/pricing', label: 'Pricing' },
                            { href: '/demo', label: 'Book a demo' },
                            { href: `${appUrl}/login`, label: 'Log in', plain: true },
                        ]}
                    />
                    <FooterColumn title="Industries" links={industryLinks.map((item) => ({ href: `/for/${item.slug}`, label: item.name }))} />
                    <FooterColumn title="Company" links={companyLinks} />
                </Container>
                <div className="border-t border-white/10">
                    <Container className="flex flex-col items-center justify-between gap-2 py-6 text-xs sm:flex-row">
                        <span>
                            © {new Date().getFullYear()} {app.name}. All rights reserved.
                        </span>
                        <span>Made in India for local businesses</span>
                    </Container>
                </div>
            </footer>
        </div>
    );
}

function FooterColumn({ title, links }) {
    return (
        <div>
            <p className="text-sm font-semibold text-white">{title}</p>
            <ul className="mt-4 space-y-2.5 text-sm">
                {links.map((link) => (
                    <li key={link.href}>
                        {link.plain ? (
                            <a href={link.href} className="hover:text-white">
                                {link.label}
                            </a>
                        ) : (
                            <Link href={link.href} className="hover:text-white">
                                {link.label}
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
