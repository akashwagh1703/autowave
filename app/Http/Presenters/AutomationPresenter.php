<?php

namespace App\Http\Presenters;

use App\Domain\Automation\Enums\JobStatus;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationJob;
use App\Domain\Automation\Models\AutomationLog;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Support\AutomationCatalog;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Shapes automations, runs, logs and messages for the business app. */
class AutomationPresenter
{
    public function __construct(private readonly AutomationCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function automation(Automation $automation, bool $withSteps = true): array
    {
        return [
            'id' => $automation->id,
            'name' => $automation->name,
            'description' => $automation->description,
            'trigger' => $automation->trigger,
            'trigger_label' => AutomationCatalog::triggerLabel($automation->trigger),
            'trigger_available' => $this->catalog->trigger($automation->trigger) !== null,
            'is_active' => $automation->is_active,
            'once_per_subject' => $automation->once_per_subject,
            'template_key' => $automation->template_key,
            'steps' => $withSteps ? array_map(fn (array $step) => [...$step, 'summary' => $this->summary($step)], $automation->stepDefinitions()) : null,
            'runs_count' => $automation->runs_count ?? null,
            'active_runs_count' => $automation->active_runs_count ?? null,
            'failed_runs_count' => $automation->failed_runs_count ?? null,
            'last_run_at' => isset($automation->runs_max_created_at) ? Carbon::parse($automation->runs_max_created_at, 'UTC')->toIso8601String() : null,
            'created_at' => $automation->created_at?->toIso8601String(),
            'updated_at' => $automation->updated_at?->toIso8601String(),
        ];
    }

    /** The builder's editable definition. */
    public function definition(Automation $automation): array
    {
        return [
            'name' => $automation->name,
            'description' => $automation->description,
            'trigger' => $automation->trigger,
            'is_active' => $automation->is_active,
            'once_per_subject' => $automation->once_per_subject,
            'steps' => $automation->stepDefinitions(),
        ];
    }

    /**
     * @param  iterable<AutomationRun>  $runs
     * @return list<array<string, mixed>>
     */
    public function runs(iterable $runs): array
    {
        $runs = collect($runs);
        $subjects = $this->subjects($runs);

        return $runs->map(fn (AutomationRun $run) => $this->run($run, $subjects[$run->subject_type.':'.$run->subject_id] ?? null))->all();
    }

    /**
     * @param  ?array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    public function run(AutomationRun $run, ?array $subject = null): array
    {
        $subject ??= $this->subjects(collect([$run]))[$run->subject_type.':'.$run->subject_id] ?? null;

        return [
            'id' => $run->id,
            'automation' => $run->automation ? [
                'id' => $run->automation->id,
                'name' => $run->automation->name,
                'deleted' => $run->automation->trashed(),
            ] : null,
            'trigger' => $run->trigger,
            'trigger_label' => AutomationCatalog::triggerLabel($run->trigger),
            'subject' => $subject ?? ['type' => $run->subject_type, 'id' => $run->subject_id, 'label' => Str::headline($run->subject_type).' #'.$run->subject_id, 'url' => null, 'deleted' => true],
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'is_final' => $run->status->isFinal(),
            'depth' => $run->depth,
            'step_count' => $run->stepCount(),
            'payload' => $run->payload ?? [],
            'error' => $run->error,
            'attempts' => $run->attempts,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function runDetail(AutomationRun $run): array
    {
        $run->loadMissing(['automation', 'jobs', 'logs', 'messages']);
        $jobs = $run->jobs->keyBy('step_index');

        return [
            ...$this->run($run),
            'steps' => collect($run->steps ?? [])->map(function (array $step, int $index) use ($jobs) {
                /** @var ?AutomationJob $job */
                $job = $jobs[$index] ?? null;

                return [
                    'index' => $index,
                    'type' => $step['type'],
                    'action' => $step['action'] ?? null,
                    'summary' => $this->summary($step),
                    'status' => $job?->status->value ?? 'not_started',
                    'run_at' => $job?->run_at?->toIso8601String(),
                    'attempts' => $job?->attempts ?? 0,
                    'error' => $job?->status === JobStatus::Failed ? $job->error : null,
                    'finished_at' => $job?->finished_at?->toIso8601String(),
                ];
            })->all(),
            'logs' => $run->logs->map(fn (AutomationLog $log) => [
                'id' => $log->id,
                'step_index' => $log->step_index,
                'level' => $log->level,
                'event' => $log->event,
                'message' => $log->message,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all(),
            'messages' => $run->messages->map(fn (OutboundMessage $message) => $this->message($message))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function message(OutboundMessage $message): array
    {
        return [
            'id' => $message->id,
            'channel' => $message->channel,
            'channel_label' => config("messaging.channels.{$message->channel}.label", Str::headline($message->channel)),
            'recipient' => $message->maskedRecipient(),
            'recipient_name' => $message->recipient_name,
            'subject' => $message->subject,
            'body' => $message->body,
            'status' => $message->status->value,
            'status_label' => $message->status->label(),
            'simulated' => $message->simulated,
            'attempts' => $message->attempts,
            'error' => $message->error,
            'can_retry' => $message->status === MessageStatus::Failed,
            'queued_at' => $message->queued_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }

    /**
     * One-line description of a step, e.g. "Wait 4 hours" or "If lead stage is New".
     *
     * @param  array{type: string, action?: ?string, config?: array<string, mixed>}  $step
     */
    public function summary(array $step): string
    {
        $config = $step['config'] ?? [];

        return match ($step['type']) {
            'condition' => $this->conditionSummary($config),
            'wait' => $this->waitSummary($config),
            default => $this->actionSummary($step['action'] ?? '', $config),
        };
    }

    private function conditionSummary(array $config): string
    {
        $rules = array_map(function (array $rule) {
            $field = AutomationCatalog::definition('fields', $rule['field']);
            $label = mb_strtolower($field['label'] ?? $rule['field']);
            $operator = config('automation.operator_labels.'.$rule['operator'], $rule['operator']);

            if (in_array($rule['operator'], ['is_set', 'is_not_set', 'is_true', 'is_false'], true)) {
                return "{$label} {$operator}";
            }

            $value = $rule['value'] ?? null;

            if (isset($field['options'])) {
                $labels = array_column($this->catalog->options($field['options']), 'label', 'value');
                $value = collect((array) $value)->map(fn ($item) => $labels[(string) $item] ?? (string) $item)->join(', ');
            }

            return "{$label} {$operator} ".(is_array($value) ? implode(', ', $value) : $value);
        }, $config['rules'] ?? []);

        return 'If '.implode(($config['match'] ?? 'all') === 'any' ? ' or ' : ' and ', $rules);
    }

    private function waitSummary(array $config): string
    {
        $amount = (int) ($config['amount'] ?? 0);
        $unit = $config['unit'] ?? 'minutes';
        $duration = $amount.' '.Str::plural(Str::singular($unit), $amount);

        return match ($config['mode'] ?? 'delay') {
            'before_start' => $amount === 0 ? 'Wait until the appointment starts' : "Wait until {$duration} before the appointment",
            'after_start' => $amount === 0 ? 'Wait until the appointment starts' : "Wait until {$duration} after the appointment starts",
            default => "Wait {$duration}",
        };
    }

    private function actionSummary(string $action, array $config): string
    {
        $label = config("automation.actions.{$action}.label", Str::headline($action));

        $detail = match ($action) {
            'send_whatsapp' => Str::limit($config['message'] ?? '', 60),
            'send_email', 'send_notification' => Str::limit($config['subject'] ?? '', 60),
            'create_task' => Str::limit($config['title'] ?? '', 60),
            'assign_lead' => ($config['mode'] ?? 'auto') === 'auto' ? 'using the assignment rules' : 'to a team member',
            'update_lead' => collect($this->catalog->options('lead_stages'))->firstWhere('value', $config['stage'] ?? null)['label'] ?? ($config['stage'] ?? ''),
            'update_customer' => $config['tag'] ?? '',
            'ai_draft_reply' => Str::limit($config['instructions'] ?? '', 60),
            default => '',
        };

        return $detail !== '' ? "{$label}: {$detail}" : $label;
    }

    /**
     * Labels and links for the runs' subjects, loaded in one query per type (deleted ones too).
     *
     * @param  Collection<int, AutomationRun>  $runs
     * @return array<string, array{type: string, id: int, label: string, url: ?string, deleted: bool}>
     */
    private function subjects(Collection $runs): array
    {
        $result = [];

        foreach ($runs->groupBy('subject_type') as $type => $group) {
            $class = AutomationRun::SUBJECTS[$type] ?? null;

            if (! $class) {
                continue;
            }

            $query = $class::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $query->withTrashed();
            }

            if ($class === Appointment::class || $class === Order::class) {
                $query->with('customer');
            }

            if ($class === Conversation::class) {
                $query->with(['customer', 'lead']);
            }

            foreach ($query->whereKey($group->pluck('subject_id')->unique()->all())->get() as $model) {
                $deleted = method_exists($model, 'trashed') && $model->trashed();

                $result["{$type}:{$model->getKey()}"] = [
                    'type' => $type,
                    'id' => $model->getKey(),
                    'label' => match (true) {
                        $model instanceof Lead, $model instanceof Customer => $model->name,
                        $model instanceof Appointment => trim(($model->customer?->name ?? 'Appointment').' · '.$model->starts_at->setTimezone(TenantTime::timezone())->format('D j M, g:i A')),
                        $model instanceof Order => 'Order '.$model->reference().($model->customer ? ' · '.$model->customer->name : ''),
                        $model instanceof Conversation => 'Chat with '.$model->displayName(),
                        default => Str::headline($type).' #'.$model->getKey(),
                    },
                    'url' => $deleted ? null : match ($type) {
                        'lead' => route('leads.show', $model->getKey(), false),
                        'customer' => route('customers.show', $model->getKey(), false),
                        'appointment' => route('appointments.show', $model->getKey(), false),
                        'order' => route('orders.show', $model->getKey(), false),
                        'conversation' => route('inbox.show', $model->getKey(), false),
                        default => null,
                    },
                    'deleted' => $deleted,
                ];
            }
        }

        return $result;
    }
}
