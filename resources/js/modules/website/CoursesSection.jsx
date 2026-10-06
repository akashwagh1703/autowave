import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import EventIcon from '@mui/icons-material/Event';
import ScheduleIcon from '@mui/icons-material/Schedule';
import SchoolIcon from '@mui/icons-material/SchoolOutlined';
import { formatDay } from '@/utils/booking';
import { formatMoney } from '@/utils/format';
import { alpha } from '@/utils/websiteTheme';
import ItemFiles from './ItemFiles';
import { ActionButton, Card, Section, SectionHeading, headingStyle, scrollToSection, useSite } from './site';

export default function CoursesSection({ config, data }) {
    const { locale, has, theme } = useSite();
    const canEnquire = config.show_enquire && has('contact');

    return (
        <Section id="courses" tone="muted">
            <SectionHeading title={config.heading} intro={config.intro} />
            <div className={`grid gap-5 ${data.length === 1 ? 'mx-auto max-w-2xl' : data.length === 2 ? 'md:grid-cols-2' : 'md:grid-cols-2 lg:grid-cols-3'}`}>
                {data.map((course) => (
                    <Card key={course.id} hover className="flex flex-col">
                        <div className="flex items-start justify-between gap-4">
                            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl" style={{ backgroundColor: alpha(theme.color, 0.1), color: theme.color }}>
                                <SchoolIcon />
                            </span>
                            {course.duration_label ? (
                                <span className="rounded-full px-3 py-1 text-xs font-semibold" style={{ backgroundColor: alpha(theme.color, 0.08), color: theme.color }}>
                                    {course.duration_label}
                                </span>
                            ) : null}
                        </div>
                        <h3 className="mt-5 text-xl text-slate-900" style={headingStyle(theme, { fontWeight: Math.min(theme.headingWeight, 700) })}>
                            {course.name}
                        </h3>
                        {course.description ? <p className="mt-2 text-sm leading-relaxed text-slate-600">{course.description}</p> : null}
                        {config.show_fees && course.fee !== null ? (
                            <p className="mt-4">
                                <span className="text-2xl font-bold" style={{ color: theme.color }}>
                                    {formatMoney(course.fee, locale.currency)}
                                </span>
                                <span className="ml-1 text-sm text-slate-500">course fee</span>
                            </p>
                        ) : null}
                        <ItemFiles item={course} className="mt-3" />
                        {config.show_batches && course.batches.length > 0 ? (
                            <ul className="mt-5 space-y-3 border-t border-slate-100 pt-5">
                                {course.batches.map((batch) => (
                                    <li key={batch.name} className="rounded-xl bg-slate-50 px-4 py-3 text-sm">
                                        <div className="flex items-start justify-between gap-3">
                                            <span className="font-semibold text-slate-800">{batch.name}</span>
                                            {config.show_fees && batch.fee !== null && batch.fee !== course.fee ? (
                                                <span className="shrink-0 font-semibold text-slate-700">{formatMoney(batch.fee, locale.currency)}</span>
                                            ) : null}
                                        </div>
                                        {batch.schedule ? (
                                            <span className="mt-1 flex items-center gap-1.5 text-slate-500">
                                                <ScheduleIcon sx={{ fontSize: 15 }} />
                                                {batch.schedule}
                                            </span>
                                        ) : null}
                                        {batch.starts_on ? (
                                            <span className="mt-0.5 flex items-center gap-1.5 text-slate-500">
                                                <EventIcon sx={{ fontSize: 15 }} />
                                                Starts {formatDay(batch.starts_on, { day: 'numeric', month: 'short', year: 'numeric' })}
                                            </span>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {canEnquire ? (
                            <div className="mt-auto pt-6">
                                <ActionButton variant="secondary" className="w-full" onClick={() => scrollToSection('contact')}>
                                    Enquire / book a demo <ArrowForwardIcon sx={{ fontSize: 16 }} />
                                </ActionButton>
                            </div>
                        ) : null}
                    </Card>
                ))}
            </div>
        </Section>
    );
}
