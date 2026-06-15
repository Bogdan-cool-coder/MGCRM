<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Models\MeetingReportQuestion;
use App\Domain\Activity\Policies\ActivityPolicy;
use App\Domain\Activity\Policies\MeetingReportQuestionPolicy;
use App\Domain\Automation\Actions\ActionHandler;
use App\Domain\Automation\Actions\ChangeOwnerAction;
use App\Domain\Automation\Actions\ChangeStageAction;
use App\Domain\Automation\Actions\CreateTaskAction;
use App\Domain\Automation\Actions\EmailAction;
use App\Domain\Automation\Actions\GenerateDocumentAction;
use App\Domain\Automation\Actions\SetFieldAction;
use App\Domain\Automation\Actions\TgNotifyAction;
use App\Domain\Automation\Actions\WebhookAction;
use App\Domain\Automation\Listeners\RunOnCreateAutomations;
use App\Domain\Automation\Listeners\RunOnEnterStageAutomations;
use App\Domain\Automation\Models\PipelineAutomation;
use App\Domain\Automation\Policies\PipelineAutomationPolicy;
use App\Domain\Automation\Services\ActionDispatcher;
use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Catalog\Models\ExchangeRate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Policies\ExchangeRatePolicy;
use App\Domain\Catalog\Policies\ProductGroupPolicy;
use App\Domain\Catalog\Policies\ProductPolicy;
use App\Domain\Contracts\Events\ApprovalDecisionMade;
use App\Domain\Contracts\Events\DocumentSubmittedForApproval;
use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Contracts\Policies\ApprovalRoutePolicy;
use App\Domain\Contracts\Policies\DocumentPolicy;
use App\Domain\Contracts\Policies\LicensorPolicy;
use App\Domain\Contracts\Policies\MessageTemplatePolicy;
use App\Domain\Contracts\Policies\TemplatePolicy;
use App\Domain\Contracts\Policies\TemplateVariablePolicy;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Policies\CompanyPolicy;
use App\Domain\Crm\Policies\ContactPolicy;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\Form;
use App\Domain\Inbox\Models\InboundMessage;
use App\Domain\Inbox\Policies\ChannelPolicy;
use App\Domain\Inbox\Policies\FormPolicy;
use App\Domain\Inbox\Policies\InboundMessagePolicy;
use App\Domain\Notification\Listeners\NotifyAuthorListener;
use App\Domain\Notification\Listeners\SendApprovalRequestListener;
use App\Domain\Onboarding\Events\CourseCompleted;
use App\Domain\Onboarding\Listeners\GenerateCertificateListener;
use App\Domain\Onboarding\Models\Certificate;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\Quiz;
use App\Domain\Onboarding\Models\QuizAttempt;
use App\Domain\Onboarding\Models\QuizOption;
use App\Domain\Onboarding\Models\QuizQuestion;
use App\Domain\Onboarding\Policies\AssignmentPolicy;
use App\Domain\Onboarding\Policies\CertificatePolicy;
use App\Domain\Onboarding\Policies\CourseModulePolicy;
use App\Domain\Onboarding\Policies\CoursePolicy;
use App\Domain\Onboarding\Policies\LessonPolicy;
use App\Domain\Onboarding\Policies\QuizAttemptPolicy;
use App\Domain\Onboarding\Policies\QuizOptionPolicy;
use App\Domain\Onboarding\Policies\QuizPolicy;
use App\Domain\Onboarding\Policies\QuizQuestionPolicy;
use App\Domain\Sales\Events\DealCreated;
use App\Domain\Sales\Events\DealStageChanged;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\LostReason;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Policies\DealPolicy;
use App\Domain\Sales\Policies\LostReasonPolicy;
use App\Domain\Sales\Policies\PipelinePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Automation action handlers (M7). Tagged so ActionDispatcher receives the
     * full set without a hard-coded list — binding a new handler here is the
     * only edit needed to add an action_kind.
     *
     * @var list<class-string<ActionHandler>>
     */
    private const AUTOMATION_ACTION_HANDLERS = [
        TgNotifyAction::class,
        CreateTaskAction::class,
        SetFieldAction::class,
        GenerateDocumentAction::class,
        ChangeOwnerAction::class,
        ChangeStageAction::class,
        WebhookAction::class,
        EmailAction::class,
    ];

    public function register(): void
    {
        $this->app->singleton(Google2FA::class, static fn (): Google2FA => new Google2FA);

        // Automation: tag the action handlers and feed them into the dispatcher.
        $this->app->tag(self::AUTOMATION_ACTION_HANDLERS, 'automation.actions');

        $this->app->singleton(
            ActionDispatcher::class,
            static fn ($app): ActionDispatcher => new ActionDispatcher(
                $app->make(AutomationEngine::class),
                $app->tagged('automation.actions'),
            ),
        );
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

        // Inbox Policies (S1.9)
        Gate::policy(Channel::class, ChannelPolicy::class);
        Gate::policy(Form::class, FormPolicy::class);
        Gate::policy(InboundMessage::class, InboundMessagePolicy::class);

        // Contracts Policies (S2.1)
        Gate::policy(LicensorEntity::class, LicensorPolicy::class);
        Gate::policy(Template::class, TemplatePolicy::class);
        Gate::policy(TemplateVariable::class, TemplateVariablePolicy::class);

        // Contracts Policies (S2.2)
        Gate::policy(Document::class, DocumentPolicy::class);

        // Contracts Policies (S2.6)
        Gate::policy(ApprovalRoute::class, ApprovalRoutePolicy::class);

        // Contracts Policies (S2.7)
        Gate::policy(MessageTemplate::class, MessageTemplatePolicy::class);

        // Automation Policies (M7) — builder + runs journal are admin/director
        // only (the `automation.manage` ability).
        Gate::policy(PipelineAutomation::class, PipelineAutomationPolicy::class);

        // Onboarding Policies (S3.1)
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(CourseModule::class, CourseModulePolicy::class);
        Gate::policy(Lesson::class, LessonPolicy::class);

        // Onboarding Policies (S3.3)
        Gate::policy(CourseAssignment::class, AssignmentPolicy::class);

        // Onboarding Policies (S3.2)
        Gate::policy(Quiz::class, QuizPolicy::class);
        Gate::policy(QuizQuestion::class, QuizQuestionPolicy::class);
        Gate::policy(QuizOption::class, QuizOptionPolicy::class);
        Gate::policy(QuizAttempt::class, QuizAttemptPolicy::class);

        // Onboarding Policies (S3.6)
        Gate::policy(Certificate::class, CertificatePolicy::class);

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

        // Named limiter for the public inbound endpoints (form submit + webhook).
        // Per-IP, throttled BEFORE any DB work so a token leak / spam burst can't
        // create a flood of Company/Deal records (S1.9 E5).
        RateLimiter::for('inbound', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('inbox.rate_limit_per_minute', 30),
        )->by($request->ip()));

        // Telegram approval channel (S2.9 — bot-specialist). Listeners are created
        // here (S2.6 dispatches these events without any listeners). They only
        // dispatch queued Jobs, so the web request is not blocked by Telegram I/O.
        Event::listen(DocumentSubmittedForApproval::class, SendApprovalRequestListener::class);
        Event::listen(ApprovalDecisionMade::class, NotifyAuthorListener::class);

        // Onboarding — Certificate generation (S3.6). On CourseCompleted, dispatch
        // GenerateCertificateJob to the queue (never block the HTTP request).
        Event::listen(CourseCompleted::class, GenerateCertificateListener::class);

        // Automation inline triggers (M7 P2). on_create / on_enter_stage fire from
        // Sales events AFTER their transaction commits; the listeners only claim an
        // idempotency slot and queue the action (ExecuteAutomationActionJob), so no
        // Telegram/webhook IO ever blocks the deal create / move web request.
        Event::listen(DealCreated::class, RunOnCreateAutomations::class);
        Event::listen(DealStageChanged::class, RunOnEnterStageAutomations::class);
    }
}
