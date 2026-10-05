import CalendarMonthIcon from '@mui/icons-material/CalendarMonth';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import StarIcon from '@mui/icons-material/Star';
import TrendingUpIcon from '@mui/icons-material/TrendingUp';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import { BrandMark } from '@/components/BrandLogo';

/*
| Product illustrations for the marketing site, drawn with the same look as the app. Decorative only
| (aria-hidden); the surrounding copy carries the meaning.
*/

export function BrowserFrame({ url = 'yourbusiness.autowave.co.in', children, className = '' }) {
    return (
        <div aria-hidden="true" className={`overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-brand-900/10 ${className}`}>
            <div className="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
                <span className="h-2.5 w-2.5 rounded-full bg-rose-300" />
                <span className="h-2.5 w-2.5 rounded-full bg-amber-300" />
                <span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
                <span className="ml-3 truncate rounded-md bg-white px-3 py-1 text-[11px] text-slate-500 ring-1 ring-slate-200">{url}</span>
            </div>
            {children}
        </div>
    );
}

export function PhoneFrame({ children, className = '' }) {
    return (
        <div aria-hidden="true" className={`w-56 rounded-[2rem] border-[6px] border-ink bg-white shadow-2xl shadow-brand-900/20 ${className}`}>
            <div className="mx-auto mt-1.5 h-1.5 w-16 rounded-full bg-slate-200" />
            <div className="overflow-hidden rounded-b-[1.6rem] pt-1.5">{children}</div>
        </div>
    );
}

const bars = [38, 52, 44, 66, 58, 74, 88];

