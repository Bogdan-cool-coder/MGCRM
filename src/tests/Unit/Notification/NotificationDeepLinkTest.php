<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Notification\Support\NotificationDeepLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * NotificationDeepLinkTest — guards that every generated deep_link points at a
 * route that actually exists in front/src/router/routes/base.ts. A regression
 * here (e.g. reintroducing the legacy `/tasks`) means the flyout click does
 * nothing, so these cases are locked down.
 *
 * Pure value object → plain PHPUnit\TestCase (no DB, no app container).
 */
final class NotificationDeepLinkTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: int|string|null, 2: ?string}>
     */
    public static function targetCases(): array
    {
        return [
            // Known targets → live entity routes.
            'deal target' => ['deal', 42, '/deals/42'],
            'company target' => ['company', 7, '/companies/7'],
            'contact target' => ['contact', 3, '/contacts/3'],
            'document target' => ['document', 99, '/documents/99'],
            'string id' => ['deal', '15', '/deals/15'],

            // Target-less / unknown → null (no broken link).
            'null type' => [null, 5, null],
            'null id' => ['deal', null, null],
            'unknown type' => ['lead', 5, null],
            'empty id' => ['deal', '', null],
            'zero id' => ['deal', 0, null],
        ];
    }

    #[DataProvider('targetCases')]
    public function test_for_target_maps_to_valid_route_or_null(
        ?string $type,
        int|string|null $id,
        ?string $expected,
    ): void {
        $this->assertSame($expected, NotificationDeepLink::forTarget($type, $id));
    }

    public function test_for_activity_links_to_target_when_present(): void
    {
        $this->assertSame('/deals/42', NotificationDeepLink::forActivity('deal', 42));
        $this->assertSame('/companies/7', NotificationDeepLink::forActivity('company', 7));
        $this->assertSame('/contacts/3', NotificationDeepLink::forActivity('contact', 3));
    }

    public function test_for_activity_falls_back_to_my_tasks_board_not_legacy_tasks(): void
    {
        // Standalone task (no target) — the fallback MUST be the live /my-tasks
        // route, never the dead /tasks path that the old default emitted.
        $this->assertSame('/my-tasks', NotificationDeepLink::forActivity(null, null));
        $this->assertSame('/my-tasks', NotificationDeepLink::tasksBoard());

        // Unknown target type also degrades to the board, not a guessed path.
        $this->assertSame('/my-tasks', NotificationDeepLink::forActivity('unknown', 5));

        $this->assertNotSame('/tasks', NotificationDeepLink::forActivity(null, null));
    }

    public function test_document_helper_returns_document_route(): void
    {
        $this->assertSame('/documents/12', NotificationDeepLink::document(12));
        $this->assertSame('/documents/12', NotificationDeepLink::document('12'));
    }

    public function test_approvals_queue_route(): void
    {
        $this->assertSame('/my-approvals', NotificationDeepLink::approvalsQueue());
    }
}
