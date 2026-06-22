<?php

declare(strict_types=1);

namespace App\Domain\Migration\Support;

use App\Domain\Crm\Models\AcquisitionChannel;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Carbon\CarbonImmutable;

/**
 * AmoReferenceResolver — turns AMO ids/codes into MGCRM foreign keys via the
 * curated maps in config('amo_migration'). Temporary migration bounded-context
 * (dropped at M12).
 *
 * One instance is reused across a whole load run; every lookup is cached so the
 * per-deal transaction loop does not re-query users / pipelines / stages /
 * channels thousands of times. Caches are first-hit lazy and keyed by the
 * resolved target, so a cold run warms them on the first deal.
 *
 * Resolution rules (all map data lives in config, see config/amo_migration.php):
 *   - owner:   user_map[amo_user_id] => email => User; unknown / departed reps
 *              fall back to fallback_user_email (the AmoImportUserSeeder account).
 *   - pipeline: status_map[amo_status]→pipeline_code (or the deal's own when the
 *               status is a shared terminal 142/143) → pipelines[code].name → Pipeline.
 *   - stage:    (pipeline_id, stage_code) => PipelineStage.
 *   - channel:  channel_map[enum_id] => AcquisitionChannel name => id.
 */
final class AmoReferenceResolver
{
    /** @var array<string, ?int> email => user id */
    private array $userByEmail = [];

    /** @var array<int, ?int> amo_user_id => user id */
    private array $userByAmoId = [];

    /** @var array<string, ?int> pipeline name => pipeline id */
    private array $pipelineByName = [];

    /** @var array<string, ?int> "pipelineId:code" => stage id */
    private array $stageByCode = [];

    /** @var array<string, ?int> channel name => acquisition channel id */
    private array $channelByName = [];

    private ?int $fallbackUserIdCache = null;

    private bool $fallbackResolved = false;

    /**
     * Resolve the deal owner from an AMO responsible_user_id. Always returns a
     * non-null user id: an unmapped / unknown rep falls back to the import
     * service account so deals.owner_user_id stays NOT NULL.
     */
    public function ownerUserId(?int $amoUserId): int
    {
        return $this->userId($amoUserId) ?? $this->fallbackUserId();
    }

    /**
     * Resolve an AMO user id to an MGCRM user id, or null when there is no match
     * (no map entry, or the mapped email has no MGCRM account). Used for actor /
     * created_by columns that are themselves nullable.
     */
    public function userId(?int $amoUserId): ?int
    {
        if ($amoUserId === null) {
            return null;
        }

        if (array_key_exists($amoUserId, $this->userByAmoId)) {
            return $this->userByAmoId[$amoUserId];
        }

        $email = config('amo_migration.user_map.'.$amoUserId);
        $id = is_string($email) ? $this->userIdByEmail($email) : null;

        return $this->userByAmoId[$amoUserId] = $id;
    }

    public function fallbackUserId(): int
    {
        if ($this->fallbackResolved) {
            return (int) $this->fallbackUserIdCache;
        }

        $email = (string) config('amo_migration.fallback_user_email', 'import-amo@mgcrm.local');
        $id = $this->userIdByEmail($email);

        if ($id === null) {
            // The AmoImportUserSeeder must have run before load. Fail loud rather
            // than silently writing a null owner.
            throw new \RuntimeException(
                "AMO import fallback user '{$email}' not found — run AmoImportUserSeeder before load."
            );
        }

        $this->fallbackResolved = true;

        return (int) ($this->fallbackUserIdCache = $id);
    }

    /**
     * Resolve the target pipeline for an AMO status. Shared terminal statuses
     * (142/143) carry a null pipeline_code in status_map → fall back to the
     * deal's own pipeline (resolved from amo_pipeline_id).
     */
    public function pipelineIdForStatus(int $amoStatusId, ?int $amoPipelineId): ?int
    {
        $entry = config('amo_migration.status_map.'.$amoStatusId);
        $code = is_array($entry) ? ($entry['pipeline_code'] ?? null) : null;

        if ($code !== null) {
            return $this->pipelineIdByCode((string) $code);
        }

        return $amoPipelineId !== null ? $this->pipelineIdByAmoId($amoPipelineId) : null;
    }

