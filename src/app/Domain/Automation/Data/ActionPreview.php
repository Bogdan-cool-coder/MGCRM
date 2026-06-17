<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

/**
 * Immutable dry-run preview of an action — what WOULD happen, with no
 * side-effect and no AutomationRun written. Returned by ActionHandler::dryRun()
 * and surfaced by the POST /automations/{id}/test endpoint (P4).
 *
 * `wouldExecute` is false when the config is a no-op for this target (empty
 * recipient, field not whitelisted, missing template_code, …) — the UI shows
 * `reason` in that case. `data` carries the resolved preview (recipient,
 * message, old/new value, …).
 */
final readonly class ActionPreview
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public bool $wouldExecute,
        public string $summary,
        public array $data = [],
        public ?string $reason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function will(string $summary, array $data = []): self
    {
        return new self(true, $summary, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function wont(string $reason, array $data = []): self
    {
        return new self(false, $reason, $data, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'would_execute' => $this->wouldExecute,
            'summary' => $this->summary,
            'reason' => $this->reason,
        ], static fn ($v): bool => $v !== null) + $this->data;
    }
}
