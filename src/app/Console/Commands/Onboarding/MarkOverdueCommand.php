<?php

declare(strict_types=1);

namespace App\Console\Commands\Onboarding;

use App\Domain\Onboarding\Services\AssignmentService;
use Illuminate\Console\Command;

/**
 * php artisan onboarding:mark-overdue
 *
 * Batch-marks course assignments as overdue when due_date < now()
 * and status is pending or in_progress.
 * Runs daily via scheduler (see routes/console.php).
 */
class MarkOverdueCommand extends Command
{
    protected $signature = 'onboarding:mark-overdue';

    protected $description = 'Mark overdue course assignments (due_date < now, status pending/in_progress)';

    public function handle(AssignmentService $service): int
    {
        $count = $service->markOverdue();

        $this->info("Marked {$count} assignment(s) as overdue.");

        return self::SUCCESS;
    }
}
