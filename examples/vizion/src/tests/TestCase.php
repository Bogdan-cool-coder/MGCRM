<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Override application bootstrap to FORCE testing DB env vars BEFORE Laravel
     * boots its config layer.
     *
     * Why we have to do this: docker-compose injects DB_CONNECTION=pgsql /
     * DB_DATABASE=vizion / DB_HOST=postgres etc. into the `app` container at
     * startup. Laravel's Dotenv loader is immutable by default — it does NOT
     * override existing env vars from .env.testing. As a result, even with
     * APP_ENV=testing and an .env.testing file pinning sqlite, the actual
     * `config('database.default')` resolved to pgsql, and RefreshDatabase
     * happily ran migrate:fresh against the live `vizion` DB, wiping it.
     *
     * Twice. In one session. So we forcibly putenv() these BEFORE bootstrap.
     */
    public function createApplication()
    {
        $forced = [
            'APP_ENV'        => 'testing',
            'DB_CONNECTION'  => 'sqlite',
            'DB_DATABASE'    => ':memory:',
            'DB_HOST'        => '',
            'DB_PORT'        => '',
            'DB_USERNAME'    => '',
            'DB_PASSWORD'    => '',
            'CACHE_STORE'    => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER'    => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'TELESCOPE_ENABLED' => 'false',
            'PULSE_ENABLED'  => 'false',
        ];

        foreach ($forced as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Hard guard: refuse to run if the test process is pointed at any DB
     * connection other than an isolated sqlite testing DB.
     *
     * Belt-and-braces: createApplication() above forces the env vars AND
     * phpunit.xml has force="true" AND .env.testing pins sqlite. This guard
     * catches future regressions (a test that explicitly switches the default
     * connection, a custom bootstrap that bypasses createApplication, etc.).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $default = config('database.default');

        if ($default !== 'sqlite') {
            throw new RuntimeException(
                "Tests must run against the sqlite testing DB, got '{$default}'. "
                ."Refusing to proceed — RefreshDatabase would wipe the live DB. "
                ."Check tests/TestCase.php::createApplication(), phpunit.xml <env>, and .env.testing."
            );
        }

        $database = config('database.connections.sqlite.database');

        if ($database !== ':memory:' && !str_contains((string) $database, 'test')) {
            throw new RuntimeException(
                "sqlite database must be ':memory:' or a path containing 'test', got '{$database}'. "
                ."Refusing to proceed."
            );
        }
    }
}
