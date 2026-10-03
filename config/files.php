<?php

use App\Domain\Commerce\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Education\Models\Course;
use App\Domain\Service\Models\Service;

/*
|--------------------------------------------------------------------------
| Attachments: documents and videos (docs/06-integrations/minio.md)
|--------------------------------------------------------------------------
|
| Files attached to records (customers first; products, courses, the website
| and the inbox later). Private files are only ever streamed through an
| authorised controller; public files live on the media disk and load from
| its public URL. Images for the website and products stay in config('website.media').
|
| The type is decided by the file's content, not its name. Every kind lists the
| content types it accepts and the extension each is stored with.
|
*/

return [

    'disks' => [
        'public' => env('FILES_PUBLIC_DISK', env('WEBSITE_MEDIA_DISK', 'public')),
        'private' => env('FILES_PRIVATE_DISK', 'local'),
    ],

    'kinds' => [
        'document' => [
            'label' => 'Document',
            'max_kb' => 10 * 1024,
            'types' => [
                'application/pdf' => 'pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ],
        ],
        'video' => [
            'label' => 'Video',
            'max_kb' => 50 * 1024,
            'types' => [
                'video/mp4' => 'mp4',
                'video/webm' => 'webm',
            ],
        ],
    ],

    // What may own attachments: the model, `kinds` allowed (with an optional per-kind limit in `kind_max`),
    // storage `folder` under tenant/{id}/, `visibility`, the per-record limit, and the permissions needed to see
    // (and download) or upload and delete its files. Public files are shown on the business website.
    'owners' => [
        'customer' => [
            'model' => Customer::class,
            'kinds' => ['document'],
            'folder' => 'documents/customers',
            'visibility' => 'private',
            'max' => 50,
            'view' => 'documents.view',
            'manage' => 'documents.manage',
        ],
        'product' => [
            'model' => Product::class,
            'kinds' => ['video', 'document'],
            'kind_max' => ['video' => 1, 'document' => 3],
            'folder' => 'catalog/products',
            'visibility' => 'public',
            'max' => 4,
            'view' => 'products.view',
            'manage' => 'products.update',
        ],
        'service' => [
            'model' => Service::class,
            'kinds' => ['video', 'document'],
            'kind_max' => ['video' => 1, 'document' => 3],
            'folder' => 'catalog/services',
            'visibility' => 'public',
            'max' => 4,
            'view' => 'services.view',
            'manage' => 'services.manage',
        ],
        'course' => [
            'model' => Course::class,
            'kinds' => ['video', 'document'],
            'kind_max' => ['video' => 1, 'document' => 3],
            'folder' => 'catalog/courses',
            'visibility' => 'public',
            'max' => 4,
            'view' => 'courses.view',
            'manage' => 'courses.manage',
        ],
    ],

    'title_max' => 150,

    // Storage allowance per business: images, documents and videos together. A platform admin can override it.
    'quota_mb' => (int) env('FILES_QUOTA_MB', 1024),

];