    public function pipelineIdByCode(string $code): ?int
    {
        $name = config('amo_migration.pipelines.'.$code.'.name');

        return is_string($name) ? $this->pipelineIdByName($name) : null;
    }

    public function pipelineIdByAmoId(int $amoPipelineId): ?int
    {
        foreach ((array) config('amo_migration.pipelines', []) as $entry) {
            if (is_array($entry) && (int) ($entry['amo_pipeline_id'] ?? 0) === $amoPipelineId) {
                return is_string($entry['name'] ?? null) ? $this->pipelineIdByName($entry['name']) : null;
            }
        }

        return null;
    }

    /**
     * Resolve {pipeline_id, stage_id} for an AMO status. stage_code comes from
     * status_map; pipeline from pipelineIdForStatus (own pipeline for terminals).
     *
     * @return array{pipeline_id: ?int, stage_id: ?int, stage_code: ?string}
     */
    public function stageForStatus(int $amoStatusId, ?int $amoPipelineId): array
    {
        $entry = config('amo_migration.status_map.'.$amoStatusId);
        $stageCode = is_array($entry) ? ($entry['stage_code'] ?? null) : null;
        $pipelineId = $this->pipelineIdForStatus($amoStatusId, $amoPipelineId);

        $stageId = ($pipelineId !== null && is_string($stageCode))
            ? $this->stageId($pipelineId, $stageCode)
            : null;

        return [
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'stage_code' => is_string($stageCode) ? $stageCode : null,
        ];
    }

    public function stageId(int $pipelineId, string $code): ?int
    {
        $key = $pipelineId.':'.$code;

        if (array_key_exists($key, $this->stageByCode)) {
            return $this->stageByCode[$key];
        }

        $id = PipelineStage::query()
            ->where('pipeline_id', $pipelineId)
            ->where('code', $code)
            ->value('id');

        return $this->stageByCode[$key] = $id !== null ? (int) $id : null;
    }

    /**
     * Resolve an AcquisitionChannel id from an AMO channel enum_id via channel_map.
     */
    public function channelIdForEnum(?int $enumId): ?int
    {
        if ($enumId === null) {
            return null;
        }

        $name = config('amo_migration.channel_map.'.$enumId);

        return is_string($name) ? $this->channelIdByName($name) : null;
    }

    public function channelIdByName(string $name): ?int
    {
        if (array_key_exists($name, $this->channelByName)) {
            return $this->channelByName[$name];
        }

        $id = AcquisitionChannel::query()->where('name', $name)->value('id');

        return $this->channelByName[$name] = $id !== null ? (int) $id : null;
    }

    /**
     * Tax-id label for a country code (tax_id_label_map), or null (generic UI).
     */
    public function taxIdLabel(?string $countryCode): ?string
    {
        if ($countryCode === null) {
            return null;
        }

        $label = config('amo_migration.tax_id_label_map.'.$countryCode);

        return is_string($label) ? $label : null;
    }

    /**
     * Convert an AMO Unix timestamp to a Y-m-d date string in Europe/Moscow
     * (AMO's account timezone), or null. Used for the deal plan/fact dates.
     */
    public function toDate(?int $timestamp): ?string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($timestamp, 'Europe/Moscow')->toDateString();
    }

    /**
     * Convert an AMO Unix timestamp to a UTC 'Y-m-d H:i:s' datetime string, or
     * null. Used for backdated created_at / due_at on historical rows.
     */
    public function toDateTime(?int $timestamp): ?string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($timestamp, 'UTC')->format('Y-m-d H:i:s');
    }

    private function userIdByEmail(string $email): ?int
    {
        $email = mb_strtolower(trim($email));

        if (array_key_exists($email, $this->userByEmail)) {
            return $this->userByEmail[$email];
        }

        $id = User::query()->whereRaw('LOWER(email) = ?', [$email])->value('id');

        return $this->userByEmail[$email] = $id !== null ? (int) $id : null;
    }

    private function pipelineIdByName(string $name): ?int
    {
        if (array_key_exists($name, $this->pipelineByName)) {
            return $this->pipelineByName[$name];
        }

        $id = Pipeline::query()->where('name', $name)->value('id');

        return $this->pipelineByName[$name] = $id !== null ? (int) $id : null;
    }
}
