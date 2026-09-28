<?php

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of an automation for one subject (lead, customer, appointment or order). `steps` is a
 * snapshot taken when the run started, so editing the automation never changes a run in flight.
 */
#[Fillable([
    'tenant_id', 'automation_id', 'trigger', 'subject_type', 'subject_id', 'dedupe_key', 'depth',
    'status', 'steps', 'payload', 'attempts', 'error', 'started_at', 'completed_at',
])]
class AutomationRun extends Model
{
    use BelongsToTenant;

    /** @var array<string, class-string<Model>> */
    public const SUBJECTS = [
        'lead' => Lead::class,
        'customer' => Customer::class,
        'appointment' => Appointment::class,
        'order' => Order::class,
    ];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'steps' => 'array',
            'payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class)->withTrashed();
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(AutomationJob::class)->orderBy('step_index');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class)->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OutboundMessage::class)->orderBy('id');
    }

    public static function subjectTypeOf(Model $subject): string
    {
        $type = array_search($subject::class, self::SUBJECTS, true);

        return is_string($type) ? $type : throw new \InvalidArgumentException('Automations cannot run for '.$subject::class.'.');
    }

    /** The subject as it is now, or null when it no longer exists (soft-deleted leads and customers count as gone). */
    public function freshSubject(): ?Model
    {
        $class = self::SUBJECTS[$this->subject_type] ?? null;

        return $class ? $class::query()->find($this->subject_id) : null;
    }

    public function stepCount(): int
    {
        return count($this->steps ?? []);
    }
}
