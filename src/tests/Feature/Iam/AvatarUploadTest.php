<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Self-service avatar upload (POST /api/profile/avatar, DELETE same).
 *
 * Covers storage on the public disk, persistence of a renderable avatar_path,
 * replacement (old file removed), removal, validation, and auth/2fa gating.
 */
class AvatarUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_user_uploads_avatar_and_path_is_persisted(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
        ])->assertOk();

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        $this->assertStringContainsString('/storage/avatars/', $path);

        // Root-relative URL, never absolute: an APP_URL-based absolute URL is
        // cross-origin for the Vite dev server (:5173) and gets blocked by the
        // browser (ERR_BLOCKED_BY_ORB). A relative /storage/... resolves against
        // whichever origin serves the page.
        $this->assertStringStartsWith('/storage/avatars/', $path);
        $this->assertStringNotContainsString('://', $path);

        // The API response carries the same relative path (SPA renders it in <img>).
        $this->assertSame($path, $response->json('data.avatar_path'));

        // The file actually exists on the fake public disk.
        $this->assertSame(1, count(Storage::disk('public')->files('avatars')));
    }

    public function test_uploading_replaces_previous_avatar_file(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('one.jpg'),
        ])->assertOk();
        $first = $user->fresh()->avatar_path;

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('two.png'),
        ])->assertOk();
        $second = $user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        // Only the latest file remains.
        $this->assertSame(1, count(Storage::disk('public')->files('avatars')));
    }

    public function test_user_removes_avatar(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $this->deleteJson('/api/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar_path', null);

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame(0, count(Storage::disk('public')->files('avatars')));
    }

    public function test_upload_rejects_non_image(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');
    }

    public function test_upload_rejects_oversized_file(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('huge.jpg')->size(4096),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');
    }

    public function test_upload_requires_authentication(): void
    {
        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertStatus(401);
    }

    public function test_upload_rejects_temp_2fa_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['2fa:validate']);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertStatus(403);
    }
}
