import EventIcon from '@mui/icons-material/Event';
import ScheduleIcon from '@mui/icons-material/Schedule';
import { formatDay } from '@/utils/booking';
import { formatMoney } from '@/utils/format';
import ItemFiles from './ItemFiles';
import { ActionButton, Card, Section, SectionHeading, scrollToSection, useSite } from './site';

export default function CoursesSection({ config, data }) {
    const { locale, has } = useSite();
    const canEnquire = config.show_enquire && has('contact');

    return (
        <Section id="courses" tone="muted">
            <SectionHeading title={config.heading} intro={config.intro} />
            <div className="grid gap-4 sm:grid-cols-2">
                {data.map((course) => (
                    <Card key={course.id} className="flex flex-col">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <h3 className="font-semibold text-slate-900">{course.name}</h3>
                                {course.duration_label ? <p className="text-sm text-slate-500">{course.duration_label}</p> : null}
                            </div>
                            {config.show_fees && course.fee !== null ? (
                                <span className="shrink-0 font-semibold text-slate-900">{formatMoney(course.fee, locale.currency)}</span>
                            ) : null}
                        </div>
                        {course.description ? <p className="mt-2 text-sm text-slate-600">{course.description}</p> : null}
                        <ItemFiles item={course} className="mt-3" />
                        {config.show_batches && course.batches.length > 0 ? (
                            <ul className="mt-4 space-y-2 border-t border-slate-100 pt-4">
                                {course.batches.map((batch) => (
                                    <li key={batch.name} className="text-sm">
                                        <div className="flex items-start justify-between gap-3">
                                            <span className="font-medium text-slate-800">{batch.name}</span>
                                            {config.show_fees && batch.fee !== null && batch.fee !== course.fee ? (
                                                <span className="shrink-0 text-slate-700">{formatMoney(batch.fee, locale.currency)}</span>
                                            ) : null}
                                        </div>
                                        {batch.schedule ? (
                                            <span className="flex items-center gap-1 text-slate-500">
                                                <ScheduleIcon sx={{ fontSize: 15 }} />
                                                {batch.schedule}
                                            </span>
                                        ) : null}
                                        {batch.starts_on ? (
                                            <span className="flex items-center gap-1 text-slate-500">
                                                <EventIcon sx={{ fontSize: 15 }} />
                                                Starts {formatDay(batch.starts_on, { day: 'numeric', month: 'short', year: 'numeric' })}
                                            </span>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {canEnquire ? (
                            <div className="mt-auto pt-4">
                                <ActionButton variant="secondary" className="px-3 py-1.5" onClick={() => scrollToSection('contact')}>
                                    Enquire / book a demo
                                </ActionButton>
                            </div>
                        ) : null}
                    </Card>
                ))}
            </div>
        </Section>
    );
}
