<?php

namespace App\Domain\Education\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Education\Events\EnrolmentCreated;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Support\FeePlan;
use App\Domain\Lead\Actions\ConvertLead;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admits a student to a batch (ADR-020), in one transaction:
 *
 * 1. the batch row is locked and its capacity checked;
 * 2. the student is resolved: from a lead (converted to a customer first when still open — the
 *    lead moves to the tenant's won stage, "Admitted" for coaching), an existing customer, or new
 *    details (reusing a customer with the same phone);
 * 3. the enrolment and its fee plan are created; an optional first payment is recorded.
 *
 * One active enrolment per student and batch (enrolments_active_unique).
 */
class AdmitStudent
{
    public function __construct(
        private readonly ConvertLead $convertLead,
        private readonly CreateCustomer $createCustomer,
        private readonly RecordActivity $recordActivity,
        private readonly RecordFeePayment $recordPayment,
    ) {}

    /**
     * @param  array{
     *     lead_id?: ?int,
     *     customer_id?: ?int,
     *     customer?: ?array{name: string, phone?: ?string, email?: ?string},
     *     batch_id: int,
     *     enrolled_on?: ?string,
     *     fee_total?: numeric-string|float|null,
     *     discount?: numeric-string|float|null,
     *     instalments?: ?list<array{due_on: string, amount: numeric-string|float}>,
     *     instalment_count?: ?int,
     *     first_due_on?: ?string,
     *     notes?: ?string,
     *     payment?: ?array{amount: numeric-string|float, method: string, reference?: ?string},
     * }  $data
     */
    public function handle(array $data, ?User $actor = null): Enrolment
    {
        $batch = Batch::query()->with('course')->find($data['batch_id'] ?? null)
            ?? throw ValidationException::withMessages(['batch_id' => __('Choose a batch.')]);

        if (! $batch->is_active || $batch->course?->trashed()) {
            throw ValidationException::withMessages(['batch_id' => __('This batch is not taking admissions.')]);
        }

        $today = TenantTime::now()->toDateString();
        $enrolledOn = filled($data['enrolled_on'] ?? null) ? (string) $data['enrolled_on'] : $today;
        $feeTotal = isset($data['fee_total']) && $data['fee_total'] !== '' ? number_format(max(0, (float) $data['fee_total']), 2, '.', '') : $batch->effectiveFee();
        $discount = isset($data['discount']) && $data['discount'] !== '' ? number_format(max(0, (float) $data['discount']), 2, '.', '') : '0.00';

        if (bccomp($discount, $feeTotal, 2) > 0) {
            throw ValidationException::withMessages(['discount' => __('The discount cannot be more than the fee.')]);
        }

        $net = bcsub($feeTotal, $discount, 2);
        $plan = ! empty($data['instalments'])
            ? FeePlan::normalize($data['instalments'], $net)
            : FeePlan::split($net, (int) ($data['instalment_count'] ?? 1), filled($data['first_due_on'] ?? null) ? (string) $data['first_due_on'] : $enrolledOn);

        try {
            return DB::transaction(function () use ($data, $actor, $batch, $enrolledOn, $feeTotal, $discount, $plan) {
                $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

                if ($locked->capacity !== null && $locked->activeEnrolments()->count() >= $locked->capacity) {
                    throw ValidationException::withMessages(['batch_id' => __('This batch is full (:capacity students).', ['capacity' => $locked->capacity])]);
                }

                [$customer, $lead] = $this->resolveStudent($data, $actor);

                if (Enrolment::query()->active()->where('batch_id', $locked->id)->where('customer_id', $customer->id)->exists()) {
                    throw ValidationException::withMessages(['batch_id' => __(':name is already in this batch.', ['name' => $customer->name])]);
                }

                $enrolment = Enrolment::query()->create([
                    'customer_id' => $customer->id,
                    'batch_id' => $locked->id,
                    'lead_id' => $lead?->id,
                    'status' => EnrolmentStatus::Active,
                    'enrolled_on' => $enrolledOn,
                    'fee_total' => $feeTotal,
                    'discount' => $discount,
                    'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                    'created_by_user_id' => $actor?->id,
                ]);

                foreach ($plan as $index => $row) {
                    $enrolment->instalments()->create(['sequence' => $index + 1, 'due_on' => $row['due_on'], 'amount' => $row['amount']]);
                }

                $enrolment->setRelations(['customer' => $customer, 'batch' => $batch]);

                $this->recordActivity->handle('admitted', lead: $lead, customer: $customer, actor: $actor, metadata: self::summary($enrolment));

                if (! empty($data['payment']['amount']) && (float) $data['payment']['amount'] > 0) {
                    $this->recordPayment->handle($enrolment, $data['payment'], $actor);
                }

                EnrolmentCreated::dispatch($enrolment);

                return $enrolment;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505' && str_contains($exception->getMessage(), 'enrolments_active_unique')) {
                throw ValidationException::withMessages(['batch_id' => __('This student is already in this batch.')]);
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> timeline metadata */
    public static function summary(Enrolment $enrolment): array
    {
        return [
            'enrolment_id' => $enrolment->id,
            'course' => $enrolment->batch?->course?->name,
            'batch' => $enrolment->batch?->name,
        ];
    }

    /** @return array{0: Customer, 1: ?Lead} */
    private function resolveStudent(array $data, ?User $actor): array
    {
        if (! empty($data['lead_id'])) {
            $lead = Lead::query()->with('stage')->find($data['lead_id'])
                ?? throw ValidationException::withMessages(['lead_id' => __('Choose a valid enquiry.')]);

            if ($lead->stage->outcome === StageOutcome::Lost) {
                throw ValidationException::withMessages(['lead_id' => __('This enquiry is marked as lost. Reactivate it first.')]);
            }

            if ($lead->stage->outcome !== StageOutcome::Won || ! $lead->customer_id) {
                $lead = $this->convertLead->handle($lead, $actor);
            }

            $customer = Customer::query()->find($lead->customer_id)
                ?? throw ValidationException::withMessages(['lead_id' => __('The customer for this enquiry was deleted.')]);

            return [$customer, $lead];
        }

        if (! empty($data['customer_id'])) {
            return [Customer::query()->find($data['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Choose a valid student.')]), null];
        }

        $inline = $data['customer'] ?? null;

        if (! is_array($inline) || blank($inline['name'] ?? null)) {
            throw ValidationException::withMessages(['customer_id' => __('Choose a student or enter their details.')]);
        }

        return [CreateCustomer::duplicateOf($inline['phone'] ?? null) ?? $this->createCustomer->handle([
            'name' => trim($inline['name']),
            'phone' => $inline['phone'] ?? null,
            'email' => $inline['email'] ?? null,
        ], $actor, ['via' => 'admission']), null];
    }
}
