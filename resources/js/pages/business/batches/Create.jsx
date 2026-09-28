import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import BatchForm from '@/modules/education/BatchForm';

export default function Create({ courses, teachers, defaultCourseId }) {
    const form = useForm({
        course_id: defaultCourseId ?? courses[0]?.id ?? '',
        name: '',
        starts_on: '',
        ends_on: '',
        weekdays: [],
        start_time: '',
        end_time: '',
        capacity: '',
        teacher_tenant_user_id: '',
        room: '',
        fee: '',
        is_active: true,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/batches');
    };

    return (
        <AppLayout title="Add batch">
            <Button component={Link} href="/courses" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Courses
            </Button>
            <PageHeader title="Add batch" description="A group of students taking a course together, on a fixed schedule." />
            <Card variant="outlined" className="max-w-4xl">
                <CardContent>
                    {courses.length === 0 ? (
                        <p className="text-sm text-slate-600">
                            Add a course first.{' '}
                            <Link href="/courses" className="text-brand-700 underline">
                                Go to courses
                            </Link>
                        </p>
                    ) : (
                        <BatchForm form={form} onSubmit={submit} submitLabel="Add batch" cancelHref="/courses" courses={courses} teachers={teachers} />
                    )}
                </CardContent>
            </Card>
        </AppLayout>
    );
}
