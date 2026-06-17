<?php

declare(strict_types=1);

namespace App\Console\Commands\Automation;

use App\Domain\Automation\Services\AutomationScanner;
use Illuminate\Console\Command;

/**
 * php artisan automation:scan-date-field
 *
 * Fires date_field_approaching automations: for every active rule, finds deals
 * whose watched (whitelisted) date field falls within [now, now+{days}] and
 * queues the action. Idempotent across runs (the AutomationRun partial-unique
 * index dedups on the target date value), so it is safe to run every tick.
 *
 * Scheduled hourly via routes/console.php on the `automation` queue.
 */
class ScanDateFieldCommand extends Command
{
    protected $signature = 'automation:scan-date-field';

    protected $description = 'Fire date_field_approaching automations for deals with a date inside the window';

    public function handle(AutomationScanner $scanner): int
    {
        $claimed = $scanner->scanDateFieldApproaching();

        $this->info("Date-field scan: queued {$claimed} automation run(s).");

        return self::SUCCESS;
    }
}
