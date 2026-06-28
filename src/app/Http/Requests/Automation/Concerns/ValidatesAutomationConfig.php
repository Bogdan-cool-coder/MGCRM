<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation\Concerns;

use App\Domain\Automation\Enums\ActionKind;
use App\Domain\Automation\Enums\TriggerKind;
use App\Domain\Automation\Exceptions\SsrfBlockedException;
use App\Domain\Automation\Support\SsrfGuard;
use App\Domain\Iam\Enums\Role;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Contracts\Validation\Validator;

/**
 * Discriminated validation of trigger_config / action_config for the automation
 * builder (M7 P4). Both StoreAutomationRequest and UpdateAutomationRequest mix
 * this in so the typed config rules live in one place.
 *
 * The JSON configs are NOT validated as free-form arrays (the ARCHITECTURE rule:
 * no raw arrays — validate discriminated by kind). withValidator() runs after the
 * base rules and, knowing the effective trigger_kind / action_kind, asserts the
 * required-and-shaped fields for THAT kind via match():
 *   - trigger date_field_approaching → { field ∈ whitelist, days ≥ 1 }
 *   - trigger idle_in_stage_days     → { days ≥ 1 }
 *   - action set_field               → { field, value } (field whitelist OR custom)
 *   - action change_stage            → { to_stage_id belongs to this pipeline }
 *   - action webhook                 → { url } admin-only + passes SsrfGuard
 *   - action tg_notify / create_task / generate_document → kind-specific requires
 *
 * Anything not matching its kind's contract is a 422 with a pointed message, so a
 * misconfigured rule can never be saved and then silently `skipped` at runtime.
 */
