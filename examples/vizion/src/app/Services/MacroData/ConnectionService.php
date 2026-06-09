<?php

namespace App\Services\MacroData;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

class ConnectionService
{
    protected const CONNECTION = 'macrodata';

    /**
     * Track the last connected company to skip redundant purge+reconnect.
     * Within a single PHP-FPM request lifecycle this avoids destroying and
     * re-creating the PDO when getData() and getGroupRows() are called
     * back-to-back for the same company (e.g. warm sequential requests in
     * tinker or a batched API call). Company ID is used as the cache key.
     *
     * Reset to null on any connection error to force a fresh connect.
     *
     * IMPORTANT — request-scope safety: ConnectionService must NOT be bound
     * as a singleton in the service container. Laravel resolves it as a
     * transient (new instance per resolve) by default, which means
     * $connectedCompanyId is reset at the start of each HTTP request and
     * cannot leak across requests from different companies in the same
     * PHP-FPM worker process. If you ever add a singleton binding, this
     * cache MUST be reset explicitly at the beginning of every request
     * (e.g. via middleware).
     */
    protected ?int $connectedCompanyId = null;

    /**
     * Configure macrodata connection for the specified company.
     *
     * Skips DB::purge() if the same company is already connected in this
     * request lifecycle — avoids PDO teardown/recreation overhead (~200-500 ms
     * round-trip penalty per purge on a remote MySQL instance).
     */
    public function connect(Company $company): void
    {
        if (!$company->macrodata_host || !$company->macrodata_database) {
            throw new \RuntimeException(__('companies.macrodata_not_configured'));
        }

        // Skip purge + reconnect if already connected to the same company.
        // This is safe because credentials are per-company and immutable within
        // the request: the company object is loaded once and reused.
        if ($this->connectedCompanyId === $company->id) {
            return;
        }

        config([
            'database.connections.' . self::CONNECTION . '.host' => $company->macrodata_host,
            'database.connections.' . self::CONNECTION . '.port' => $company->macrodata_port ?? 3306,
            'database.connections.' . self::CONNECTION . '.database' => $company->macrodata_database,
            'database.connections.' . self::CONNECTION . '.username' => $company->macrodata_username,
            'database.connections.' . self::CONNECTION . '.password' => $company->macrodata_password,
        ]);

        DB::purge(self::CONNECTION);

        $this->connectedCompanyId = $company->id;
    }

    /**
     * Check if connection to company's MacroData is possible.
     */
    public function test(Company $company): bool
    {
        try {
            $this->connect($company);
            DB::connection(self::CONNECTION)->getPdo();

            return true;
        } catch (\Exception $e) {
            // Reset cached company so next connect() forces a fresh purge+reconnect.
            $this->connectedCompanyId = null;
            return false;
        }
    }
}
