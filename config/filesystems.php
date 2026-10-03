<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // S3-compatible object storage (MinIO on production) for uploads. The app writes to
        // MEDIA_ENDPOINT (loopback on the server); browsers load MEDIA_URL (HTTPS, read-only proxy).
        // The bucket must allow anonymous GetObject; it holds only public files.
        'media' => [
            'driver' => 's3',
            'key' => env('MEDIA_ACCESS_KEY'),
            'secret' => env('MEDIA_SECRET_KEY'),
            'region' => env('MEDIA_REGION', 'us-east-1'),
            'bucket' => env('MEDIA_BUCKET', 'autowave-public'),
            'endpoint' => env('MEDIA_ENDPOINT', 'http://127.0.0.1:9000'),
            'url' => env('MEDIA_URL'),
            'use_path_style_endpoint' => true,
            // File names are unique (ULID), so browsers may cache them for a year.
            'options' => ['CacheControl' => 'public, max-age=31536000, immutable'],
            'throw' => true,
            'report' => false,
        ],

        // Private object storage (customer documents). No public URL and no anonymous access: files are
        // streamed through AttachmentController after a permission check.
        'files' => [
            'driver' => 's3',
            'key' => env('MEDIA_ACCESS_KEY'),
            'secret' => env('MEDIA_SECRET_KEY'),
            'region' => env('MEDIA_REGION', 'us-east-1'),
            'bucket' => env('MEDIA_PRIVATE_BUCKET', 'autowave-private'),
            'endpoint' => env('MEDIA_ENDPOINT', 'http://127.0.0.1:9000'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
