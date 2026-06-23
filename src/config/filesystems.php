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

        /*
        |----------------------------------------------------------------------
        | Documents disk (Contracts domain — S2.1)
        |----------------------------------------------------------------------
        | Non-public local storage for contract docx/pdf files and docx templates.
        | Access via Sanctum Bearer + Storage::disk('documents')->download().
        | Not symlinked to public/storage — intentionally private.
        */
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/private/documents'),
            'serve' => false,
            'throw' => false,
            // BUG-CERT-PERM: queue worker may run as root with tight umask → dirs end up
            // 0700, web cannot read PDFs (404). Force 0755 for new subdirectories so that
            // php-fpm / nginx (www-data) can traverse into onboarding/certificates/{id}/.
            'directory_visibility' => 'public',
        ],

        /*
        |----------------------------------------------------------------------
        | CRM Files disk (entity card uploads — M2)
        |----------------------------------------------------------------------
        | Non-public local storage for files uploaded to contact / company cards.
        | Swap driver to 's3' (and provide AWS_* env vars) for production use.
        | Access via Sanctum Bearer + CrmFileService::download().
        */
        'crm_files' => [
            'driver' => env('CRM_FILES_DISK_DRIVER', 'local'),
            'root'   => storage_path('app/private/crm_files'),
            'serve'  => false,
            'throw'  => false,
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
