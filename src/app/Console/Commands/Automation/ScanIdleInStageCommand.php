<?php

declare(strict_types=1);

namespace App\Console\Commands\Automation;

use App\Domain\Automation\Services\AutomationScanner;
use Illuminate\Console\Command;

/**
 * php artisan automation:scan-idle
 *
 * Fires idle_in_stage_days automations: for every active rule, finds deals that
 * have sat in the watched stage for >= {days} and queues the action. Idempotent
 * across runs (the AutomationRun partial-unique index dedups on the deterministic
 * stage-entry timestamp), so it is safe to run every tick.
 *
 * Scheduled hourly via routes/console.php on the `automation` queue.
 */
class ScanIdleInStageCommand extends Command
{
    protected $signature = 'automation:scan-idle';

    protected $description = 'Fire idle_in_stage_days automations for deals idle beyond their threshold';

    public function handle(AutomationScanner $scanner): int
    {
        $claimed = $scanner->scanIdleInStage();

        $this->info("Idle scan: queued {$claimed} automation run(s).");

        return self::SUCCESS;
    }
}
