import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import BatchForm from '@/modules/education/BatchForm';

export default function Edit({ batch, courses, teachers }) {
    const form = useForm({
        course_id: batch.course_id,
        name: batch.name,
        starts_on: batch.starts_on ?? '',
        ends_on: batch.ends_on ?? '',
        weekdays: batch.weekdays ?? [],
        start_time: batch.start_time ?? '',
        end_time: batch.end_time ?? '',
        capacity: batch.capacity ?? '',
        teacher_tenant_user_id: batch.teacher_tenant_user_id ?? '',
        room: batch.room ?? '',
        fee: batch.fee ?? '',
        is_active: batch.is_active,
    });

    const courseOptions = courses.some((course) => course.id === batch.course_id) || !batch.course
        ? courses
        : [...courses, { id: batch.course_id, name: batch.course.name, fee: batch.course.fee }];

    const submit = (event) => {
        event.preventDefault();
        form.put(`/batches/${batch.id}`);
    };

    return (
        <AppLayout title={`Edit ${batch.name}`}>
            <Button component={Link} href={`/batches/${batch.id}`} startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                {batch.label}
            </Button>
            <PageHeader title="Edit batch" description="Schedule changes apply to future classes; attendance already taken is kept." />
            <Card variant="outlined" className="max-w-4xl">
                <CardContent>
                    <BatchForm form={form} onSubmit={submit} submitLabel="Save batch" cancelHref={`/batches/${batch.id}`} courses={courseOptions} teachers={teachers} />
                </CardContent>
            </Card>
        </AppLayout>
    );
}
