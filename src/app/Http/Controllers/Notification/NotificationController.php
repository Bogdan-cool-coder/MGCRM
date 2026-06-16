<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\ListNotificationsRequest;
use App\Http\Resources\Notification\NotificationFeedResource;
use App\Http\Resources\Notification\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin NotificationController (ARCHITECTURE.md §1) — the in-app notification
 * flyout data source (task #9). Always scoped to the authenticated user; the
 * NotificationPolicy guarantees no cross-user access. All logic lives in
 * NotificationService.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $service,
    ) {}

    /**
     * Grouped flyout payload: actionable + paginated feed + digest + unread_count.
     * Returned as a top-level document (no outer "data" envelope, per the
     * NotificationFeedResource $wrap override) — the flyout reads
     * actionable/feed/digest directly.
     */
    public function index(ListNotificationsRequest $request): NotificationFeedResource
    {
        $this->authorize('viewAny', Notification::class);

        return new NotificationFeedResource(
            $this->service->grouped($request->user(), $request->perPage()),
        );
    }

    /** Mark one notification read (idempotent). */
    public function read(Request $request, Notification $notification): NotificationResource
    {
        $this->authorize('update', $notification);

        return new NotificationResource(
            $this->service->markRead($notification),
        );
    }

    /** Mark every unread notification of the caller read. */
    public function readAll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Notification::class);

        $count = $this->service->markAllRead($request->user());

        return response()->json([
            'marked' => $count,
            'unread_count' => $this->service->unreadCount($request->user()),
        ]);
    }
}
