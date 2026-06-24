<?php

declare(strict_types=1);

namespace App\Http\Controllers\Iam;

use App\Domain\Iam\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\UserIndexRequest;
use App\Http\Resources\Iam\UserOptionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only colleague directory (Iam context).
 *
 * Feeds assign / responsible dropdowns on the front (e.g. the "Исполнитель"
 * select in ActivityFormDialog). It is a reference list of co-workers, so it is
 * open to any authenticated user — no per-row policy. The list is intentionally
 * thin: active users only, safe fields only (UserOptionResource never exposes
 * secrets). A simple data-wrapped array (no pagination) matches the front's
 * usage in front/src/api/users.ts (res.data.data → flat list).
 */
class UserController extends Controller
{
    public function index(UserIndexRequest $request): AnonymousResourceCollection
    {
        $search = $request->validated('search');

        $users = User::query()
            ->where('is_active', true)
            // Exclude system/service accounts (e.g. the AMO import principal):
            // they must never surface in owner/assignee dropdowns even when left
            // active. is_active alone is not a reliable filter for this.
            ->where('is_service', false)
            ->when(
                $search !== null && $search !== '',
                function ($query) use ($search): void {
                    // LOWER(...) keeps this case-insensitive and portable across
                    // PostgreSQL (runtime) and SQLite (tests). The like operand
                    // is lowered in PHP so the bound value matches.
                    $needle = '%'.mb_strtolower(trim((string) $search)).'%';
                    $query->where(function ($q) use ($needle): void {
                        $q->whereRaw('LOWER(full_name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
                    });
                }
            )
            ->orderBy('full_name')
            ->get();

        return UserOptionResource::collection($users);
    }
}
