<?php

declare(strict_types=1);

namespace Tests\Unit\Iam;

use App\Domain\Iam\Models\User;
use App\Domain\Iam\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_persists_nav_quick_actions(): void
    {
        $user = User::factory()->create();

        $result = (new ProfileService)->update($user, [
            'nav_quick_actions' => ['create_deal', 'create_contact'],
        ]);

        $this->assertSame(['create_deal', 'create_contact'], $result->nav_quick_actions);
        $this->assertSame(['create_deal', 'create_contact'], $user->fresh()->nav_quick_actions);
    }

    public function test_update_reindexes_sparse_arrays(): void
    {
        $user = User::factory()->create();

        $result = (new ProfileService)->update($user, [
            'nav_quick_actions' => [2 => 'create_deal', 5 => 'create_contact'],
        ]);

        // array_values drops the original keys so the stored JSON is a list.
        $this->assertSame(['create_deal', 'create_contact'], $result->nav_quick_actions);
    }

    public function test_update_with_null_clears_actions(): void
    {
        $user = User::factory()->create(['nav_quick_actions' => ['create_deal']]);

        $result = (new ProfileService)->update($user, ['nav_quick_actions' => null]);

        $this->assertNull($result->nav_quick_actions);
        $this->assertNull($user->fresh()->nav_quick_actions);
    }

    public function test_update_leaves_actions_untouched_when_key_absent(): void
    {
        $user = User::factory()->create(['nav_quick_actions' => ['create_deal']]);

        $result = (new ProfileService)->update($user, []);

        $this->assertSame(['create_deal'], $result->nav_quick_actions);
    }
}
