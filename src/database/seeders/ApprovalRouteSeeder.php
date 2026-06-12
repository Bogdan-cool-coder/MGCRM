<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Contracts\Models\ApprovalRoute;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Seeder;

/**
 * ApprovalRouteSeeder — idempotent demo route for contract kind.
 *
 * Creates:
 *   - A lawyer test user (lawyer@mgcrm.test) if not present.
 *   - A director test user (director@mgcrm.test) if not present.
 *   - A default two-stage route: Юрист → Директор.
 *
 * The route is is_default=true so it matches any contract document without
 * a specific template_id match.
 */
class ApprovalRouteSeeder extends Seeder
{
    public function run(): void
    {
        // Create test users for smoke testing if not present.
        $lawyer = User::firstOrCreate(
            ['email' => 'lawyer@mgcrm.test'],
            [
                'full_name' => 'Lawyer Test',
                'password' => bcrypt('password'),
                'role' => 'lawyer',
                'is_active' => true,
                'locale' => 'ru',
                'totp_enabled' => false,
            ]
        );

        $director = User::firstOrCreate(
            ['email' => 'director@mgcrm.test'],
            [
                'full_name' => 'Director Test',
                'password' => bcrypt('password'),
                'role' => 'director',
                'is_active' => true,
                'locale' => 'ru',
                'totp_enabled' => false,
            ]
        );

        // Idempotent: create the default route only if it does not exist.
        ApprovalRoute::firstOrCreate(
            [
                'document_kind' => 'contract',
                'is_default' => true,
                'template_id' => null,
            ],
            [
                'title' => 'Default Contract Approval (Юрист → Директор)',
                'is_active' => true,
                'stages' => [
                    [
                        'order' => 1,
                        'name' => 'Юрист',
                        'user_ids' => [$lawyer->id],
                        'min_required' => 1,
                    ],
                    [
                        'order' => 2,
                        'name' => 'Директор',
                        'user_ids' => [$director->id],
                        'min_required' => 1,
                    ],
                ],
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
            ]
        );
    }
}