/** App dashboard: today's numbers, a revenue chart and upcoming appointments. */
export function DashboardMockup() {
    return (
        <BrowserFrame url="app.autowave.co.in/dashboard">
            <div className="flex">
                <div className="hidden w-36 shrink-0 flex-col gap-1.5 border-r border-slate-100 bg-slate-50/60 p-3 sm:flex">
                    <div className="mb-2 flex items-center gap-2">
                        <BrandMark size={20} />
                        <span className="text-xs font-bold text-ink">AutoWave</span>
                    </div>
                    {['Dashboard', 'Leads', 'Inbox', 'Bookings', 'Website', 'Automations'].map((item, index) => (
                        <span key={item} className={`rounded-md px-2 py-1.5 text-[11px] ${index === 0 ? 'bg-brand-100 font-semibold text-brand-800' : 'text-slate-500'}`}>
                            {item}
                        </span>
                    ))}
                </div>
                <div className="min-w-0 flex-1 p-4">
                    <p className="text-[11px] text-slate-500">Good morning 👋</p>
                    <p className="text-sm font-bold text-ink">Today at Glow Studio</p>
                    <div className="mt-3 grid grid-cols-3 gap-2">
                        {[
                            ['Revenue today', '₹18,450', '+12%'],
                            ['Appointments', '24', '6 left'],
                            ['New leads', '9', '3 hot'],
                        ].map(([label, value, note]) => (
                            <div key={label} className="rounded-lg border border-slate-100 p-2.5">
                                <p className="text-[10px] text-slate-500">{label}</p>
                                <p className="mt-0.5 text-base font-bold text-ink">{value}</p>
                                <p className="text-[10px] font-medium text-emerald-600">{note}</p>
                            </div>
                        ))}
                    </div>
                    <div className="mt-3 grid grid-cols-5 gap-2">
                        <div className="col-span-3 rounded-lg border border-slate-100 p-2.5">
                            <p className="flex items-center gap-1 text-[10px] font-semibold text-slate-600">
                                <TrendingUpIcon sx={{ fontSize: 12 }} /> This week
                            </p>
                            <div className="mt-2 flex h-20 items-end gap-1.5">
                                {bars.map((height, index) => (
                                    <span
                                        key={index}
                                        className={`flex-1 rounded-t ${index === bars.length - 1 ? 'bg-brand-600' : 'bg-brand-200'}`}
                                        style={{ height: `${height}%` }}
                                    />
                                ))}
                            </div>
                        </div>
                        <div className="col-span-2 space-y-1.5 rounded-lg border border-slate-100 p-2.5">
                            <p className="text-[10px] font-semibold text-slate-600">Next up</p>
                            {[
                                ['11:00', 'Haircut · Priya'],
                                ['11:45', 'Facial · Neha'],
                                ['12:30', 'Colour · Aditi'],
                            ].map(([time, label]) => (
                                <div key={time} className="flex items-center gap-1.5 text-[10px]">
                                    <span className="rounded bg-accent-500/10 px-1 font-semibold text-accent-600">{time}</span>
                                    <span className="truncate text-slate-600">{label}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </BrowserFrame>
    );
}

/** A small business website as visitors see it on a phone. */
export function SitePhoneMockup({ name = 'Glow Studio', tagline = 'Hair, skin and bridal studio', color = '#4f46e5', items = ['Haircut & styling', 'Classic facial', 'Bridal makeup'], cta = 'Book now' }) {
    return (
        <PhoneFrame>
            <div className="px-3 pt-2 pb-1 text-[11px] font-bold" style={{ color }}>
                {name}
            </div>
            <div className="px-4 py-6 text-center text-white" style={{ background: `linear-gradient(135deg, ${color}, #0b1020 140%)` }}>
                <p className="text-sm leading-tight font-bold">{tagline}</p>
                <span className="mt-3 inline-block rounded-full bg-white px-3 py-1 text-[10px] font-semibold" style={{ color }}>
                    {cta}
                </span>
            </div>
            <div className="space-y-1.5 bg-slate-50 p-3">
                {items.map((item, index) => (
                    <div key={item} className="flex items-center justify-between rounded-lg bg-white p-2 shadow-sm">
                        <span className="text-[10px] font-semibold text-ink">{item}</span>
                        <span className="text-[10px] font-bold" style={{ color }}>
                            ₹{[600, 1500, 15000][index % 3].toLocaleString('en-IN')}
                        </span>
                    </div>
                ))}
                <div className="flex items-center gap-1 pt-1 text-[10px] text-amber-500">
                    {[0, 1, 2, 3, 4].map((star) => (
                        <StarIcon key={star} sx={{ fontSize: 11 }} />
                    ))}
                    <span className="ml-1 text-slate-500">Loved by regulars</span>
                </div>
            </div>
        </PhoneFrame>
    );
}

/** A full business website in a browser window. */
export function SiteMockup({ name = 'Glow Studio', tagline = 'Look your best, every day', color = '#be185d', items = ['Haircut & styling', 'Classic facial', 'Bridal makeup'], cta = 'Book now' }) {
    return (
        <BrowserFrame url={`${name.toLowerCase().replace(/[^a-z]+/g, '-')}.autowave.co.in`}>
            <div className="flex items-center justify-between px-5 py-3">
                <span className="text-sm font-bold" style={{ color }}>
                    {name}
                </span>
                <span className="hidden gap-4 text-[11px] text-slate-500 sm:flex">
                    <span>Services</span>
                    <span>Gallery</span>
                    <span>Contact</span>
                </span>
                <span className="rounded-md px-3 py-1 text-[11px] font-semibold text-white" style={{ backgroundColor: color }}>
                    {cta}
                </span>
            </div>
            <div className="grid items-center gap-4 px-5 py-8 sm:grid-cols-2" style={{ background: `linear-gradient(120deg, ${color}14, transparent)` }}>
                <div>
                    <p className="text-[10px] font-semibold tracking-wider uppercase" style={{ color }}>
                        Welcome
                    </p>
                    <p className="font-display mt-1 text-xl leading-tight font-extrabold text-ink">{tagline}</p>
                    <div className="mt-3 flex gap-2">
                        <span className="rounded-md px-3 py-1.5 text-[11px] font-semibold text-white" style={{ backgroundColor: color }}>
                            {cta}
                        </span>
                        <span className="flex items-center gap-1 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-semibold text-slate-700">
                            <WhatsAppIcon sx={{ fontSize: 12, color: '#25D366' }} /> WhatsApp
                        </span>
                    </div>
                </div>
                <div className="grid h-28 grid-cols-3 gap-1.5">
                    <span className="col-span-2 row-span-2 rounded-lg" style={{ background: `linear-gradient(160deg, ${color}66, ${color}22)` }} />
                    <span className="rounded-lg" style={{ backgroundColor: `${color}33` }} />
                    <span className="rounded-lg" style={{ backgroundColor: `${color}22` }} />
                </div>
            </div>
            <div className="grid grid-cols-3 gap-2 px-5 py-4">
                {items.map((item) => (
                    <div key={item} className="rounded-lg border border-slate-100 p-2.5">
                        <span className="block h-1.5 w-6 rounded-full" style={{ backgroundColor: color }} />
                        <p className="mt-2 text-[10px] font-semibold text-ink">{item}</p>
                        <p className="text-[9px] text-slate-400">Book online</p>
                    </div>
                ))}
            </div>
        </BrowserFrame>
    );
}

const leads = [
    ['Rahul M.', 'Bridal package', 'Instagram', 'New', 'bg-indigo-100 text-indigo-700', 'Today'],
    ['Sneha P.', 'Keratin treatment', 'Website', 'New', 'bg-indigo-100 text-indigo-700', 'Today'],
    ['Amit K.', 'Hair colour', 'WhatsApp', 'Contacted', 'bg-sky-100 text-sky-700', 'Tomorrow'],
    ['Kavya R.', 'Bridal makeup', 'Walk-in', 'Won', 'bg-emerald-100 text-emerald-700', '—'],
];

/** Lead list with stages, sources and follow-ups. */
export function PipelineMockup() {
    return (
        <BrowserFrame url="app.autowave.co.in/leads">
            <div className="p-4">
                <div className="flex items-center justify-between">
                    <p className="text-sm font-bold text-ink">Leads</p>
                    <span className="rounded-md bg-brand-600 px-2.5 py-1 text-[10px] font-semibold text-white">+ New lead</span>
                </div>
                <div className="mt-3 flex gap-1.5">
                    {['All 24', 'New 9', 'Contacted 6', 'Won 9'].map((tab, index) => (
                        <span key={tab} className={`rounded-full px-2.5 py-1 text-[10px] font-medium ${index === 0 ? 'bg-ink text-white' : 'bg-slate-100 text-slate-600'}`}>
                            {tab}
                        </span>
                    ))}
                </div>
                <div className="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-100">
                    {leads.map(([name, interest, source, stage, stageClass, followUp]) => (
                        <div key={name} className="grid grid-cols-[1.3fr_1fr_auto] items-center gap-2 px-3 py-2">
                            <div className="min-w-0">
                                <p className="truncate text-[11px] font-semibold text-ink">{name}</p>
                                <p className="truncate text-[9px] text-slate-500">
                                    {interest} · {source}
                                </p>
                            </div>
                            <span className="text-[9px] text-slate-500">Follow up: {followUp}</span>
                            <span className={`rounded-full px-2 py-0.5 text-[9px] font-semibold ${stageClass}`}>{stage}</span>
                        </div>
                    ))}
                </div>
            </div>
        </BrowserFrame>
    );
}

/** WhatsApp conversation with an AI-suggested reply and an automation note. */
export function ChatMockup() {
    return (
        <BrowserFrame url="app.autowave.co.in/inbox">
            <div className="space-y-2 bg-[#efeae2] p-4">
                <div className="flex items-center gap-2 pb-1">
                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-500 text-[10px] font-bold text-white">PK</span>
                    <div>
                        <p className="text-[11px] font-semibold text-ink">Priya K.</p>
                        <p className="flex items-center gap-1 text-[9px] text-slate-500">
                            <WhatsAppIcon sx={{ fontSize: 10, color: '#25D366' }} /> WhatsApp
                        </p>
                    </div>
                </div>
                <p className="max-w-[75%] rounded-lg rounded-tl-none bg-white px-3 py-2 text-[11px] text-slate-700 shadow-sm">Hi! Do you have a slot for a haircut this Sunday evening?</p>
                <p className="ml-auto max-w-[75%] rounded-lg rounded-tr-none bg-[#d9fdd3] px-3 py-2 text-[11px] text-slate-700 shadow-sm">
                    Yes! Sunday 5:30 pm is free with Sana. Shall I book it for you? 💇‍♀️
                </p>
                <div className="flex items-center gap-1.5 rounded-lg border border-dashed border-brand-300 bg-white/80 px-3 py-2 text-[10px] text-brand-800">
                    <span className="font-semibold">✨ AI suggestion</span>
                    <span className="truncate text-slate-500">“Booked! You will get a reminder 2 hours before.”</span>
                </div>
                <div className="flex items-center gap-1.5 text-[10px] text-slate-600">
                    <CheckCircleIcon sx={{ fontSize: 12, color: '#0d9488' }} /> Automation: reminder scheduled for Sunday 3:30 pm
                </div>
            </div>
        </BrowserFrame>
    );
}

const slots = ['9:00', '10:00', '11:00', '12:00', '4:00', '5:00', '6:00', '7:00'];
const taken = new Set(['10:00', '12:00', '6:00']);

/** Online booking: pick a day and a free slot. */
export function BookingMockup({ title = 'Book a slot', color = '#4f46e5' }) {
    return (
        <BrowserFrame>
            <div className="p-4">
                <p className="flex items-center gap-1.5 text-xs font-bold text-ink">
                    <CalendarMonthIcon sx={{ fontSize: 14, color }} /> {title}
                </p>
                <div className="mt-3 flex gap-1.5">
                    {['Fri 9', 'Sat 10', 'Sun 11', 'Mon 12'].map((day, index) => (
                        <span
                            key={day}
                            className={`flex-1 rounded-lg border py-1.5 text-center text-[10px] font-semibold ${index === 1 ? 'text-white' : 'border-slate-200 text-slate-600'}`}
                            style={index === 1 ? { backgroundColor: color, borderColor: color } : undefined}
                        >
                            {day}
                        </span>
                    ))}
                </div>
                <div className="mt-3 grid grid-cols-4 gap-1.5">
                    {slots.map((slot) => (
                        <span
                            key={slot}
                            className={`rounded-md py-1.5 text-center text-[10px] font-medium ${taken.has(slot) ? 'bg-slate-100 text-slate-300 line-through' : slot === '5:00' ? 'text-white' : 'bg-white text-slate-700 ring-1 ring-slate-200'}`}
                            style={slot === '5:00' ? { backgroundColor: color } : undefined}
                        >
                            {slot}
                        </span>
                    ))}
                </div>
                <div className="mt-3 rounded-lg py-2 text-center text-[11px] font-semibold text-white" style={{ backgroundColor: color }}>
                    Confirm booking
                </div>
            </div>
        </BrowserFrame>
    );
}

/** Small floating notification used around the hero illustration. */
export function Toast({ icon, title, body, className = '' }) {
    return (
        <div aria-hidden="true" className={`flex items-center gap-2.5 rounded-xl bg-white/95 px-3 py-2.5 shadow-xl ring-1 shadow-brand-900/10 ring-slate-100 backdrop-blur ${className}`}>
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">{icon}</span>
            <span className="min-w-0">
                <span className="block text-[11px] font-semibold text-ink">{title}</span>
                <span className="block truncate text-[10px] text-slate-500">{body}</span>
            </span>
        </div>
    );
}
