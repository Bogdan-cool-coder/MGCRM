<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\Automation\Enums\ActionStatus;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Immutable outcome of an action-handler execute() call.
 *
 * Handlers never touch AutomationRun — they return this and the dispatcher
 * persists it (status + summary/data into result_json). `data` is the structured
 * audit payload (e.g. created activity id, old/new field values, webhook status
 * code); `summary` is a short human-readable line for the runs UI.
 *
 * `deferredJobFactory` is set ONLY by network handlers (tg_notify / webhook):
 * the side-effect must not block the web request, so the handler returns a
 * `queued` result plus a factory that builds the queue job once the dispatcher
 * knows the persisted run id. The dispatcher finalizes the run as `queued`, then
 * dispatches `($deferredJobFactory)($run->id)`. Keeping the factory here (rather
 * than dispatching inside the handler) lets the dispatcher own the run lifecycle
 * and lets dry-run / tests inspect the result without firing a job.
 */
final readonly class ActionResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  (Closure(int): ShouldQueue)|null  $deferredJobFactory
     */
    public function __construct(
        public ActionStatus $status,
        public string $summary,
        public array $data = [],
        public ?Closure $deferredJobFactory = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(string $summary, array $data = []): self
    {
        return new self(ActionStatus::Success, $summary, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function skipped(string $reason, array $data = []): self
    {
        return new self(ActionStatus::Skipped, $reason, ['reason' => $reason] + $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  (Closure(int): ShouldQueue)|null  $deferredJobFactory
     */
    public static function queued(string $summary, array $data = [], ?Closure $deferredJobFactory = null): self
    {
        return new self(ActionStatus::Queued, $summary, $data, $deferredJobFactory);
    }

    /**
     * Flatten to the array stored in AutomationRun.result.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['summary' => $this->summary] + $this->data;
    }
}
