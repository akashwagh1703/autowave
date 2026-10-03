import { Head } from '@inertiajs/react';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import { useCallback, useMemo, useState } from 'react';
import BookingSection from '@/modules/website/BookingSection';
import ContactSection from '@/modules/website/ContactSection';
import CoursesSection from '@/modules/website/CoursesSection';
import { Downloads, Videos } from '@/modules/website/FileSections';
import ReservationSection from '@/modules/website/ReservationSection';
import { CartDrawer, Products, useCart } from '@/modules/website/ShopSection';
import { About, Faq, Footer, Gallery, Header, Hero, Offers, Services, Team, Testimonials } from '@/modules/website/sections';
import { SiteContext, scrollToSection, siteTheme } from '@/modules/website/site';

// Section types without a renderer (packages, reviews) never reach the page while they have no data.
const RENDERERS = {
    header: Header,
    hero: Hero,
    about: About,
    services: Services,
    products: Products,
    courses: CoursesSection,
    team: Team,
    gallery: Gallery,
    video: Videos,
    testimonials: Testimonials,
    offers: Offers,
    faq: Faq,
    downloads: Downloads,
    contact: ContactSection,
    booking: BookingSection,
    reservation: ReservationSection,
    footer: Footer,
};

export default function Home({
    business,
    template,
    seo,
    contact,
    social,
    locale,
    sections,
    booking,
    reservation,
    shop,
    enquiry,
    preview,
    enquirySent,
    bookingConfirmation,
    reservationConfirmation,
    orderConfirmation,
}) {
    const theme = siteTheme(template, business.primary_color);
    const [selectedService, setSelectedService] = useState(null);
    const types = useMemo(() => new Set(sections.map((section) => section.type)), [sections]);
    const products = useMemo(
        () => Object.fromEntries(sections.filter((section) => section.type === 'products').flatMap((section) => section.data.flatMap((group) => group.products)).map((product) => [product.id, product])),
        [sections],
    );
    const cart = useCart(shop, products);

    const bookService = useCallback((id) => {
        setSelectedService({ id, at: Date.now() });
        scrollToSection('booking');
    }, []);

    const site = {
        business,
        contact,
        social,
        locale,
        theme,
        booking,
        reservation,
        enquiry,
        enquirySent,
        bookingConfirmation,
        reservationConfirmation,
        shop,
        cart,
        orderConfirmation,
        selectedService,
        bookService,
        has: (type) => types.has(type),
    };

    return (
        <SiteContext.Provider value={site}>
            <div className="flex min-h-screen flex-col bg-white text-slate-900" style={{ fontFamily: theme.font }}>
                <Head title={seo.title}>
                    {seo.description ? <meta head-key="description" name="description" content={seo.description} /> : null}
                </Head>

                {preview ? (
                    <div className="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900" role="status">
                        Preview: this website is not published yet, so only people with this link can see it.
                    </div>
                ) : null}

                {sections.map((section) => {
                    const Renderer = RENDERERS[section.type];

                    return Renderer ? <Renderer key={section.id} config={section.config} data={section.data} /> : null;
                })}

                {shop ? <CartDrawer products={products} /> : null}

                {contact.whatsapp_url ? (
                    <a
                        href={contact.whatsapp_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="Chat on WhatsApp"
                        className="fixed right-5 bottom-5 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-lg transition hover:scale-105"
                    >
                        <WhatsAppIcon fontSize="large" />
                    </a>
                ) : null}
            </div>
        </SiteContext.Provider>
    );
}
