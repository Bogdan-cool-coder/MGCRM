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

        // Internal storage for generated documents (PDF/docx). Shares the
        // "local" disk's root (storage/app/private) so the existing
        // "documents/{id}/document.pdf" relative paths resolve to the same files
        // — this disk is NOT web-public (no symlink in public/). Its sole
        // difference from "local" is filesystem permissions: "public" file +
        // directory visibility makes Flysystem create files 0644 and directories
        // 0755, so files written by the queue-worker container (root) stay
        // readable by the php-fpm pool (www-data). The default "local" disk uses
        // private visibility (0600 files / 0700 dirs), which makes root-written
        // files unreadable by www-data inside the container and breaks the
        // download endpoint with a spurious 409. A dedicated disk keeps this
        // loosened visibility scoped to documents only — "local" stays untouched.
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'public',
            'directory_visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'backups' => [
            'driver' => 'local',
            'root' => '/var/www/backups',
            'throw' => false,
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