trait ValidatesAutomationConfig
{
    /**
     * Hook the discriminated config validation after the base rules pass.
     *
     * Laravel calls withValidator() automatically. We only inspect the kind-specific
     * config once the scalar rules (enum membership etc.) have already been checked,
     * to avoid duplicate / confusing errors.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Skip config checks if the kinds themselves are invalid — the base
            // rules already flagged them.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $triggerKind = $this->effectiveTriggerKind();
            $actionKind = $this->effectiveActionKind();

            if ($triggerKind !== null) {
                $this->validateTriggerConfig($validator, $triggerKind);
            }

            if ($actionKind !== null) {
                $this->validateActionConfig($validator, $actionKind);
            }
        });
    }

    /**
     * Per-trigger required fields.
     */
    private function validateTriggerConfig(Validator $validator, TriggerKind $kind): void
    {
        $config = $this->triggerConfigInput();

        match ($kind) {
            TriggerKind::IdleInStageDays => $this->requirePositiveInt(
                $validator,
                $config,
                'trigger_config.days',
                'idle_in_stage_days requires a positive integer "days".',
            ),
            TriggerKind::DateFieldApproaching => $this->validateDateFieldTrigger($validator, $config),
            // on_enter_stage / on_create carry no required config.
            TriggerKind::OnEnterStage, TriggerKind::OnCreate => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateDateFieldTrigger(Validator $validator, array $config): void
    {
        $this->requirePositiveInt(
            $validator,
            $config,
            'trigger_config.days',
            'date_field_approaching requires a positive integer "days".',
        );

        $whitelist = (array) config('automation.date_fields.deal', []);
        $field = isset($config['field']) ? (string) $config['field'] : '';

        if (! in_array($field, $whitelist, true)) {
            $validator->errors()->add(
                'trigger_config.field',
                'date_field_approaching "field" must be one of: '.implode(', ', $whitelist).'.',
            );
        }
    }

    /**
     * Per-action required fields + security gates.
     */
    private function validateActionConfig(Validator $validator, ActionKind $kind): void
    {
        $config = $this->actionConfigInput();

        match ($kind) {
            ActionKind::TgNotify => $this->validateTgNotify($validator, $config),
            ActionKind::CreateTask => $this->validateCreateTask($validator, $config),
            ActionKind::SetField => $this->validateSetField($validator, $config),
            ActionKind::GenerateDocument => $this->requireNonEmptyString(
                $validator,
                $config,
                'action_config.template_code',
                'generate_document requires a "template_code".',
            ),
            ActionKind::ChangeStage => $this->validateChangeStage($validator, $config),
            ActionKind::Webhook => $this->validateWebhook($validator, $config),
            ActionKind::ChangeOwner => $this->validateChangeOwner($validator, $config),
            // email is a forward-compatible no-op — no hard required field.
            ActionKind::Email => null,
        };
    }

    /**
     * tg_notify — non-empty "message" + a recipient spec the RecipientResolver
     * understands. The builder folds its type/user/chat fields into the single
     * `recipient` string ('owner' | 'user_id:N' | 'chat_id:X'); validating the
     * shape here means a malformed spec 422s at save instead of silently
     * resolving to the deal owner at runtime.
     *
     * @param  array<string, mixed>  $config
     */
    private function validateTgNotify(Validator $validator, array $config): void
    {
        $this->requireNonEmptyString(
            $validator,
            $config,
            'action_config.message',
            'tg_notify requires a non-empty "message".',
        );

        // recipient is optional (defaults to "owner") but, if present, must be a
        // recognised spec: owner | user_id:N | chat_id:X.
        if (array_key_exists('recipient', $config)
            && ! $this->isValidTgRecipient($config['recipient'])
        ) {
            $validator->errors()->add(
                'action_config.recipient',
                'tg_notify "recipient" must be "owner", "user_id:N" or "chat_id:X".',
            );
        }
    }

    /**
     * create_task — non-empty "title" + an optional "responsible" spec the
     * RecipientResolver understands ('owner' | 'user_id:N'). The builder folds its
     * assignee_type/user_id fields into this single string; a bad spec 422s at
     * save instead of silently falling back to the deal owner.
     *
     * @param  array<string, mixed>  $config
     */
    private function validateCreateTask(Validator $validator, array $config): void
    {
        $this->requireNonEmptyString(
            $validator,
            $config,
            'action_config.title',
            'create_task requires a non-empty "title".',
        );

        if (array_key_exists('responsible', $config)
            && ! $this->isValidUserSpec($config['responsible'])
        ) {
            $validator->errors()->add(
                'action_config.responsible',
                'create_task "responsible" must be "owner" or "user_id:N".',
            );
        }
    }

    /**
     * "owner" | "user_id:N" (N > 0).
     */
    private function isValidUserSpec(mixed $spec): bool
    {
        if (! is_string($spec)) {
            return false;
        }

        $spec = trim($spec);

        if ($spec === 'owner') {
            return true;
        }

        return (bool) preg_match('/^user_id:[1-9]\d*$/', $spec);
    }

    /**
     * "owner" | "user_id:N" | "chat_id:X" (chat ids may be negative for groups).
     */
    private function isValidTgRecipient(mixed $spec): bool
    {
        if ($this->isValidUserSpec($spec)) {
            return true;
        }

        return is_string($spec) && (bool) preg_match('/^chat_id:-?[1-9]\d*$/', trim($spec));
    }

    /**
     * change_owner — only round_robin is implemented; the optional candidate
     * pool is either an explicit list of user ids or a role/department filter.
     * Validating it here means a misconfigured rule 422s at save instead of
     * silently `skipped` at runtime (and stops the FE offering unimplemented
     * routing rules).
     *
     * @param  array<string, mixed>  $config
     */
    private function validateChangeOwner(Validator $validator, array $config): void
    {
        $rule = isset($config['rule']) ? (string) $config['rule'] : 'round_robin';

        if ($rule !== 'round_robin') {
            $validator->errors()->add(
                'action_config.rule',
                "change_owner only supports the 'round_robin' rule for now.",
            );

            return;
        }

        // Explicit hand-picked pool: must be a non-associative list of positive
        // integer user ids (matches the builder MultiSelect).
        if (array_key_exists('pool', $config)) {
            $pool = $config['pool'];

            $valid = is_array($pool) && array_is_list($pool) && array_reduce(
                $pool,
                static fn (bool $carry, mixed $id): bool => $carry && is_numeric($id) && (int) $id > 0,
                true,
            );

            if (! $valid) {
                $validator->errors()->add(
                    'action_config.pool',
                    'change_owner "pool" must be a list of user ids.',
                );
            }
        }

        // Legacy/API dynamic filter: role (if given) must be a real Role.
        $filter = is_array($config['user_pool_filter'] ?? null) ? $config['user_pool_filter'] : [];

        if (! empty($filter['role']) && Role::tryFrom((string) $filter['role']) === null) {
            $validator->errors()->add(
                'action_config.user_pool_filter.role',
                'change_owner "user_pool_filter.role" must be a valid role.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateSetField(Validator $validator, array $config): void
    {
        $field = isset($config['field']) ? (string) $config['field'] : '';

        if ($field === '') {
            $validator->errors()->add('action_config.field', 'set_field requires a "field".');

            return;
        }

        if (! array_key_exists('value', $config)) {
            $validator->errors()->add('action_config.value', 'set_field requires a "value".');
        }

        // The column whitelist is the security boundary — stage_id / owner /
        // amount / currency are deliberately absent (dedicated actions own them).
        // A non-whitelisted name is only valid if it is a defined custom field;
        // we cannot know that without the deal scope here, so we block only the
        // explicitly sensitive columns to give a clear up-front error and leave
        // unknown-custom-field handling to the runtime (`skipped`).
        $blocked = ['stage_id', 'owner_user_id', 'amount', 'currency', 'password', 'role', 'department_id'];

        if (in_array($field, $blocked, true)) {
            $validator->errors()->add(
                'action_config.field',
                "set_field cannot write the protected field '{$field}' — use a dedicated action.",
            );

            return;
        }

        // Array-cast columns (e.g. tags) must receive an array value: writing a
        // bare scalar into deals.tags (cast: array) would corrupt the column. The
        // FE already shapes tags as a chips list; this guards hand-crafted / API
        // configs from pushing a scalar into a list column.
        if (in_array($field, self::SET_FIELD_ARRAY_COLUMNS, true)
            && array_key_exists('value', $config)
            && ! is_array($config['value'])
        ) {
            $validator->errors()->add(
                'action_config.value',
                "set_field '{$field}' expects a list of values.",
            );
        }
    }

    /**
     * Whitelisted deal columns that are array-cast — their set_field value must be
     * an array, not a scalar. Mirrors the Deal model casts() (tags => array).
     */
    private const array SET_FIELD_ARRAY_COLUMNS = ['tags'];

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateChangeStage(Validator $validator, array $config): void
    {
        $toStageId = isset($config['to_stage_id']) ? (int) $config['to_stage_id'] : 0;

        if ($toStageId <= 0) {
            $validator->errors()->add('action_config.to_stage_id', 'change_stage requires a "to_stage_id".');

            return;
        }

        // Cross-pipeline moves are out of MVP scope: the target stage must belong
        // to this automation's pipeline.
        $pipelineId = $this->effectivePipelineId();

        if ($pipelineId !== null) {
            $belongs = PipelineStage::query()
                ->where('id', $toStageId)
                ->where('pipeline_id', $pipelineId)
                ->exists();

            if (! $belongs) {
                $validator->errors()->add(
                    'action_config.to_stage_id',
                    'change_stage "to_stage_id" must be a stage in this automation\'s pipeline.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateWebhook(Validator $validator, array $config): void
    {
        // webhook is admin-only (it can exfiltrate deal data outbound).
        if ($this->user()?->can('automation.webhook.configure') !== true) {
            $validator->errors()->add(
                'action_kind',
                'The webhook action may only be configured by an administrator.',
            );

            return;
        }

        $url = isset($config['url']) ? (string) $config['url'] : '';

        if ($url === '') {
            $validator->errors()->add('action_config.url', 'webhook requires a "url".');

            return;
        }

        // SSRF guard up-front (mirrors the contracts router 422) so a blocked
        // destination can never be persisted.
        try {
            app(SsrfGuard::class)->assertSafe($url);
        } catch (SsrfBlockedException $e) {
            $validator->errors()->add('action_config.url', "Webhook URL blocked: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requirePositiveInt(Validator $validator, array $config, string $key, string $message): void
    {
        $leaf = $this->leafKey($key);
        $value = $config[$leaf] ?? null;

        if (! is_numeric($value) || (int) $value < 1) {
            $validator->errors()->add($key, $message);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requireNonEmptyString(Validator $validator, array $config, string $key, string $message): void
    {
        $leaf = $this->leafKey($key);
        $value = $config[$leaf] ?? null;

        if (! is_string($value) || trim($value) === '') {
            $validator->errors()->add($key, $message);
        }
    }

    private function leafKey(string $dotted): string
    {
        $parts = explode('.', $dotted);

        return (string) end($parts);
    }

    // ---- Effective values (Store provides them outright; Update may fall back
    //      to the persisted automation) ----

    abstract protected function effectiveTriggerKind(): ?TriggerKind;

    abstract protected function effectiveActionKind(): ?ActionKind;

    abstract protected function effectivePipelineId(): ?int;

    /**
     * @return array<string, mixed>
     */
    abstract protected function triggerConfigInput(): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function actionConfigInput(): array;
}
