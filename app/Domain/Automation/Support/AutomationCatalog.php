<?php

namespace App\Domain\Automation\Support;

use App\Domain\Automation\Actions\Steps\StepAction;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Lead\Actions\AssignLead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use InvalidArgumentException;

/**
 * config/automation.php filtered to what the current tenant can use (enabled modules and engines),
 * plus the tenant lists that conditions and actions refer to (stages, services, team members…).
 */
class AutomationCatalog
{
    /** @var array<string, list<array{value: string, label: string}>> */
    private array $options = [];

    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, array<string, mixed>> */
    public function triggers(): array
    {
        return array_filter(config('automation.triggers'), fn (array $trigger) => $this->available($trigger));
    }

    /** @return ?array<string, mixed> */
    public function trigger(string $key): ?array
    {
        return $this->triggers()[$key] ?? null;
    }

    public function subjectOf(string $trigger): ?string
    {
        return self::definition('triggers', $trigger)['subject'] ?? null;
    }

    /**
     * A catalogue entry by key. Trigger and field keys contain dots ("lead.created"), so they
     * cannot be read with config("automation.triggers.{$key}").
     *
     * @return ?array<string, mixed>
     */
    public static function definition(string $catalogue, string $key): ?array
    {
        return config("automation.{$catalogue}")[$key] ?? null;
    }

    public static function triggerLabel(string $trigger): string
    {
        return self::definition('triggers', $trigger)['label'] ?? $trigger;
    }

    /** @return list<string> */
    public function entitiesFor(string $trigger): array
    {
        return config('automation.subjects.'.$this->subjectOf($trigger).'.entities', []);
    }

    /** @return array<string, array<string, mixed>> */
    public function fieldsFor(string $trigger): array
    {
        $entities = $this->entitiesFor($trigger);

        return array_filter(config('automation.fields'), fn (array $field) => in_array($field['entity'], $entities, true) && $this->available($field));
    }

    /** @return array<string, array<string, mixed>> */
    public function actionsFor(string $trigger): array
    {
        $entities = $this->entitiesFor($trigger);

        return array_filter(config('automation.actions'), fn (array $action) => array_intersect($action['entities'], $entities) !== [] && $this->available($action));
    }

    /** @return array<string, array<string, mixed>> */
    public function waitModesFor(string $trigger): array
    {
        $subject = $this->subjectOf($trigger);

        return array_filter(config('automation.wait_modes'), fn (array $mode) => ! isset($mode['subject']) || $mode['subject'] === $subject);
    }

    /** @return array<string, array<string, mixed>> */
    public function variablesFor(string $trigger): array
    {
        $entities = ['business', ...$this->entitiesFor($trigger)];

        return array_filter(config('automation.variables'), fn (array $variable) => in_array($variable['entity'], $entities, true));
    }

    public function action(string $key): StepAction
    {
        $class = config("automation.actions.{$key}.class") ?? throw new InvalidArgumentException("Unknown automation action [{$key}].");

        return app($class);
    }

