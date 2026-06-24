<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactCompanyLink;
use App\Domain\Crm\Models\DismissedDuplicate;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * DedupService — duplicate detection and merge logic.
 *
 * Dedup criteria:
 *   - Contacts: normalized phone / email / full_name (lowercase trimmed)
 *   - Companies: normalized phone / email / tax_id / name (lowercase trimmed)
 *
 * Normalization is done entirely in PHP before building queries — no DB::raw
 * string literals — so the queries are portable across PostgreSQL and SQLite.
 *
 * Merge is always transactional. Duplicate IDs are soft-deleted after
 * all related links are transferred to the master record.
 */
class DedupService
{
    private const ALLOWED_SCOPES = ['contact', 'company'];

    /**
     * Scan for potential duplicates of a given entity.
     * Returns a collection of candidate models (the possible duplicates).
     * Dismissed pairs are excluded.
     *
     * @return Collection<int, Model>
     */
    public function scan(string $scope, int $entityId): Collection
    {
        $this->assertScope($scope);

        if ($scope === 'contact') {
            return $this->scanContact($entityId);
        }

        return $this->scanCompany($entityId);
    }

    /**
     * Global scan: group all records in scope that share a normalized
     * phone / email / name (contacts) or phone / email / tax_id / name (companies).
     *
     * Returns groups as a SupportCollection of arrays:
     *   [ ['key' => '<criterion>:<value>', 'entities' => Collection<Model>], ... ]
     *
     * Visibility scoping:
     *   - Admin / Director: all records
     *   - Everyone else (Manager, etc.): only owned records
     *
     * Dismissed pairs are NOT filtered here (global view) — the UI can handle
     * individual dismissals within a group.
     *
     * @return SupportCollection<int, array{key: string, entities: Collection<int, Model>}>
     */
    public function scanAll(string $scope, User $user): SupportCollection
    {
        $this->assertScope($scope);

        if ($scope === 'contact') {
            return $this->scanAllContacts($user);
        }

        return $this->scanAllCompanies($user);
    }

    /**
     * Merge one or more duplicate records into a master record.
     * Transfers ContactCompanyLink rows; other relations (deals, tasks, activities)
     * are stubs here — each domain service is responsible for its own FK migration
     * (hooked via observer or called explicitly in S1.3+).
     *
     * @param  int[]  $duplicateIds
     */
    public function merge(string $scope, int $masterId, array $duplicateIds, User $actor): void
    {
        $this->assertScope($scope);

        if (in_array($masterId, $duplicateIds, true)) {
            throw new InvalidArgumentException('Master ID must not appear in duplicate IDs.');
        }

        DB::transaction(function () use ($scope, $masterId, $duplicateIds): void {
            foreach ($duplicateIds as $dupId) {
                if ($scope === 'contact') {
                    $this->mergeContact($masterId, $dupId);
                } else {
                    $this->mergeCompany($masterId, $dupId);
                }

                // Clean up dismissed-pair records involving the merged duplicate
                DismissedDuplicate::where('entity_type', $scope)
                    ->where(function ($q) use ($dupId): void {
                        $q->where('entity_a_id', $dupId)
                            ->orWhere('entity_b_id', $dupId);
                    })->delete();
            }
        });
    }

    /**
     * Mark a pair as "not a duplicate" — scan will not surface them again.
     */
    public function dismiss(string $scope, int $entityAId, int $entityBId, User $actor): void
    {
        $this->assertScope($scope);

        // Normalize: a < b
        [$a, $b] = $entityAId < $entityBId
            ? [$entityAId, $entityBId]
            : [$entityBId, $entityAId];

        DismissedDuplicate::firstOrCreate([
            'entity_type' => $scope,
            'entity_a_id' => $a,
            'entity_b_id' => $b,
        ], [
            'dismissed_by_user_id' => $actor->id,
            'dismissed_at' => now(),
        ]);
    }

    // ---- Private helpers ----

