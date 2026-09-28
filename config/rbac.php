<?php

/*
|--------------------------------------------------------------------------
| RBAC catalogue
|--------------------------------------------------------------------------
|
| Source of truth for tenant permissions and template roles. RbacSeeder syncs
| this file into the `permissions` table and the template roles
| (roles.tenant_id = null). New tenants receive copies of the template roles.
|
| Keys are "{group}.{action}". Every key is registered as a Gate ability, so
| `$user->can('leads.assign')` and the `can:leads.assign` middleware work.
|
| Document changes in docs/04-security/authorization.md.
|
*/

return [

    'permissions' => [
        'customers' => [
            'view' => 'View customers',
            'create' => 'Create customers',
            'update' => 'Update customers',
            'delete' => 'Delete customers',
        ],
        'leads' => [
            'view' => 'View leads',
            'create' => 'Create leads',
            'update' => 'Update leads',
            'assign' => 'Assign leads to team members',
            'delete' => 'Delete leads',
        ],
        'services' => [
            'view' => 'View services',
            'manage' => 'Create, edit and delete services and categories',
        ],
        'resources' => [
            'view' => 'View staff and bookable resources',
            'manage' => 'Manage staff and resources, their working hours and time off',
        ],
        'appointments' => [
            'view' => 'View appointments',
            'create' => 'Create appointments',
            'update' => 'Update appointments',
            'cancel' => 'Cancel appointments',
        ],
        'products' => [
            'view' => 'View products',
            'create' => 'Create products',
            'update' => 'Update products',
            'delete' => 'Delete products',
        ],
        'orders' => [
            'view' => 'View orders',
            'create' => 'Create orders',
            'update' => 'Update orders',
        ],
        'offers' => [
            'view' => 'View offers and coupon codes',
            'manage' => 'Create, edit and delete offers and coupon codes',
        ],
        'courses' => [
            'view' => 'View courses and batches',
            'manage' => 'Create, edit and delete courses and batches',
        ],
        'students' => [
            'view' => 'View students, demo classes and attendance',
            'admit' => 'Admit students and schedule demo classes',
            'update' => 'Update enrolments, drop or complete students',
            'attendance' => 'Take class attendance',
        ],
        'fees' => [
            'view' => 'View fees and dues',
            'collect' => 'Record and remove fee payments',
            'manage' => 'Change fee plans, discounts and instalments',
        ],
        'reservations' => [
            'view' => 'View table reservations and tables',
            'manage' => 'Create and update reservations, manage tables',
        ],
        'conversations' => [
            'view' => 'View the inbox and conversations',
            'reply' => 'Reply, send templates, close and reopen conversations',
            'assign' => 'Assign conversations to team members',
        ],
        'ai' => [
            'use' => 'Use AI suggestions, summaries and writing help',
            'assistant' => 'Ask the business assistant about business data',
        ],
        'automation' => [
            'view' => 'View automations',
            'create' => 'Create automations',
            'update' => 'Update automations',
            'delete' => 'Delete automations',
        ],
        'website' => [
            'view' => 'View the website setup',
            'manage' => 'Edit, design and publish the website',
        ],
        'reports' => [
            'view' => 'View reports',
        ],
        'users' => [
            'view' => 'View team members',
            'manage' => 'Invite, update and remove team members',
        ],
        'roles' => [
            'manage' => 'Create and edit roles',
        ],
        'settings' => [
            'view' => 'View business settings',
            'update' => 'Update business settings',
        ],
    ],

    /*
    | Template roles. `grants_all` roles receive every permission, including
    | permissions added in the future. `locked` roles cannot be edited or deleted.
    */
    'roles' => [
        'owner' => [
            'name' => 'Owner',
            'description' => 'Full access to the business workspace.',
            'grants_all' => true,
            'locked' => true,
            'permissions' => [],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Runs day-to-day operations.',
            'permissions' => [
                'customers.*', 'leads.*', 'services.*', 'resources.*', 'appointments.*', 'products.*', 'orders.*',
                'offers.*', 'courses.*', 'students.*', 'fees.*', 'reservations.*',
                'conversations.*', 'automation.*', 'website.*', 'ai.*', 'reports.view', 'users.view', 'settings.view',
            ],
        ],
        'receptionist' => [
            'name' => 'Receptionist',
            'description' => 'Handles walk-ins, enquiries and bookings.',
            'permissions' => [
                'customers.view', 'customers.create', 'customers.update',
                'leads.view', 'leads.create', 'leads.update',
                'services.view', 'resources.view',
                'appointments.*', 'orders.view', 'orders.create',
                'offers.view', 'courses.view', 'students.view', 'students.admit', 'fees.view', 'fees.collect', 'reservations.*',
                'conversations.view', 'conversations.reply',
                'ai.use',
            ],
        ],
        'sales_executive' => [
            'name' => 'Sales Executive',
            'description' => 'Follows up and converts leads.',
            'permissions' => [
                'customers.view', 'customers.create', 'customers.update',
                'leads.view', 'leads.create', 'leads.update', 'leads.assign',
                'services.view', 'resources.view',
                'appointments.view', 'appointments.create',
                'courses.view', 'students.view', 'students.admit', 'reservations.view',
                'conversations.view', 'conversations.reply',
                'ai.use',
            ],
        ],
        'staff' => [
            'name' => 'Staff',
            'description' => 'Sees their schedule and assigned customers.',
            'permissions' => [
                'customers.view', 'services.view', 'resources.view', 'appointments.view', 'appointments.update',
                'courses.view', 'students.view', 'students.attendance', 'reservations.view',
            ],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Views orders and financial reports.',
            'permissions' => [
                'customers.view', 'services.view', 'products.view', 'orders.view', 'reports.view',
                'offers.view', 'courses.view', 'students.view', 'fees.view',
            ],
        ],
    ],

];
