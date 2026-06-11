<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Manager,
            'telegram_user_id' => null,
            'avatar_path' => null,
            'department_id' => null,
            'manager_id' => null,
            'is_active' => true,
            'locale' => 'ru',
            'totp_enabled' => false,
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'backup_codes' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * State: a specific role.
     */
    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes): array => ['role' => $role]);
    }

    /**
     * State: 2FA enabled with a known secret + backup codes.
     *
     * @param  list<string>  $backupCodes  plaintext codes (factory hashes them)
     */
    public function withTwoFactor(string $secret, array $backupCodes = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'totp_enabled' => true,
            'totp_secret' => $secret,
            'totp_enabled_at' => now(),
            'backup_codes' => array_map(static fn (string $code): string => Hash::make($code), $backupCodes),
        ]);
    }

    /**
     * State: deactivated account.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
