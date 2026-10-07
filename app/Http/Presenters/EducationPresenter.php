<?php

namespace App\Http\Presenters;

use App\Domain\Education\Enums\AttendanceStatus;
use App\Domain\Education\Enums\DemoStatus;
use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\ClassSession;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeeInstalment;
use App\Domain\Education\Models\FeePayment;
use App\Domain\Education\Support\BatchSchedule;
use App\Support\TenantTime;

/** Browser-safe shapes for the education pages (courses, batches, students, fees, demo classes). */
final class EducationPresenter
{
    /** @return array<string, mixed> */
    public static function course(Course $course): array
    {
        return [
            'id' => $course->id,
            'name' => $course->name,
            'description' => $course->description,
            'fee' => $course->fee,
            'duration_label' => $course->duration_label,
            'is_active' => $course->is_active,
            'batches_count' => $course->batches_count ?? null,
            'students_count' => $course->students_count ?? null,
            'image' => $course->relationLoaded('image') && $course->image
                ? ['id' => $course->image->id, 'url' => $course->image->url()]
                : null,
            'deleted' => $course->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    public static function batch(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'course_id' => $batch->course_id,
            'course' => $batch->relationLoaded('course') && $batch->course
                ? ['id' => $batch->course->id, 'name' => $batch->course->name, 'fee' => $batch->course->fee, 'deleted' => $batch->course->trashed()]
                : null,
            'name' => $batch->name,
            'label' => $batch->relationLoaded('course') && $batch->course ? "{$batch->course->name} · {$batch->name}" : $batch->name,
            'starts_on' => $batch->starts_on?->toDateString(),
            'ends_on' => $batch->ends_on?->toDateString(),
            'weekdays' => array_map('intval', $batch->weekdays ?? []),
            'start_time' => $batch->startTime(),
            'end_time' => $batch->endTime(),
            'schedule' => BatchSchedule::describe($batch),
            'capacity' => $batch->capacity,
            'room' => $batch->room,
            'fee' => $batch->fee,
            'effective_fee' => $batch->relationLoaded('course') ? $batch->effectiveFee() : null,
            'teacher_tenant_user_id' => $batch->teacher_tenant_user_id,
            'teacher' => $batch->relationLoaded('teacher') && $batch->teacher
                ? ['id' => $batch->teacher->id, 'name' => $batch->teacher->user?->name]
                : null,
            'is_active' => $batch->is_active,
            'students_count' => $batch->active_enrolments_count ?? null,
            'seats_left' => isset($batch->active_enrolments_count) && $batch->capacity !== null
                ? max(0, $batch->capacity - $batch->active_enrolments_count)
                : null,
            'deleted' => $batch->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    public static function enrolment(Enrolment $enrolment): array
    {
        return [
            'id' => $enrolment->id,
            'status' => $enrolment->status->value,
            'status_label' => $enrolment->status->label(),
            'enrolled_on' => $enrolment->enrolled_on?->toDateString(),
            'fee_total' => (string) $enrolment->fee_total,
            'discount' => (string) $enrolment->discount,
            'net_fee' => $enrolment->netFee(),
            'amount_paid' => (string) $enrolment->amount_paid,
            'balance' => $enrolment->balance(),
            'notes' => $enrolment->notes,
            'completed_at' => $enrolment->completed_at?->toIso8601String(),
            'dropped_at' => $enrolment->dropped_at?->toIso8601String(),
            'created_at' => $enrolment->created_at?->toIso8601String(),
            'customer_id' => $enrolment->customer_id,
            'customer' => $enrolment->relationLoaded('customer') && $enrolment->customer
                ? [
                    'id' => $enrolment->customer->id,
                    'name' => $enrolment->customer->name,
                    'phone' => $enrolment->customer->phone,
                    'email' => $enrolment->customer->email,
                    'deleted' => $enrolment->customer->trashed(),
                ]
                : null,
            'batch_id' => $enrolment->batch_id,
            'batch' => $enrolment->relationLoaded('batch') && $enrolment->batch ? self::batch($enrolment->batch) : null,
            'lead_id' => $enrolment->lead_id,
            'next_due' => $enrolment->relationLoaded('instalments')
                ? (($next = $enrolment->instalments->first(fn (FeeInstalment $instalment) => ! $instalment->isPaid())) ? self::instalment($next) : null)
                : null,
            'instalments' => $enrolment->relationLoaded('instalments')
                ? $enrolment->instalments->map(fn (FeeInstalment $instalment) => self::instalment($instalment))->values()->all()
                : null,
            'payments' => $enrolment->relationLoaded('payments')
                ? $enrolment->payments->map(fn (FeePayment $payment) => self::payment($payment))->values()->all()
                : null,
            'creator' => $enrolment->relationLoaded('creator') && $enrolment->creator
                ? ['id' => $enrolment->creator->id, 'name' => $enrolment->creator->name]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function instalment(FeeInstalment $instalment): array
    {
        $today = TenantTime::now()->toDateString();

        return [
            'id' => $instalment->id,
            'sequence' => $instalment->sequence,
            'due_on' => $instalment->due_on->toDateString(),
            'amount' => (string) $instalment->amount,
            'amount_paid' => (string) $instalment->amount_paid,
            'due' => $instalment->due(),
            'is_paid' => $instalment->isPaid(),
            'is_overdue' => ! $instalment->isPaid() && $instalment->due_on->toDateString() < $today,
            'reminded_at' => $instalment->reminded_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(FeePayment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (string) $payment->amount,
            'method' => $payment->method,
            'method_label' => $payment->methodLabel(),
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'recorded_by' => $payment->relationLoaded('recorder') && $payment->recorder ? $payment->recorder->name : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function session(ClassSession $session): array
    {
        return [
            'id' => $session->id,
            'held_on' => $session->held_on->toDateString(),
            'topic' => $session->topic,
            'present_count' => $session->present_count ?? null,
            'records_count' => $session->records_count ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public static function demo(DemoClass $demo): array
    {
        return [
            'id' => $demo->id,
            'scheduled_at' => $demo->scheduled_at->toIso8601String(),
            'status' => $demo->status->value,
            'status_label' => $demo->status->label(),
            'notes' => $demo->notes,
            'lead_id' => $demo->lead_id,
            'lead' => $demo->relationLoaded('lead') && $demo->lead
                ? ['id' => $demo->lead->id, 'name' => $demo->lead->name, 'phone' => $demo->lead->phone, 'deleted' => $demo->lead->trashed()]
                : null,
            'course' => $demo->relationLoaded('course') && $demo->course ? ['id' => $demo->course->id, 'name' => $demo->course->name] : null,
            'batch' => $demo->relationLoaded('batch') && $demo->batch ? ['id' => $demo->batch->id, 'name' => $demo->batch->name] : null,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function enrolmentStatuses(): array
    {
        return array_map(fn (EnrolmentStatus $status) => ['value' => $status->value, 'label' => $status->label()], EnrolmentStatus::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function attendanceStatuses(): array
    {
        return array_map(fn (AttendanceStatus $status) => ['value' => $status->value, 'label' => $status->label()], AttendanceStatus::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function demoStatuses(): array
    {
        return array_map(fn (DemoStatus $status) => ['value' => $status->value, 'label' => $status->label()], DemoStatus::cases());
    }
}
