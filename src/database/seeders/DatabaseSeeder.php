<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * BASELINE seeders — system configuration only (roles/permissions, accounts,
     * product catalog, sales pipeline + stages, lost reasons, meeting-report
     * question registry, licensor entities, contract templates + variables,
     * default approval route, message templates). These are re-run by the
     * "Сброс настроек" clean reset (app:reset-clean).
     *
     * NOTE: these are the LEAF baseline seeders, listed directly rather than via
     * the mixed orchestrators (SalesSeeder / ContractsSeeder), because those
     * orchestrators also pull in SAMPLE (business) data. Keeping the leaves here
     * lets the reset run baseline-only without touching deals/documents/etc.
     *
     * @var list<class-string<Seeder>>
     */
    private const BASELINE_SEEDERS = [
        RolePermissionSeeder::class,
        AdminSeeder::class,
        // Org: default department directory (feeds the add-user form Select).
        DepartmentSeeder::class,
        // Catalog (products + prices).
        ProductGroupSeeder::class,
        ProductSeeder::class,
        // CRM directories: acquisition channels, disconnect reasons.
        AcquisitionChannelSeeder::class,
        DisconnectReasonSeeder::class,
        // Sales config: pipeline + stages, lost reasons.
        PipelineSeeder::class,
        // SalesPulse: the two AMO mirror funnels (MACRO Global / MACRO AI Global)
        // — independent of the locked "Продажи" funnel above.
        AmoPipelineSeeder::class,
        LostReasonSeeder::class,
        // Activity: meeting-report question registry (config, deps-free).
        MeetingReportQuestionSeeder::class,
        // Contracts config: licensor entities, templates, template variables,
        // default approval route (also creates lawyer/director test accounts),
        // message templates.
        LicensorEntitySeeder::class,
        TemplateSeeder::class,
        TemplateVariableSeeder::class,
        ApprovalRouteSeeder::class,
        MessageTemplateSeeder::class,
    ];

    /**
     * SAMPLE seeders — demo business data. NOT re-run by the clean reset.
     *
     * @var list<class-string<Seeder>>
     */
    private const SAMPLE_SEEDERS = [
        // Sales: demo deals + deal products.
        DemoDealsSeeder::class,
        // Activity: demo activities (depend on demo deals).
        DemoActivitiesSeeder::class,
        // Manager Cabinet KPI: department/commission/team-target + won deals +
        // salary plans + demo contracts (business data).
        ManagerKpiSeeder::class,
        // SalesPulse: today-anchored deals/activities/stage-history in the two AMO
        // funnels for the three demo managers, so the oversight bot commands show
        // live numbers. Runs after ManagerKpiSeeder (shares the manager accounts).
        SalesPulseDemoSeeder::class,
        // Inbox: demo channel + public form.
        InboxSeeder::class,
        // Contracts: demo documents + revisions.
        DemoDocumentsSeeder::class,
        // Onboarding: demo course, assignments, quiz (content + progress).
        OnboardingSeeder::class,
        // Migration: AMO fallback import service account (DEC-C). Service user,
        // not config — hence SAMPLE, not baseline. Needs the spatie roles
        // (RolePermissionSeeder, baseline) to exist first.
        AmoImportUserSeeder::class,
        // Migration: AMO "Продукт" enum options (94) pre-loaded as skip rows into
        // the amo_product_mappings curation table. SAMPLE (curation data, not
        // config) — human maps each to a catalog product later.
        AmoProductMappingSeeder::class,
    ];

    /**
     * The baseline (config-only) seeder list, shared by app:reset-clean and tests.
     *
     * @return list<class-string<Seeder>>
     */
    public static function baselineSeeders(): array
    {
        return self::BASELINE_SEEDERS;
    }

    /**
     * The sample (business-data) seeder list.
     *
     * @return list<class-string<Seeder>>
     */
    public static function sampleSeeders(): array
    {
        return self::SAMPLE_SEEDERS;
    }

    public function run(): void
    {
        // Full local/staging seed: baseline configuration first, then demo data.
        // ManagerKpiSeeder must run after PipelineSeeder (won stage) — both are
        // ordered correctly across the two lists.
        $this->call([
            ...self::BASELINE_SEEDERS,
            ...self::SAMPLE_SEEDERS,
        ]);
    }
}
