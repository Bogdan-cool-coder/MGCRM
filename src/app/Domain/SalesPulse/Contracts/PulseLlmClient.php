<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Contracts;

/**
 * PulseLlmClient — the SalesPulse report layer's seam over the AI stack. Two
 * report flows need an LLM (spec §5):
 *
 *   - /dayresults : a free-text analysis (Haiku, no tools).
 *   - /weeklyreport: a forced-tool structured output (Sonnet, tool_use
 *                    `weekly_analysis`) — we want the tool ARGUMENTS, not a
 *                    side-effecting tool execution.
 *
 * The default binding (PrismPulseLlmClient) drives App\Services\AI\AiRetryService
 * (library-first reuse of the cascade/retry/budget). Tests bind a fake to assert
 * payload building + brief application + offline fallback WITHOUT touching the
 * network — hence this interface (spec: abstract the LLM call).
 *
 * isAvailable() lets the report services pick the offline fallback (spec §5.1 /
 * §5.2) when there is no API key / Prism is not wired, without throwing.
 */
interface PulseLlmClient
{
    /**
     * Whether an AI call can be made (API key present / provider wired). When
     * false, the report services render their offline fallback.
     */
    public function isAvailable(): bool;

    /**
     * Free-text completion (spec §5.1 /dayresults — Haiku cascade). Returns the
     * model's text body.
     *
     * @param  string  $chatType  config('ai.providers.*.{chatType}') key.
     */
    public function completeText(string $chatType, string $systemPrompt, string $userPayload): string;

    /**
     * Forced structured output via a single tool (spec §5.2 /weeklyreport — Sonnet
     * cascade, tool_use `{toolName}`, tool_choice forced). Returns the tool-call
     * arguments as an associative array (the schema instance).
     *
     * @param  string  $chatType  config key (e.g. 'report_generation').
     * @param  array<string, mixed>  $toolSchema  Description + JSON-schema-ish param
     *                                            spec the implementation maps to a Prism Tool.
     * @return array<string, mixed> The tool input (movements_briefs/stuck_briefs/narrative).
     */
    public function completeWithTool(
        string $chatType,
        string $systemPrompt,
        string $userPayload,
        string $toolName,
        array $toolSchema,
    ): array;
}
