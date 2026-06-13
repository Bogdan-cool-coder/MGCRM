<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Contracts\Models\MessageTemplateBinding;
use App\Domain\Contracts\Services\Helpers\MoneyFormatter;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Sales\Models\Deal;
use Illuminate\Support\Facades\Log;

/**
 * MessageTemplateService — рендер, buildVars, CRUD и матч по контексту.
 *
 * Метки: {{key}} с dot-notation пространства имён.
 * Рендер — чистый PHP str_replace, никакого Blade view().
 * Неизвестная метка остаётся нетронутой (не падаем).
 *
 * findForContext() — PHP-матч (SQLite-совместимый):
 *   score = количество совпавших непустых полей биндинга (AND-логика).
 *   Наибольший score побеждает; при равном — наименьший id.
 *   Wildcard-биндинг (все поля NULL) → score = 0, fallback.
 */
class MessageTemplateService
{
    /**
     * Render the template body (and optionally subject) by substituting
     * {{key}} placeholders with values from $vars.
     *
     * @param  array<string, string>  $vars  flat map dot-key → string value
     * @return array{body: string, subject: string|null, unresolved_keys: list<string>}
     */
    public function render(MessageTemplate $template, array $vars): array
    {
        [$body, $bodyUnresolved] = $this->substitute($template->body, $vars);

        $subject = null;
        $subjectUnresolved = [];

        if ($template->subject !== null && $template->subject !== '') {
            [$subject, $subjectUnresolved] = $this->substitute($template->subject, $vars);
        }

        /** @var list<string> $unresolved */
        $unresolved = array_values(array_unique([...$bodyUnresolved, ...$subjectUnresolved]));

        if (count($unresolved) > 0) {
            Log::warning('MessageTemplateService: unresolved keys', [
                'template_id' => $template->id,
                'keys' => $unresolved,
            ]);
        }

        return [
            'body' => $body,
            'subject' => $subject,
            'unresolved_keys' => $unresolved,
        ];
    }

    /**
     * Build a flat vars map from Eloquent model instances (or null).
     *
     * Context accepted:
     *   ['deal' => Deal|null, 'company' => Company|null, 'contact' => Contact|null,
     *    'user' => User|null, 'document' => object{number,total}|null]
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    public function buildVars(array $context): array
    {
        $vars = [];

        // date.* — always present
        $vars['date.today'] = now()->format('d.m.Y');
        $vars['date.tomorrow'] = now()->addDay()->format('d.m.Y');

        $deal = $context['deal'] ?? null;
        if ($deal instanceof Deal) {
            $vars['deal.name'] = (string) ($deal->title ?? '');
            $vars['deal.amount'] = $deal->amount !== null
                ? MoneyFormatter::format((int) $deal->amount)
                : '';
            // Eager-load guard: use relation if already loaded
            $stage = $deal->relationLoaded('stage') ? $deal->stage : null;
            $vars['deal.stage_name'] = $stage !== null ? (string) ($stage->name ?? '') : '';
        }

        $company = $context['company'] ?? null;
        if ($company instanceof Company) {
            $vars['company.name'] = (string) ($company->name ?? '');
            $vars['company.inn'] = (string) ($company->tax_id ?? '');
            $vars['company.city'] = (string) ($company->city ?? '');
        }

        $contact = $context['contact'] ?? null;
        if ($contact instanceof Contact) {
            $vars['contact.full_name'] = (string) ($contact->full_name ?? '');
            $vars['contact.phone'] = (string) ($contact->phone ?? '');
            $vars['contact.email'] = (string) ($contact->email ?? '');
        }

        $user = $context['user'] ?? null;
        if ($user instanceof User) {
            $vars['user.full_name'] = (string) ($user->full_name ?? '');
        }

        // document — accepts any object/model with number and total properties
        $document = $context['document'] ?? null;
        if ($document !== null) {
            $vars['document.number'] = (string) ($document->number ?? '');
            $total = $document->total ?? null;
            $vars['document.total_formatted'] = $total !== null
                ? MoneyFormatter::format((int) $total)
                : '';
        }

        return $vars;
    }

    /**
     * Find the most specific active MessageTemplate for the given filter context.
     *
     * @param  array{channel_kind?: ChannelKind|string|null, pipeline_stage_id?: int|null,
     *              pipeline_id?: int|null, activity_type?: ActivityType|string|null,
     *              automation_slot?: string|null}  $filter
     */
    public function findForContext(array $filter): ?MessageTemplate
    {
        $templates = MessageTemplate::with('bindings')
            ->where('is_active', true)
            ->get();

        $bestScore = -1;
        $bestId = PHP_INT_MAX;
        $best = null;

        // Normalise enum inputs to string values for comparison
        $filterChannelKind = $this->enumValue($filter['channel_kind'] ?? null);
        $filterPipelineId = isset($filter['pipeline_id']) ? (int) $filter['pipeline_id'] : null;
        $filterStageId = isset($filter['pipeline_stage_id']) ? (int) $filter['pipeline_stage_id'] : null;
        $filterActivityType = $this->enumValue($filter['activity_type'] ?? null);
        $filterSlot = isset($filter['automation_slot']) ? (string) $filter['automation_slot'] : null;

        foreach ($templates as $template) {
            foreach ($template->bindings as $binding) {
                [$matched, $score] = $this->scoreBinding(
                    $binding,
                    $filterChannelKind,
                    $filterPipelineId,
                    $filterStageId,
                    $filterActivityType,
                    $filterSlot,
                );

                if (! $matched) {
                    continue;
                }

                if ($score > $bestScore || ($score === $bestScore && $template->id < $bestId)) {
                    $bestScore = $score;
                    $bestId = $template->id;
                    $best = $template;
                }
            }
        }

        return $best;
    }

