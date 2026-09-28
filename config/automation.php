<?php

use App\Domain\Automation\Actions\Steps\AssignLeadStep;
use App\Domain\Automation\Actions\Steps\CreateTaskStep;
use App\Domain\Automation\Actions\Steps\MoveLeadStageStep;
use App\Domain\Automation\Actions\Steps\NotifyTeamStep;
use App\Domain\Automation\Actions\Steps\SendEmailStep;
use App\Domain\Automation\Actions\Steps\SendWhatsAppStep;
use App\Domain\Automation\Actions\Steps\TagCustomerStep;

/*
|--------------------------------------------------------------------------
| Automation engine (Phase 5, ADR-015)
|--------------------------------------------------------------------------
|
| Trigger → Condition → Wait → Action. A tenant's automations are built from
| the catalogues below; nothing about a specific business is hard-coded.
|
| Subjects: the record an automation runs for (lead, customer, appointment,
| order). Entities: the records a step can read or change for that subject — an
| appointment or order also exposes its customer, a lead its linked customer (if any).
|
*/

return [

    // Records reachable from each subject, in lookup order.
    'subjects' => [
        'lead' => ['label' => 'lead', 'entities' => ['lead', 'customer']],
        'customer' => ['label' => 'customer', 'entities' => ['customer']],
        'appointment' => ['label' => 'appointment', 'entities' => ['appointment', 'customer']],
        'order' => ['label' => 'order', 'entities' => ['order', 'customer']],
    ],

    /*
    | Triggers are domain events (StartAutomations maps each event class to one of
    | these keys). `module` / `engine` must be enabled for the tenant to use it.
    */
    'triggers' => [
        'lead.created' => ['label' => 'Lead created', 'group' => 'Leads', 'subject' => 'lead', 'module' => 'leads', 'description' => 'A new lead is added.'],
        'lead.updated' => ['label' => 'Lead details updated', 'group' => 'Leads', 'subject' => 'lead', 'module' => 'leads', 'description' => 'Name, phone, interest or other details change.'],
        'lead.status_changed' => ['label' => 'Lead stage changed', 'group' => 'Leads', 'subject' => 'lead', 'module' => 'leads', 'description' => 'A lead moves to another stage.'],
        'lead.assigned' => ['label' => 'Lead assigned', 'group' => 'Leads', 'subject' => 'lead', 'module' => 'leads', 'description' => 'A lead is assigned to a team member.'],
        'lead.converted' => ['label' => 'Lead converted', 'group' => 'Leads', 'subject' => 'lead', 'module' => 'leads', 'description' => 'A lead becomes a customer.'],
        'website.enquiry' => ['label' => 'Website enquiry received', 'group' => 'Leads', 'subject' => 'lead', 'module' => 'leads', 'description' => 'A visitor sends the enquiry form on your website (new or existing lead).'],
        'customer.created' => ['label' => 'Customer added', 'group' => 'Customers', 'subject' => 'customer', 'module' => 'customers', 'description' => 'A new customer is added.'],
        'appointment.created' => ['label' => 'Appointment booked', 'group' => 'Appointments', 'subject' => 'appointment', 'engine' => 'booking', 'description' => 'A new appointment is booked.'],
        'appointment.confirmed' => ['label' => 'Appointment confirmed', 'group' => 'Appointments', 'subject' => 'appointment', 'engine' => 'booking', 'description' => 'An appointment is confirmed (including bookings confirmed automatically).'],
        'appointment.rescheduled' => ['label' => 'Appointment rescheduled', 'group' => 'Appointments', 'subject' => 'appointment', 'engine' => 'booking', 'description' => 'An appointment moves to another time or person.'],
        'appointment.completed' => ['label' => 'Appointment completed', 'group' => 'Appointments', 'subject' => 'appointment', 'engine' => 'booking', 'description' => 'An appointment is marked completed.'],
        'appointment.cancelled' => ['label' => 'Appointment cancelled', 'group' => 'Appointments', 'subject' => 'appointment', 'engine' => 'booking', 'description' => 'An appointment is cancelled.'],
        'appointment.no_show' => ['label' => 'Appointment no-show', 'group' => 'Appointments', 'subject' => 'appointment', 'engine' => 'booking', 'description' => 'The customer did not turn up.'],
        'order.created' => ['label' => 'Order placed', 'group' => 'Orders', 'subject' => 'order', 'engine' => 'commerce', 'description' => 'A new order is placed, by the team or on your website.'],
        'order.confirmed' => ['label' => 'Order confirmed', 'group' => 'Orders', 'subject' => 'order', 'engine' => 'commerce', 'description' => 'An order is confirmed (including orders confirmed automatically).'],
        'order.ready' => ['label' => 'Order ready', 'group' => 'Orders', 'subject' => 'order', 'engine' => 'commerce', 'description' => 'An order is ready for pickup or out for delivery.'],
        'order.completed' => ['label' => 'Order completed', 'group' => 'Orders', 'subject' => 'order', 'engine' => 'commerce', 'description' => 'An order is handed over or delivered.'],
        'order.cancelled' => ['label' => 'Order cancelled', 'group' => 'Orders', 'subject' => 'order', 'engine' => 'commerce', 'description' => 'An order is cancelled.'],
        'order.paid' => ['label' => 'Order paid', 'group' => 'Orders', 'subject' => 'order', 'engine' => 'commerce', 'description' => 'The payments recorded for an order cover its total.'],
    ],

    /*
    | Condition fields. `options` names a tenant list resolved at runtime:
    | lead_stages, lead_sources, appointment_statuses, services, booking_resources,
    | order_statuses, order_sources, order_fulfilment, payment_statuses.
    */
    'fields' => [
        'lead.stage' => ['label' => 'Lead stage', 'entity' => 'lead', 'type' => 'enum', 'options' => 'lead_stages'],
        'lead.source' => ['label' => 'Lead source', 'entity' => 'lead', 'type' => 'enum', 'options' => 'lead_sources'],
        'lead.assigned' => ['label' => 'Lead is assigned', 'entity' => 'lead', 'type' => 'boolean'],
        'lead.contacted' => ['label' => 'Lead has been contacted', 'entity' => 'lead', 'type' => 'boolean'],
        'lead.phone' => ['label' => 'Lead phone', 'entity' => 'lead', 'type' => 'presence'],
        'lead.email' => ['label' => 'Lead email', 'entity' => 'lead', 'type' => 'presence'],
        'lead.interest' => ['label' => 'Lead interest', 'entity' => 'lead', 'type' => 'text'],
        'lead.estimated_value' => ['label' => 'Lead estimated value', 'entity' => 'lead', 'type' => 'number'],
        'customer.tags' => ['label' => 'Customer tags', 'entity' => 'customer', 'type' => 'tags'],
        'customer.phone' => ['label' => 'Customer phone', 'entity' => 'customer', 'type' => 'presence'],
        'customer.email' => ['label' => 'Customer email', 'entity' => 'customer', 'type' => 'presence'],
        'customer.city' => ['label' => 'Customer city', 'entity' => 'customer', 'type' => 'text'],
        'appointment.status' => ['label' => 'Appointment status', 'entity' => 'appointment', 'type' => 'enum', 'options' => 'appointment_statuses'],
        'appointment.service' => ['label' => 'Appointment service', 'entity' => 'appointment', 'type' => 'enum', 'options' => 'services', 'engine' => 'service'],
        'appointment.resource' => ['label' => 'Appointment with', 'entity' => 'appointment', 'type' => 'enum', 'options' => 'booking_resources'],
        'appointment.price' => ['label' => 'Appointment price', 'entity' => 'appointment', 'type' => 'number'],
        'appointment.source' => ['label' => 'Appointment source', 'entity' => 'appointment', 'type' => 'enum', 'options' => 'appointment_sources'],
        'order.status' => ['label' => 'Order status', 'entity' => 'order', 'type' => 'enum', 'options' => 'order_statuses'],
        'order.source' => ['label' => 'Order source', 'entity' => 'order', 'type' => 'enum', 'options' => 'order_sources'],
        'order.fulfilment' => ['label' => 'Order type', 'entity' => 'order', 'type' => 'enum', 'options' => 'order_fulfilment'],
        'order.payment_status' => ['label' => 'Order payment', 'entity' => 'order', 'type' => 'enum', 'options' => 'payment_statuses'],
        'order.total' => ['label' => 'Order total', 'entity' => 'order', 'type' => 'number'],
    ],

    'operators' => [
        'enum' => ['equals', 'not_equals', 'in', 'not_in'],
        'text' => ['equals', 'not_equals', 'contains', 'not_contains', 'is_set', 'is_not_set'],
        'number' => ['equals', 'gt', 'gte', 'lt', 'lte', 'is_set', 'is_not_set'],
        'boolean' => ['is_true', 'is_false'],
        'presence' => ['is_set', 'is_not_set'],
        'tags' => ['contains', 'not_contains'],
    ],

    'operator_labels' => [
        'equals' => 'is',
        'not_equals' => 'is not',
        'in' => 'is any of',
        'not_in' => 'is none of',
        'contains' => 'contains',
        'not_contains' => 'does not contain',
        'gt' => 'is more than',
        'gte' => 'is at least',
        'lt' => 'is less than',
        'lte' => 'is at most',
        'is_set' => 'is set',
        'is_not_set' => 'is empty',
        'is_true' => 'yes',
        'is_false' => 'no',
    ],

    /*
    | Actions. `entities`: the step needs at least one of these records (the first
    | available is used). `module`: must be enabled for the tenant.
    */
    'actions' => [
        'send_whatsapp' => ['label' => 'Send WhatsApp message', 'group' => 'Messages', 'class' => SendWhatsAppStep::class, 'entities' => ['lead', 'customer'], 'module' => 'messaging'],
        'send_email' => ['label' => 'Send email', 'group' => 'Messages', 'class' => SendEmailStep::class, 'entities' => ['lead', 'customer'], 'module' => 'messaging'],
        'send_notification' => ['label' => 'Notify the team', 'group' => 'Messages', 'class' => NotifyTeamStep::class, 'entities' => ['lead', 'customer', 'appointment', 'order']],
        'create_task' => ['label' => 'Create follow-up task', 'group' => 'Records', 'class' => CreateTaskStep::class, 'entities' => ['lead', 'customer']],
        'assign_lead' => ['label' => 'Assign lead', 'group' => 'Records', 'class' => AssignLeadStep::class, 'entities' => ['lead'], 'module' => 'leads'],
        'update_lead' => ['label' => 'Move lead to stage', 'group' => 'Records', 'class' => MoveLeadStageStep::class, 'entities' => ['lead'], 'module' => 'leads'],
        'update_customer' => ['label' => 'Tag customer', 'group' => 'Records', 'class' => TagCustomerStep::class, 'entities' => ['customer']],
    ],

    // Placeholders for message text, e.g. "Hi {{customer.first_name}}".
    'variables' => [
        'business.name' => ['label' => 'Business name', 'entity' => 'business'],
        'business.phone' => ['label' => 'Business phone', 'entity' => 'business'],
        'lead.name' => ['label' => 'Lead name', 'entity' => 'lead'],
        'lead.first_name' => ['label' => 'Lead first name', 'entity' => 'lead'],
        'lead.phone' => ['label' => 'Lead phone', 'entity' => 'lead'],
        'lead.interest' => ['label' => 'Lead interest', 'entity' => 'lead'],
        'lead.stage' => ['label' => 'Lead stage', 'entity' => 'lead'],
        'customer.name' => ['label' => 'Customer name', 'entity' => 'customer'],
        'customer.first_name' => ['label' => 'Customer first name', 'entity' => 'customer'],
        'customer.phone' => ['label' => 'Customer phone', 'entity' => 'customer'],
        'appointment.date' => ['label' => 'Appointment date', 'entity' => 'appointment'],
        'appointment.time' => ['label' => 'Appointment time', 'entity' => 'appointment'],
        'appointment.service' => ['label' => 'Appointment service', 'entity' => 'appointment'],
        'appointment.resource' => ['label' => 'Appointment with', 'entity' => 'appointment'],
        'order.number' => ['label' => 'Order number', 'entity' => 'order'],
        'order.total' => ['label' => 'Order total', 'entity' => 'order'],
        'order.items' => ['label' => 'Order items', 'entity' => 'order'],
        'order.fulfilment' => ['label' => 'Order type (pickup, delivery…)', 'entity' => 'order'],
    ],

    /*
    | Wait steps. `delay` waits from the moment the step runs; `before_start` and
    | `after_start` are relative to the appointment start and move with it when
    | the appointment is rescheduled.
    */
    'wait_modes' => [
        'delay' => ['label' => 'Wait for'],
        'before_start' => ['label' => 'Until before the appointment', 'subject' => 'appointment'],
        'after_start' => ['label' => 'Until after the appointment starts', 'subject' => 'appointment'],
    ],

    'wait_units' => ['minutes' => 1, 'hours' => 60, 'days' => 1440],

    'limits' => [
        'automations' => 100,
        'steps' => 20,
        'rules' => 10,
        'message' => 1000,
        'subject' => 150,
        'max_wait_days' => 90,
        'task_due_hours' => 720,
    ],

    /*
    | Default automations created for new tenants (and backfilled once for older
    | ones). A business type may list its own keys in
    | `configuration.automation_templates`. Templates whose trigger, fields or
    | actions the tenant cannot use are skipped. Message templates start paused
    | so nothing is sent to customers until the owner turns them on.
    */
    'default_templates' => ['new_lead_welcome', 'new_lead_followup', 'appointment_confirmation', 'appointment_reminder', 'no_show_followup', 'thank_you', 'new_online_order_alert', 'order_ready'],

    'templates' => [
        'new_lead_welcome' => [
            'name' => 'Welcome new leads on WhatsApp',
            'description' => 'Thanks every new lead straight away.',
            'trigger' => 'lead.created',
            'active' => false,
            'steps' => [
                ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'lead.phone', 'operator' => 'is_set']]]],
                ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => "Hi {{lead.first_name}}, thanks for contacting {{business.name}}! We'll get back to you shortly."]],
            ],
        ],
        'new_lead_followup' => [
            'name' => 'Follow up on new leads',
            'description' => 'If nobody has moved a new lead on after 4 hours, add a follow-up task.',
            'trigger' => 'lead.created',
            'active' => true,
            'steps' => [
                ['type' => 'wait', 'config' => ['mode' => 'delay', 'amount' => 4, 'unit' => 'hours']],
                ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'lead.stage', 'operator' => 'equals', 'value' => 'new']]]],
                ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Follow up with {{lead.name}}', 'due_in_hours' => 0]],
            ],
        ],
        'appointment_confirmation' => [
            'name' => 'Confirm appointments on WhatsApp',
            'description' => 'Sends the date and time as soon as an appointment is confirmed.',
            'trigger' => 'appointment.confirmed',
            'active' => false,
            'steps' => [
                ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hi {{customer.first_name}}, your appointment at {{business.name}} is confirmed for {{appointment.date}} at {{appointment.time}}.']],
            ],
        ],
        'appointment_reminder' => [
            'name' => 'Appointment reminder',
            'description' => 'Reminds the customer the day before, if the appointment is still on.',
            'trigger' => 'appointment.confirmed',
            'active' => false,
            'steps' => [
                ['type' => 'wait', 'config' => ['mode' => 'before_start', 'amount' => 24, 'unit' => 'hours']],
                ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'appointment.status', 'operator' => 'equals', 'value' => 'confirmed']]]],
                ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Reminder: your appointment at {{business.name}} is on {{appointment.date}} at {{appointment.time}}. Reply if you need to change it.']],
            ],
        ],
        'no_show_followup' => [
            'name' => 'Rebook no-shows',
            'description' => 'Adds a task to call customers who missed their appointment.',
            'trigger' => 'appointment.no_show',
            'active' => true,
            'steps' => [
                ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Call {{customer.name}} to rebook the missed appointment', 'due_in_hours' => 2]],
            ],
        ],
        'thank_you' => [
            'name' => 'Thank customers after a visit',
            'description' => 'Sends a thank-you message two hours after a completed appointment.',
            'trigger' => 'appointment.completed',
            'active' => false,
            'steps' => [
                ['type' => 'wait', 'config' => ['mode' => 'delay', 'amount' => 2, 'unit' => 'hours']],
                ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Thank you for visiting {{business.name}}, {{customer.first_name}}! We hope to see you again soon.']],
            ],
        ],
        'new_online_order_alert' => [
            'name' => 'Tell the team about website orders',
            'description' => 'Emails the owners as soon as a customer orders on your website.',
            'trigger' => 'order.created',
            'active' => true,
            'steps' => [
                ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'order.source', 'operator' => 'equals', 'value' => 'website']]]],
                ['type' => 'action', 'action' => 'send_notification', 'config' => [
                    'recipients' => 'owners',
                    'subject' => 'New website order {{order.number}}',
                    'message' => '{{customer.name}} ({{customer.phone}}) ordered {{order.items}}. Total: {{order.total}} ({{order.fulfilment}}).',
                ]],
            ],
        ],
        'order_ready' => [
            'name' => 'Tell customers their order is ready',
            'description' => 'Sends a WhatsApp message when an order is ready for pickup or out for delivery.',
            'trigger' => 'order.ready',
            'active' => false,
            'steps' => [
                ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hi {{customer.first_name}}, your order {{order.number}} from {{business.name}} is ready ({{order.fulfilment}}). Total: {{order.total}}.']],
            ],
        ],
    ],

    /*
    | Execution. Runs deeper than max_depth (automations triggered by automations)
    | are not started, which stops loops.
    */
    'queue' => env('AUTOMATION_QUEUE', 'automation'),
    'max_depth' => 3,
    'tries' => 3,
    'backoff' => [30, 120],

    // The scheduler re-dispatches work that was lost from the queue or left by a crashed worker.
    'stuck_queued_minutes' => 10,
    'stuck_running_minutes' => 15,
    'dispatch_batch' => 500,

    'per_page' => 25,

];
