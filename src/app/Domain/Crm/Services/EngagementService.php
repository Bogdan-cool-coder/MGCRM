<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EngagementService — last_activity_at stamping and tier computation.
 *
 * touch()       — writes last_activity_at to crm_contacts or crm_companies via
 *                 a single UPDATE (no model load to avoid waking Eloquent events).
 *                 Called from ActivityService::create() after every new activity.
 *
 * tierForContact() / tierForCompany() — pure PHP functions; no DB query.
 *                 Safe to call inside API Resources (model already loaded).
 *
 * Thresholds are read from config/crm.php (section 'engagement') so they can be
 * overridden per-env or via .env variables without touching code.
 */
class EngagementService
{
    /**
     * Stamp last_activity_at = now() on a contact or company.
     *
     * @param  'contact'|'company'  $type
     */
    public function touch(string $type, int $entityId): void
    {
        $table = match ($type) {
            'contact' => 'crm_contacts',
            'company' => 'crm_companies',
        };

        DB::table($table)
            ->where('id', $entityId)
            ->whereNull('deleted_at')
            ->update(['last_activity_at' => now()]);
    }

    /**
     * Fan-out touch for a deal's engagement surface: the deal's company and every
     * linked contact (deal_contacts) get last_activity_at = now().
     *
     * The caller resolves the ids (it owns / can read the Sales tables — see
     * Deal::engagementTargets()); this method stays pure (no Sales-table access)
     * so it never crosses the DDD boundary in the wrong direction. It is the
     * single place the "company + each contact" fan-out lives, reused by both the
     * Sales (deal mutations) and Activity (deal-targeted activities) domains.
     *
     * @param  array{company_id: ?int, contact_ids: list<int>}  $targets
     */
    public function touchForDeal(array $targets): void
    {
        $companyId = $targets['company_id'] ?? null;

        if ($companyId !== null) {
            $this->touch('company', $companyId);
        }

        foreach ($targets['contact_ids'] ?? [] as $contactId) {
            $this->touch('contact', (int) $contactId);
        }
    }

    /**
     * Compute engagement tier for a Contact model (no DB query).
     * last_activity_at must already be loaded on the model.
     */
    public function tierForContact(Contact $contact): EngagementTier
    {
        return $this->computeTier(
            $contact->last_activity_at,
            (int) config('crm.engagement.contact.warm_days', 14),
            (int) config('crm.engagement.contact.cold_days', 45),
        );
    }

    /**
     * Compute engagement tier for a Company model (no DB query).
     * last_activity_at must already be loaded on the model.
     */
    public function tierForCompany(Company $company): EngagementTier
    {
        return $this->computeTier(
            $company->last_activity_at,
            (int) config('crm.engagement.company.warm_days', 30),
            (int) config('crm.engagement.company.cold_days', 90),
        );
    }

    /**
     * Pure tier algorithm — no DB access.
     * Null last_activity_at = PHP_INT_MAX days → always Cold.
     *
     * "days" = how many days have elapsed since last_activity_at (always >= 0).
     * Carbon::diffInDays() with absolute=true always returns a non-negative value;
     * since now() > lastActivityAt the result is the days elapsed.
     */
    public function computeTier(?Carbon $lastActivityAt, int $warmDays, int $coldDays): EngagementTier
    {
        if ($lastActivityAt === null) {
            return EngagementTier::Cold;
        }

        // Days elapsed since last touch (absolute, non-negative).
        $days = (int) $lastActivityAt->copy()->startOfDay()->diffInDays(now()->startOfDay());

        if ($days <= $warmDays) {
            return EngagementTier::Fresh;
        }

        if ($days <= $coldDays) {
            return EngagementTier::Cooling;
        }

        return EngagementTier::Cold;
    }
}