    // ---- CRUD helpers ----

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId): MessageTemplate
    {
        $data['created_by_user_id'] = $userId;
        $data['updated_by_user_id'] = $userId;

        return MessageTemplate::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MessageTemplate $template, array $data, int $userId): MessageTemplate
    {
        $data['updated_by_user_id'] = $userId;
        $template->update($data);

        return $template->fresh()->load('bindings');
    }

    /**
     * Soft-delete: sets is_active=false.
     */
    public function deactivate(MessageTemplate $template): void
    {
        $template->update(['is_active' => false]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addBinding(MessageTemplate $template, array $data): MessageTemplateBinding
    {
        $data['message_template_id'] = $template->id;

        $binding = MessageTemplateBinding::create($data);

        // Re-fetch to populate DB-generated fields (created_at via useCurrent()).
        return $binding->fresh();
    }

    // ---- Private ----

    /**
     * Substitute {{key}} placeholders in $text.
     *
     * @param  array<string, string>  $vars
     * @return array{0: string, 1: list<string>}
     */
    private function substitute(string $text, array $vars): array
    {
        /** @var list<string> $unresolved */
        $unresolved = [];

        $result = preg_replace_callback(
            '/\{\{([\w.]+)\}\}/',
            static function (array $m) use ($vars, &$unresolved): string {
                $key = $m[1];
                if (array_key_exists($key, $vars)) {
                    return $vars[$key];
                }
                $unresolved[] = $key;

                return $m[0]; // leave intact
            },
            $text,
        ) ?? $text;

        return [$result, array_values(array_unique($unresolved))];
    }

    /**
     * Score a single binding against the filter.
     * Returns [matched: bool, score: int].
     * score = count of non-null binding fields that matched.
     * Wildcard (all null) → matched=true, score=0.
     *
     * @return array{0: bool, 1: int}
     */
    private function scoreBinding(
        MessageTemplateBinding $binding,
        ?string $filterChannelKind,
        ?int $filterPipelineId,
        ?int $filterStageId,
        ?string $filterActivityType,
        ?string $filterSlot,
    ): array {
        $score = 0;
        $allNull = true;

        // channel_kind
        if ($binding->channel_kind !== null) {
            $allNull = false;
            $bVal = $this->enumValue($binding->channel_kind);
            if ($bVal !== $filterChannelKind) {
                return [false, 0];
            }
            $score++;
        }

        // pipeline_stage_id (checked before pipeline_id — more specific)
        if ($binding->pipeline_stage_id !== null) {
            $allNull = false;
            if ((int) $binding->pipeline_stage_id !== $filterStageId) {
                return [false, 0];
            }
            $score++;
        }

        // pipeline_id
        if ($binding->pipeline_id !== null) {
            $allNull = false;
            if ((int) $binding->pipeline_id !== $filterPipelineId) {
                return [false, 0];
            }
            $score++;
        }

        // activity_type
        if ($binding->activity_type !== null) {
            $allNull = false;
            $bVal = $this->enumValue($binding->activity_type);
            if ($bVal !== $filterActivityType) {
                return [false, 0];
            }
            $score++;
        }

        // automation_slot
        if ($binding->automation_slot !== null) {
            $allNull = false;
            if ($binding->automation_slot !== $filterSlot) {
                return [false, 0];
            }
            $score++;
        }

        // Wildcard: $allNull=true, score=0 — matched
        return [true, $allNull ? 0 : $score];
    }

    /**
     * Resolve enum-or-string to its string value for comparison.
     */
    private function enumValue(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        if ($val instanceof \BackedEnum) {
            return $val->value;
        }

        return (string) $val;
    }
}
