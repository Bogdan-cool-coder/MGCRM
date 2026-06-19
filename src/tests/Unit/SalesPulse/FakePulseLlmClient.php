<?php

declare(strict_types=1);

namespace Tests\Unit\SalesPulse;

use App\Domain\SalesPulse\Contracts\PulseLlmClient;

/**
 * FakePulseLlmClient — a test double for the SalesPulse LLM seam (spec §5). It
 * never touches the network: completeText returns a canned body, completeWithTool
 * returns a canned tool-input array, and the last-seen payload/system prompt are
 * captured so tests can assert payload building.
 *
 * `$available` toggles isAvailable() so a test can drive the offline-fallback path
 * without an API key. `$throwOnCall` makes the next call throw to exercise the
 * try/catch fallback.
 */
class FakePulseLlmClient implements PulseLlmClient
{
    public bool $available = true;

    public bool $throwOnCall = false;

    public ?string $lastSystemPrompt = null;

    public ?string $lastPayload = null;

    public ?string $lastToolName = null;

    /** @var array<string, mixed> */
    public array $lastToolSchema = [];

    public string $textReply = 'BODY';

    /** @var array<string, mixed> */
    public array $toolReply = [];

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function completeText(string $chatType, string $systemPrompt, string $userPayload): string
    {
        $this->lastSystemPrompt = $systemPrompt;
        $this->lastPayload = $userPayload;

        if ($this->throwOnCall) {
            throw new \RuntimeException('boom');
        }

        return $this->textReply;
    }

    public function completeWithTool(
        string $chatType,
        string $systemPrompt,
        string $userPayload,
        string $toolName,
        array $toolSchema,
    ): array {
        $this->lastSystemPrompt = $systemPrompt;
        $this->lastPayload = $userPayload;
        $this->lastToolName = $toolName;
        $this->lastToolSchema = $toolSchema;

        if ($this->throwOnCall) {
            throw new \RuntimeException('boom');
        }

        return $this->toolReply;
    }
}
