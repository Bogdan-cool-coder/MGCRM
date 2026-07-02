<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Services;

use App\Domain\SalesPulse\Contracts\PulseLlmClient;
use App\Support\Ai\AiRetryService;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Facades\Tool as ToolFactory;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * PrismPulseLlmClient — the production PulseLlmClient (spec §5). Drives
 * AiRetryService (library-first reuse of the Anthropic cascade + retry + budget)
 * rather than calling Prism / Anthropic directly.
 *
 *   - completeText()      → /dayresults (Haiku, no tools) — returns response text.
 *   - completeWithTool()  → /weeklyreport: build a single Prism Tool from the
 *                           schema, force tool_choice to it (Anthropic
 *                           {type:'tool', name}), and capture the tool ARGUMENTS.
 *                           We never want a side-effecting tool body, so the tool
 *                           closure just records its args into a sink and returns
 *                           an empty string. We also read response->toolCalls as a
 *                           belt-and-braces fallback if the closure did not fire.
 *
 * isAvailable() is keyed off the Anthropic API key — no key → the report services
 * choose the offline fallback (spec §5.1 / §5.2) instead of erroring.
 */
class PrismPulseLlmClient implements PulseLlmClient
{
    public function __construct(
        private readonly AiRetryService $aiRetry,
    ) {}

    public function isAvailable(): bool
    {
        $key = config('prism.providers.anthropic.api_key');

        return is_string($key) && $key !== '';
    }

    public function completeText(string $chatType, string $systemPrompt, string $userPayload): string
    {
        $response = $this->aiRetry->executeWithRetry(
            $chatType,
            $systemPrompt,
            [new UserMessage($userPayload)],
        );

        return $response->text;
    }

    public function completeWithTool(
        string $chatType,
        string $systemPrompt,
        string $userPayload,
        string $toolName,
        array $toolSchema,
    ): array {
        /** @var array<string, mixed> $captured */
        $captured = [];

        $tool = $this->buildTool($toolName, $toolSchema, $captured);

        $response = $this->aiRetry->executeWithRetryAndToolChoice(
            $chatType,
            $systemPrompt,
            [new UserMessage($userPayload)],
            [$tool],
            $toolName, // force tool_choice on this tool (Anthropic {type:'tool',name}).
        );

        if ($captured !== []) {
            return $captured;
        }

        // Fallback: read the forced tool call's arguments off the response.
        foreach ($response->toolCalls as $call) {
            if ($call->name === $toolName) {
                /** @var array<string, mixed> $args */
                $args = $call->arguments();

                return $args;
            }
        }

        return [];
    }

    /**
     * Build a Prism Tool from the spec's tool schema. The closure captures the
     * provided args by reference into $sink and returns an empty acknowledgement
     * (we only want the structured input, not a side effect).
     *
     * @param  array<string, mixed>  $schema  ['description' => ..., 'properties' => [name => Schema]]
     * @param  array<string, mixed>  $sink
     */
    private function buildTool(string $name, array $schema, array &$sink): Tool
    {
        /** @var array<string, Schema> $properties */
        $properties = $schema['properties'] ?? [];

        $tool = ToolFactory::as($name)
            ->for((string) ($schema['description'] ?? 'Structured analysis output.'))
            ->using(function (...$args) use (&$sink): string {
                // Args arrive associatively from the tool call; record them.
                if (count($args) === 1 && is_array($args[0])) {
                    /** @var array<string, mixed> $first */
                    $first = $args[0];
                    $sink = $first;
                } else {
                    $sink = $args;
                }

                return 'ok';
            });

        foreach ($properties as $propName => $propSchema) {
            $tool = $tool->withParameter($propSchema, true);
        }

        return $tool;
    }

    /**
     * The weekly_analysis tool schema (spec §5.2) as Prism Schema objects:
     *   { movements_briefs:[{lead_id:int, brief:str}],
     *     stuck_briefs:[{lead_id:int, brief:str}],
     *     narrative:str }  — all required.
     *
     * Exposed statically so WeeklyReportService and tests share one definition.
     *
     * @return array{description: string, properties: array<string, Schema>}
     */
    public static function weeklyAnalysisSchema(): array
    {
        $briefItem = new ObjectSchema(
            name: 'brief_item',
            description: 'Один бриф по сделке.',
            properties: [
                new NumberSchema('lead_id', 'ID сделки (lead_id из payload).'),
                new StringSchema('brief', 'Короткая фраза на русском (40-80 знаков), своими словами.'),
            ],
            requiredFields: ['lead_id', 'brief'],
        );

        return [
            'description' => 'Переформулированные брифы топ-сделок и общий нарратив недели.',
            'properties' => [
                'movements_briefs' => new ArraySchema(
                    'movements_briefs',
                    'Брифы по top_movements (по одному на каждую сделку).',
                    $briefItem,
                ),
                'stuck_briefs' => new ArraySchema(
                    'stuck_briefs',
                    'Брифы по top_stuck (по одному на каждую сделку).',
                    $briefItem,
                ),
                'narrative' => new StringSchema(
                    'narrative',
                    'Общий разбор недели целиком (русский, 1000-1800 знаков).',
                ),
            ],
        ];
    }
}
