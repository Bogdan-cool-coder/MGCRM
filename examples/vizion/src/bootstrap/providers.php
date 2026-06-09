<?php

$providers = [
    App\Providers\AppServiceProvider::class,
];

// Telescope is a dev-only package (require-dev). Only register the provider
// when the class is actually available (i.e. composer installed with --dev).
if (class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
    $providers[] = App\Providers\TelescopeServiceProvider::class;
}

return $providers;