    /** @return Collection<int, Contact> */
    private function scanContact(int $contactId): Collection
    {
        $contact = Contact::findOrFail($contactId);

        // Collect dismissed IDs for this contact
        $dismissed = $this->dismissedIds('contact', $contactId);

        // Normalize values in PHP — avoids DB::raw string-literal quoting
        // issues across PostgreSQL (double-quote = identifier) and SQLite.
        $orConditions = [];

        if ($contact->phone) {
            $orConditions['phone_normalized'] = $this->normalizePhone($contact->phone);
        }

        if ($contact->email) {
            $orConditions['email_lower'] = mb_strtolower(trim($contact->email));
        }

        $orConditions['name_lower'] = $this->normalizeName($contact->full_name ?? '');

        $candidates = Contact::query()
            ->where('id', '!=', $contactId)
            ->whereNotIn('id', $dismissed)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($orConditions, $contact): void {
                if (isset($orConditions['phone_normalized']) && $contact->phone) {
                    // Fetch by the raw stored value — we compare normalized
                    // values from PHP against all candidates after retrieval
                    // to avoid non-portable REPLACE chains in SQL.
                    // For phone: use a LIKE with the raw value as fallback,
                    // then post-filter. But simpler: store & compare normalized.
                    // Since we can't alter schema, we use a broad OR and filter in PHP.
                    // We do: phone IS NOT NULL (broad) OR email=lower OR name=lower,
                    // then the PHP normalizePhone post-filter handles phone strictly.
                    $q->orWhereNotNull('phone');
                }

                if (isset($orConditions['email_lower'])) {
                    $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$orConditions['email_lower']]);
                }

