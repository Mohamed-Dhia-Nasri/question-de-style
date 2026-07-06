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

        // SVC-Export artifacts (REQ-M1-012): PRIVATE storage, short-lived
        // signed access only, automatic deletion at expiry. Never public.
        'exports' => [
            'driver' => 'local',
            'root' => storage_path('app/exports'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
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

        // PRIVATE media archive (expiring stories, REQ-M1-004; ADR-0013
        // object storage). Local driver for development; switch to an
        // S3-compatible driver (Hetzner Object Storage / R2) via env in
        // production. Never public — access only through short-lived
        // signed URLs (StoryMediaController).
        'media' => [
            'driver' => env('QDS_MEDIA_DRIVER', 'local'),
            'root' => storage_path('app/media'),
            'visibility' => 'private',
            'key' => env('QDS_MEDIA_S3_KEY'),
            'secret' => env('QDS_MEDIA_S3_SECRET'),
            'region' => env('QDS_MEDIA_S3_REGION'),
            'bucket' => env('QDS_MEDIA_S3_BUCKET'),
            'endpoint' => env('QDS_MEDIA_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('QDS_MEDIA_S3_PATH_STYLE', true),
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
