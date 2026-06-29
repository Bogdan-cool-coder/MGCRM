<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Documents\GotenbergClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Fakes\FakeGotenbergClient;

abstract class TestCase extends BaseTestCase
{
    /**
     * Override application bootstrap to FORCE testing DB env vars BEFORE Laravel
     * boots its config layer.
     *
     * Why we have to do this: docker-compose injects DB_CONNECTION=pgsql /
     * DB_DATABASE=macro_crm / DB_HOST=postgres etc. into the `app` container at
     * startup. Laravel's Dotenv loader is immutable by default — it does NOT
     * override existing env vars from .env.testing. As a result, even with
     * APP_ENV=testing and an .env.testing file pinning sqlite, the actual
     * config('database.default') would resolve to pgsql, and RefreshDatabase
     * would happily run migrate:fresh against the live DB, wiping it.
     *
     * This is the second leg of the triple isolation: phpunit.xml force="true"
     * + this putenv() before bootstrap + the setUp() abort-guard below. The
     * $_ENV assignment alone does NOT win against Dotenv — only putenv() does.
     */
    public function createApplication()
    {
        $forced = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_HOST' => '',
            'DB_PORT' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'TELESCOPE_ENABLED' => 'false',
            'PULSE_ENABLED' => 'false',
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
                .'Refusing to proceed — RefreshDatabase would wipe the live DB. '
                .'Check tests/TestCase.php::createApplication(), phpunit.xml <env>, and .env.testing.'
            );
        }

        $database = config('database.connections.sqlite.database');

        if ($database !== ':memory:' && ! str_contains((string) $database, 'test')) {
            throw new RuntimeException(
                "sqlite database must be ':memory:' or a path containing 'test', got '{$database}'. "
                .'Refusing to proceed.'
            );
        }

        $this->fakeGotenbergByDefault();
        $this->seedRolesAndPermissions();
    }

    /**
     * Swap the real GotenbergClient for an in-memory fake in the test container
     * so NO test ever opens a socket to the live gotenberg service.
     *
     * Why this exists: certificate/contract PDF generation fires as a *side
     * effect* of unrelated flows — completing a course/quiz/lesson dispatches
     * GenerateCertificateJob synchronously (QUEUE_CONNECTION=sync in tests),
     * which calls GotenbergClient->officeToPdf over Http::. Tests whose subject
     * is the lesson/quiz never thought to fake Gotenberg, so they punched
     * through to http://gotenberg:3000 and died with "Could not resolve host:
     * gotenberg" (cURL 6) — a live-service dependency that violates the
     * isolation rule (tests NEVER hit live services).
     *
     * Why a container bind and NOT a base Http::fake(): Laravel's Http::fake()
     * MERGES stubs (first-registered wins for a matching URL). A default stub
     * in setUp() would therefore SHADOW per-test Http::fake([...forms...]) calls
     * that need a specific PDF body or a 5xx error — exactly the Contracts
     * TemplateCheck/ContractGeneration suites. Binding the fake client instead
     * leaves those tests untouched: they explicitly construct/resolve the REAL
     * GotenbergClient (via the GotenbergClient::class container key after their
     * own Http::fake, or `new TemplateCheckService(..., new GotenbergClient)`),
     * so they exercise the real HTTP layer while side-effect paths get the fake.
     *
     * Subclasses that DO want the real client back (none today) can rebind it in
     * their own setUp() after parent::setUp().
     */
    protected function fakeGotenbergByDefault(): void
    {
        $this->app->instance(GotenbergClient::class, new FakeGotenbergClient);
    }

    /**
     * Seed the spatie roles + permission matrix into the test DB (IAM-1).
     *
     * Authorization is permission-based: $user->can(...) / can: middleware /
     * Policy hasPermissionTo all resolve against the spatie grant matrix on the
     * `sanctum` guard. The User model's `saved` hook mirrors the `role` column
     * into a spatie role on every create — so the roles MUST exist before any
     * factory user is built, or syncRoles() throws RoleDoesNotExist.
     *
     * Runs only when the schema is present (RefreshDatabase migrated it) — pure
     * Unit tests without a DB are skipped. Idempotent: the seeder firstOrCreates.
     */
    private function seedRolesAndPermissions(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Forget all resolved auth guards so the next request re-authenticates from
     * scratch.
     *
     * Why this exists: Laravel boots the application ONCE per test method, so
     * every sub-request ($this->getJson(...), $this->postJson(...)) shares the
     * same `auth` manager. Illuminate\Auth\RequestGuard (which backs the
     * `sanctum` guard) memoizes the resolved user on first use and returns it
     * for every later request in the method — it never re-runs Sanctum's
     * findToken(). So if one test changes the Bearer mid-method (e.g. swaps a
     * full token for the limited 2FA temp token), the guard keeps returning the
     * FIRST request's user + access token, and per-token ability checks
     * (Verify2FA) test the wrong token.
     *
     * In production this never happens: PHP-FPM gives each HTTP request a fresh
     * process, app, and guard. forgetGuards() reproduces that per-request
     * isolation inside a single test method. Call it whenever a test switches
     * the Bearer token between sub-requests.
     */
    protected function flushAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