    /** @return list<array{value: string, label: string}> */
    public function options(string $list): array
    {
        return $this->options[$list] ??= match ($list) {
            'lead_stages' => LeadStage::query()->active()->orderBy('sort_order')->get()
                ->map(fn (LeadStage $stage) => ['value' => $stage->code, 'label' => $stage->name])->all(),
            'lead_sources' => LeadSource::query()->active()->orderBy('sort_order')->get()
                ->map(fn (LeadSource $source) => ['value' => $source->code, 'label' => $source->name])->all(),
            'appointment_statuses' => array_map(fn (AppointmentStatus $status) => ['value' => $status->value, 'label' => $status->label()], AppointmentStatus::cases()),
            'appointment_sources' => array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(config('booking.sources')), config('booking.sources')),
            'order_statuses' => array_map(fn (OrderStatus $status) => ['value' => $status->value, 'label' => $status->label()], OrderStatus::cases()),
            'order_sources' => array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(config('commerce.sources')), config('commerce.sources')),
            'order_fulfilment' => array_map(fn (string $value, array $method) => ['value' => $value, 'label' => $method['label']], array_keys(config('commerce.fulfilment')), config('commerce.fulfilment')),
            'payment_statuses' => array_map(fn (PaymentStatus $status) => ['value' => $status->value, 'label' => $status->label()], PaymentStatus::cases()),
            'services' => $this->context->hasEngine('service')
                ? Service::query()->orderBy('name')->get()->map(fn (Service $service) => ['value' => (string) $service->id, 'label' => $service->name])->all()
                : [],
            'booking_resources' => $this->context->hasEngine('booking')
                ? BookingResource::query()->orderBy('name')->get()->map(fn (BookingResource $resource) => ['value' => (string) $resource->id, 'label' => $resource->name])->all()
                : [],
            'members' => app(AssignLead::class)->assignableMembers()
                ->map(fn (TenantUser $member) => ['value' => (string) $member->id, 'label' => (string) $member->user?->name])->all(),
            default => throw new InvalidArgumentException("Unknown option list [{$list}]."),
        };
    }

    /** @return list<string> */
    public function optionValues(string $list): array
    {
        return array_column($this->options($list), 'value');
    }

    /**
     * Everything the automation builder needs, limited to what this tenant can use.
     *
     * @return array<string, mixed>
     */
    public function forBuilder(): array
    {
        $triggers = $this->triggers();
        $fields = array_merge(...array_map(fn (string $key) => $this->fieldsFor($key), array_keys($triggers)) ?: [[]]);
        $actions = array_merge(...array_map(fn (string $key) => $this->actionsFor($key), array_keys($triggers)) ?: [[]]);

        return [
            'triggers' => collect($triggers)->map(fn (array $trigger, string $key) => [
                'key' => $key,
                'label' => $trigger['label'],
                'group' => $trigger['group'],
                'description' => $trigger['description'] ?? null,
                'subject' => $trigger['subject'],
                'entities' => $this->entitiesFor($key),
            ])->values()->all(),
            'fields' => collect($fields)->map(fn (array $field, string $key) => [
                'key' => $key,
                'label' => $field['label'],
                'entity' => $field['entity'],
                'type' => $field['type'],
                'options' => isset($field['options']) ? $this->options($field['options']) : null,
            ])->values()->all(),
            'operators' => collect(config('automation.operators'))->map(fn (array $operators) => array_map(
                fn (string $operator) => ['value' => $operator, 'label' => config("automation.operator_labels.{$operator}", $operator)],
                $operators,
            ))->all(),
            'actions' => collect($actions)->map(fn (array $action, string $key) => [
                'key' => $key,
                'label' => $action['label'],
                'group' => $action['group'],
                'entities' => $action['entities'],
            ])->values()->all(),
            'waitModes' => collect(config('automation.wait_modes'))->map(fn (array $mode, string $key) => [
                'key' => $key,
                'label' => $mode['label'],
                'subject' => $mode['subject'] ?? null,
            ])->values()->all(),
            'waitUnits' => array_keys(config('automation.wait_units')),
            'variables' => collect(config('automation.variables'))->map(fn (array $variable, string $key) => [
                'key' => $key,
                'label' => $variable['label'],
                'entity' => $variable['entity'],
            ])->values()->all(),
            'stages' => in_array('update_lead', array_keys($actions), true) ? $this->options('lead_stages') : [],
            'members' => in_array('assign_lead', array_keys($actions), true) ? $this->options('members') : [],
            'channels' => collect(config('messaging.channels'))->map(function (array $channel, string $key) {
                $resolved = app(ChannelResolver::class)->resolve($key);

                return ['label' => $channel['label'], 'simulated' => $resolved['simulated'], 'window' => $resolved['window_hours'] !== null];
            })->all(),
            'templates' => in_array('send_whatsapp', array_keys($actions), true)
                ? MessageTemplate::query()->approved()->where('channel', 'whatsapp')->orderBy('name')->orderBy('language')->get()
                    ->map(fn (MessageTemplate $template) => [
                        'name' => $template->name,
                        'language' => $template->language,
                        'category' => $template->category,
                        'body' => $template->body,
                        'variables' => $template->variables,
                    ])->all()
                : [],
            'limits' => config('automation.limits'),
        ];
    }

    /** @param  array<string, mixed>  $item */
    private function available(array $item): bool
    {
        return (! isset($item['module']) || $this->context->hasModule($item['module']))
            && (! isset($item['engine']) || $this->context->hasEngine($item['engine']));
    }
}
