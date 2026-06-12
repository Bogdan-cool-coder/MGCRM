<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Models\MeetingReportQuestion;
use App\Domain\Activity\Policies\ActivityPolicy;
use App\Domain\Activity\Policies\MeetingReportQuestionPolicy;
use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Policies\ExchangeRatePolicy;
use App\Domain\Catalog\Policies\ProductGroupPolicy;
use App\Domain\Catalog\Policies\ProductPolicy;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Policies\CompanyPolicy;
use App\Domain\Crm\Policies\ContactPolicy;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\LostReason;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Policies\DealPolicy;
use App\Domain\Sales\Policies\LostReasonPolicy;
use App\Domain\Sales\Policies\PipelinePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Google2FA::class, static fn (): Google2FA => new Google2FA);
    }

    public function boot(): void
    {
        // CRM Policies (ARCHITECTURE.md §3 — no inline role checks)
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);

        // Catalog Policies
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ProductGroup::class, ProductGroupPolicy::class);
        Gate::policy(ExchangeRate::class, ExchangeRatePolicy::class);

        // Sales Policies
        Gate::policy(Deal::class, DealPolicy::class);
        Gate::policy(Pipeline::class, PipelinePolicy::class);
        Gate::policy(LostReason::class, LostReasonPolicy::class);

        // Activity Policies
        Gate::policy(Activity::class, ActivityPolicy::class);
        Gate::policy(MeetingReportQuestion::class, MeetingReportQuestionPolicy::class);

        // Admin-write gate: write operations on shared directories (company-types,
        // contact-positions, sources, countries, cities) and CustomFieldDef are
        // restricted to admin and director roles only.
        Gate::define('admin-write', static fn (User $user): bool => in_array(
            $user->role,
            [Role::Admin, Role::Director],
            strict: true,
        ));

        // Dedup global scan gate: scanning the full database for duplicates is a
        // privileged operation — only admin/director may trigger it.
        Gate::define('dedup-scan-all', static fn (User $user): bool => in_array(
            $user->role,
            [Role::Admin, Role::Director],
            strict: true,
        ));
    }
}
