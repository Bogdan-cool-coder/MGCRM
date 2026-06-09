<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Report;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Shared read-access ACL for a single Report. Used by ReportController's read
 * endpoints (show / groupRows / filterOptions) and by
 * UserReportPreferenceController (preferences hang off a report and must
 * follow the same visibility rules) and ChatController's report-scoped
 * endpoints.
 *
 * The actual visibility rules live in the generic AssertsConfigEntityReadAccess
 * trait (shared with Widget / Dashboard). This trait keeps the Report-specific
 * public method names and contract (returns int active company id, throws 403)
 * so existing call-sites don't change.
 *
 * Rules (unchanged):
 *  - Active company is sourced from the ResolveActiveCompany middleware
 *    (users.active_company_id), falling back to user->company_id.
 *  - A report is reachable only if it is a system report OR its company_id
 *    matches the active company. Cross-company access is permitted only for
 *    superadmins.
 *  - Viewers additionally require non-system reports to be is_published.
 */
trait AssertsReportReadAccess
{
    use AssertsConfigEntityReadAccess;

    protected function assertReportAccess(Request $request, Report $report): int
    {
        $user = $request->user();
        $activeCompanyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $this->guardReadable($report, $user, $activeCompanyId);

        return $activeCompanyId;
    }

    /**
     * Variant for callers that hold a report *id* rather than a resolved
     * Report instance (e.g. ChatController's report-scoped endpoints, which
     * take report_id from the request body / query). Loads the report,
     * applies the exact same ACL as assertReportAccess(), and hands back the
     * resolved Report so the caller can keep using it.
     *
     * A missing report is treated as forbidden (403, not 404) so the endpoint
     * never leaks whether a given report id exists in another company.
     *
     * @param  \App\Models\User  $user            the authenticated user
     * @param  int               $activeCompanyId  active company (already resolved by the caller)
     *
     * @throws HttpResponseException 403 when the report is missing or not readable.
     */
    protected function assertReportIdReadable(int $reportId, $user, int $activeCompanyId): Report
    {
        /** @var Report $report */
        $report = $this->assertEntityIdReadable(Report::class, $reportId, $user, $activeCompanyId);

        return $report;
    }
}