                if (isset($orConditions['name_lower'])) {
                    $q->orWhereRaw('LOWER(TRIM(full_name)) = ?', [$orConditions['name_lower']]);
                }
            })
            ->get();

        // Post-filter: for phone, compare normalized values in PHP (avoids
        // SQL REPLACE chains that differ between PG and SQLite).
        if (isset($orConditions['phone_normalized']) && $contact->phone) {
            $myPhone = $orConditions['phone_normalized'];

            return $candidates->filter(function (Contact $c) use ($myPhone, $orConditions): bool {
                // Keep if phone matches (normalized) …
                if ($c->phone && $this->normalizePhone($c->phone) === $myPhone) {
                    return true;
                }

                // … or if email matches
                if (isset($orConditions['email_lower']) && $c->email
                    && mb_strtolower(trim($c->email)) === $orConditions['email_lower']) {
                    return true;
                }

                // … or if name matches
                if (isset($orConditions['name_lower']) && $c->full_name
                    && $this->normalizeName($c->full_name) === $orConditions['name_lower']) {
                    return true;
                }

                return false;
            })->values();
        }

        return $candidates;
    }

    /** @return Collection<int, Company> */
    private function scanCompany(int $companyId): Collection
    {
        $company = Company::findOrFail($companyId);

        $dismissed = $this->dismissedIds('company', $companyId);

        $orConditions = [];

        if ($company->phone) {
            $orConditions['phone_normalized'] = $this->normalizePhone($company->phone);
        }

        if ($company->email) {
            $orConditions['email_lower'] = mb_strtolower(trim($company->email));
        }

        if ($company->tax_id) {
            $orConditions['tax_id_trimmed'] = trim($company->tax_id);
        }

        $orConditions['name_lower'] = $this->normalizeName($company->name ?? '');

        $candidates = Company::query()
            ->where('id', '!=', $companyId)
            ->whereNotIn('id', $dismissed)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($orConditions, $company): void {
                if (isset($orConditions['phone_normalized']) && $company->phone) {
                    $q->orWhereNotNull('phone');
                }

                if (isset($orConditions['email_lower'])) {
                    $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$orConditions['email_lower']]);
                }

                if (isset($orConditions['tax_id_trimmed'])) {
                    $q->orWhereRaw('TRIM(tax_id) = ?', [$orConditions['tax_id_trimmed']]);
                }

                if (isset($orConditions['name_lower'])) {
                    $q->orWhereRaw('LOWER(TRIM(name)) = ?', [$orConditions['name_lower']]);
                }
            })
            ->get();

        // PHP post-filter for phone (same reason as contacts)
        if (isset($orConditions['phone_normalized']) && $company->phone) {
            $myPhone = $orConditions['phone_normalized'];

            return $candidates->filter(function (Company $c) use ($myPhone, $orConditions): bool {
                if ($c->phone && $this->normalizePhone($c->phone) === $myPhone) {
                    return true;
                }

                if (isset($orConditions['email_lower']) && $c->email
                    && mb_strtolower(trim($c->email)) === $orConditions['email_lower']) {
                    return true;
                }

                if (isset($orConditions['tax_id_trimmed']) && $c->tax_id
                    && trim($c->tax_id) === $orConditions['tax_id_trimmed']) {
                    return true;
                }

                if (isset($orConditions['name_lower']) && $c->name
                    && $this->normalizeName($c->name) === $orConditions['name_lower']) {
                    return true;
                }

                return false;
            })->values();
        }

        return $candidates;
    }

    /**
     * Global contact scan: groups contacts by shared normalized email / phone / name.
     * Visibility-scoped: non-admin/director users see only their own contacts.
     *
     * After collecting raw per-criterion groups, overlapping groups (groups that
     * share at least one entity ID) are merged via connected-component union so
     * each entity appears in at most one output group.
     *
     * @return SupportCollection<int, array{key: string, entities: Collection<int, Contact>}>
     */
    private function scanAllContacts(User $user): SupportCollection
    {
        $isPrivileged = in_array($user->role, [Role::Admin, Role::Director], true);

        $base = Contact::query()->whereNull('deleted_at');

        if (! $isPrivileged) {
            $base->where('owner_id', $user->id);
        }

        /** @var Collection<int, Contact> $all */
        $all = $base->get();

        // Build keyed map: id → model for fast lookup
        $byId = $all->keyBy('id');

        // Collect raw per-criterion groups: [['key'=>..., 'ids'=>[...]]]
        $raw = [];

        // Group by normalized email
        $all->filter(fn (Contact $c): bool => (bool) $c->email)
            ->groupBy(fn (Contact $c): string => mb_strtolower(trim($c->email)))
            ->filter(fn ($g): bool => $g->count() > 1)
            ->each(function ($g, string $key) use (&$raw): void {
                $raw[] = ['key' => 'email:'.$key, 'ids' => $g->pluck('id')->all()];
            });

        // Group by normalized phone
        $all->filter(fn (Contact $c): bool => (bool) $c->phone)
            ->groupBy(fn (Contact $c): string => $this->normalizePhone($c->phone))
            ->filter(fn ($g, string $k): bool => $g->count() > 1 && $k !== '')
            ->each(function ($g, string $key) use (&$raw): void {
                $raw[] = ['key' => 'phone:'.$key, 'ids' => $g->pluck('id')->all()];
            });

        // Group by normalized full_name
        $all->filter(fn (Contact $c): bool => (bool) $c->full_name)
            ->groupBy(fn (Contact $c): string => $this->normalizeName($c->full_name))
            ->filter(fn ($g): bool => $g->count() > 1)
            ->each(function ($g, string $key) use (&$raw): void {
                $raw[] = ['key' => 'name:'.$key, 'ids' => $g->pluck('id')->all()];
            });

        return $this->mergeOverlappingGroups($raw, $byId);
    }

    /**
     * Global company scan: groups companies by shared normalized email / phone / tax_id / name.
     *
     * @return SupportCollection<int, array{key: string, entities: Collection<int, Company>}>
     */
    private function scanAllCompanies(User $user): SupportCollection
    {
        $isPrivileged = in_array($user->role, [Role::Admin, Role::Director], true);

        $base = Company::query()->whereNull('deleted_at');

        if (! $isPrivileged) {
            $base->where(function ($q) use ($user): void {
                $q->where('owner_user_id', $user->id)
                    ->orWhere('responsible_user_id', $user->id);
            });
        }

        /** @var Collection<int, Company> $all */
        $all = $base->get();

        $byId = $all->keyBy('id');

        $raw = [];

        // Group by normalized email
        $all->filter(fn (Company $c): bool => (bool) $c->email)
            ->groupBy(fn (Company $c): string => mb_strtolower(trim($c->email)))
            ->filter(fn ($g): bool => $g->count() > 1)
            ->each(function ($g, string $key) use (&$raw): void {
                $raw[] = ['key' => 'email:'.$key, 'ids' => $g->pluck('id')->all()];
            });

        // Group by normalized phone
        $all->filter(fn (Company $c): bool => (bool) $c->phone)
            ->groupBy(fn (Company $c): string => $this->normalizePhone($c->phone))
            ->filter(fn ($g, string $k): bool => $g->count() > 1 && $k !== '')
            ->each(function ($g, string $key) use (&$raw): void {
                $raw[] = ['key' => 'phone:'.$key, 'ids' => $g->pluck('id')->all()];
            });

        // Group by normalized tax_id
        $all->filter(fn (Company $c): bool => (bool) $c->tax_id)
            ->groupBy(fn (Company $c): string => trim($c->tax_id))
            ->filter(fn ($g): bool => $g->count() > 1)
            ->each(function ($g, string $key) use (&$raw): void {
                $raw[] = ['key' => 'tax_id:'.$key, 'ids' => $g->pluck('id')->all()];
            });

        // Group by normalized name
        $all->filter(fn (Company $c): bool => (bool) $c->name)
            ->groupBy(fn (Company $c): string => $this->normalizeName($c->name))
            ->filter(fn ($g): bool => $g->count() > 1)
            ->each(function ($g, string $key) use (&$raw): void {
                $raw[] = ['key' => 'name:'.$key, 'ids' => $g->pluck('id')->all()];
            });

        return $this->mergeOverlappingGroups($raw, $byId);
    }

    /**
     * Union-find: merge raw per-criterion groups whose entity-ID sets overlap.
     *
     * Input $raw is an array of ['key'=>string, 'ids'=>int[]] tuples.
     * Any two groups sharing at least one ID are collapsed into a single
     * output group; their keys are concatenated with '|'.
     *
     * This guarantees each entity ID appears in exactly one output group.
     *
     * @param  array<int, array{key: string, ids: int[]}>  $raw
     * @param  SupportCollection<int, Model>  $byId
     * @return SupportCollection<int, array{key: string, entities: Collection<int, Model>}>
     */
    private function mergeOverlappingGroups(array $raw, SupportCollection $byId): SupportCollection
    {
        // parent[i] = representative index for group i
        $n = count($raw);
        $parent = range(0, $n - 1);

        $find = function (int $x) use (&$parent, &$find): int {
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]); // path compression
            }

            return $parent[$x];
        };

        $union = function (int $a, int $b) use (&$parent, &$find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        // For each entity ID track which group indices contain it
        // so we can union groups that share an ID.
        $idToGroups = [];
        for ($i = 0; $i < $n; $i++) {
            foreach ($raw[$i]['ids'] as $id) {
                $idToGroups[$id][] = $i;
            }
        }

        // Union all groups that share an ID
        foreach ($idToGroups as $groupIndices) {
            $first = $groupIndices[0];
            for ($j = 1; $j < count($groupIndices); $j++) {
                $union($first, $groupIndices[$j]);
            }
        }

        // Aggregate: root → merged group data
        $merged = [];
        for ($i = 0; $i < $n; $i++) {
            $root = $find($i);
            if (! isset($merged[$root])) {
                $merged[$root] = ['keys' => [], 'ids' => []];
            }

            $merged[$root]['keys'][] = $raw[$i]['key'];
            foreach ($raw[$i]['ids'] as $id) {
                $merged[$root]['ids'][$id] = true; // use map to deduplicate IDs
            }
        }

        return collect(array_values($merged))
            ->map(function (array $group) use ($byId): array {
                $ids = array_keys($group['ids']);
                $entities = collect($ids)
                    ->map(fn (int $id) => $byId->get($id))
                    ->filter()
                    ->values();

                return [
                    'key' => implode('|', $group['keys']),
                    'entities' => $entities,
                ];
            })
            ->values();
    }

    /**
     * Transfer Contact's company links to master, then soft-delete duplicate.
     */
    private function mergeContact(int $masterId, int $dupId): void
    {
        // Transfer company links — skip if master already has a link to same company
        $masterLinks = ContactCompanyLink::where('contact_id', $masterId)
            ->pluck('company_id')
            ->all();

        ContactCompanyLink::where('contact_id', $dupId)
            ->whereNotIn('company_id', $masterLinks)
            ->update(['contact_id' => $masterId, 'is_primary' => false]);

        // Delete orphaned links (master already has them)
        ContactCompanyLink::where('contact_id', $dupId)->delete();

        // Soft-delete the duplicate
        Contact::where('id', $dupId)->delete();
    }

    /**
     * Transfer ALL of the duplicate company's child FK rows to master, then
     * soft-delete the duplicate. Must be called inside an existing DB::transaction.
     *
     * Tables re-parented (in order, to respect FK dependencies):
     *   1. crm_contact_company_links  — pivot (skip if master already has the contact)
     *   2. deals                      — deals.company_id
     *   3. documents                  — documents.source_company_id
     *   4. company_requisites         — company_requisites.company_id
     *   5. company_channels           — company_channels.company_id
     *   6. company_client_status_log  — company_client_status_log.company_id
     *   7. crm_companies (holding)    — crm_companies.holding_id (subsidiaries of dup → master)
     *   8. acquisition_channel_history — polymorphic entity_id where entity_type='company'
     *
     * After re-parenting, the duplicate is soft-deleted (SoftDeletes sets deleted_at).
     * DB-level FK CASCADE/RESTRICT actions do NOT fire on soft-delete, so we must
     * reassign every referencing row explicitly here before the soft-delete.
     */
    private function mergeCompany(int $masterId, int $dupId): void
    {
        // 1. Contact-company pivot: transfer links not already on master.
        $masterContactIds = ContactCompanyLink::where('company_id', $masterId)
            ->pluck('contact_id')
            ->all();

        ContactCompanyLink::where('company_id', $dupId)
            ->whereNotIn('contact_id', $masterContactIds)
            ->update(['company_id' => $masterId, 'is_primary' => false]);

        // Delete any remaining links (master already has them — duplicates).
        ContactCompanyLink::where('company_id', $dupId)->delete();

        // 2. Deals: re-parent deals.company_id.
        DB::table('deals')
            ->where('company_id', $dupId)
            ->update(['company_id' => $masterId]);

        // 3. Documents: re-parent source_company_id (nullable FK).
        DB::table('documents')
            ->where('source_company_id', $dupId)
            ->update(['source_company_id' => $masterId]);

        // 4. Requisites: re-parent company_requisites.company_id.
        DB::table('company_requisites')
            ->where('company_id', $dupId)
            ->update(['company_id' => $masterId]);

        // 5. Company channels: re-parent company_channels.company_id.
        DB::table('company_channels')
            ->where('company_id', $dupId)
            ->update(['company_id' => $masterId]);

        // 6. Client status log: re-parent company_client_status_log.company_id.
        DB::table('company_client_status_log')
            ->where('company_id', $dupId)
            ->update(['company_id' => $masterId]);

        // 7. Holding tree: subsidiaries pointing at the dup become children of master.
        DB::table('crm_companies')
            ->where('holding_id', $dupId)
            ->update(['holding_id' => $masterId]);

        // 8. Polymorphic acquisition-channel history (entity_type = 'company').
        DB::table('acquisition_channel_history')
            ->where('entity_type', 'company')
            ->where('entity_id', $dupId)
            ->update(['entity_id' => $masterId]);

        // Finally: soft-delete the now-orphan-free duplicate.
        Company::where('id', $dupId)->delete();
    }

    /**
     * Returns IDs of entities that have been dismissed as "not duplicates" of the given entity.
     *
     * @return int[]
     */
    private function dismissedIds(string $scope, int $entityId): array
    {
        $pairs = DismissedDuplicate::where('entity_type', $scope)
            ->where(function ($q) use ($entityId): void {
                $q->where('entity_a_id', $entityId)
                    ->orWhere('entity_b_id', $entityId);
            })
            ->get(['entity_a_id', 'entity_b_id']);

        return $pairs->flatMap(function ($row) use ($entityId): array {
            return [$row->entity_a_id === $entityId ? $row->entity_b_id : $row->entity_a_id];
        })->all();
    }

    /** Normalize phone to digits-only string for comparison. */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    /** Lowercase + trim for name comparison. */
    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function assertScope(string $scope): void
    {
        if (! in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new InvalidArgumentException(
                "Invalid dedup scope '{$scope}'. Allowed: ".implode(', ', self::ALLOWED_SCOPES)
            );
        }
    }
}
