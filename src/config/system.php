<?php

declare(strict_types=1);

// MGCRM system-maintenance configuration. Settings here gate privileged,
// destructive operational tooling (ARCHITECTURE.md §3 — env() only in config/;
// application code reads config('system.*')).

return [

    /*
    |--------------------------------------------------------------------------
    | Clean-reset switch
    |--------------------------------------------------------------------------
    |
    | "Сброс настроек" (admin-only) drops every table and re-seeds ONLY the
    | baseline configuration (roles/permissions, accounts, catalogs, pipelines,
    | approval routes, templates, lost-reasons, meeting-report registry). It is
    | a destructive operation, so it is OFF by default and must be turned on
    | explicitly per environment (e.g. dev/staging). On a production deploy
    | where SYSTEM_RESET_ENABLED is unset/false, POST /api/system/reset aborts.
    |
    */
    'reset_enabled' => env('SYSTEM_RESET_ENABLED', false),

];
