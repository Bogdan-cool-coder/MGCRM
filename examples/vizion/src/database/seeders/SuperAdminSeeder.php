<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Standalone seeder: provisions the three operational superadmins bound to the
 * system Vizion company.
 *
 * NOT registered in DatabaseSeeder — run it explicitly:
 *
 *     php artisan db:seed --class=SuperAdminSeeder
 *
 * Passwords are generated at runtime (never stored in the repo, never read from
 * env) and printed to stdout exactly once, only for newly created accounts.
 * Existing accounts are left untouched (idempotent) — their passwords are never
 * regenerated or overwritten.
 */
class SuperAdminSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const SUPERADMIN_EMAILS = [
        'sh.tursunova@macroglobaltech.com',
        'p.chuhareva@macroglobaltech.com',
        'a.amangeldiyev@macroglobaltech.com',
    ];

    public function run(): void
    {
        $company = Company::where('name', 'Vizion')
            ->where('is_system', true)
            ->first();

        if ($company === null) {
            $this->command?->error(
                'System company "Vizion" not found. Run SystemSeeder first '
                . '(php artisan db:seed --class=SystemSeeder).'
            );

            return;
        }

        /** @var list<array{email: string, password: ?string, created: bool}> $results */
        $results = [];

        foreach (self::SUPERADMIN_EMAILS as $email) {
            $existing = User::where('email', $email)->first();

            if ($existing !== null) {
                // Idempotent path: do NOT touch the password. Only make sure the
                // role/company binding is in place (repairs drifted rows without
                // ever rotating a live credential).
                $this->ensureBinding($existing, $company->id);

                $results[] = [
                    'email' => $email,
                    'password' => null,
                    'created' => false,
                ];

                continue;
            }

            // Strong runtime-generated password: 24 chars, mixed case + digits +
            // symbols. Hashing is handled by the User model's 'password' =>
            // 'hashed' cast, so we assign the plaintext and it is hashed on save.
            $plainPassword = Str::password(24, letters: true, numbers: true, symbols: true, spaces: false);

            User::create([
                'name' => $this->humanName($email),
                'email' => $email,
                'password' => $plainPassword,
                'role' => 'superadmin',
                'company_id' => $company->id,
                'active_company_id' => $company->id,
                'company_accesses' => [
                    ['company_id' => $company->id, 'role' => 'superadmin'],
                ],
            ]);

            $results[] = [
                'email' => $email,
                'password' => $plainPassword,
                'created' => true,
            ];
        }

        $this->printCredentials($results);
    }

    /**
     * Repair role + Vizion binding on an already-existing user without touching
     * the password. Only persists if something actually changed.
     */
    private function ensureBinding(User $user, int $companyId): void
    {
        $dirty = false;

        if ($user->role !== 'superadmin') {
            $user->role = 'superadmin';
            $dirty = true;
        }

        if ((int) $user->company_id !== $companyId) {
            $user->company_id = $companyId;
            $dirty = true;
        }

        if ((int) $user->active_company_id !== $companyId) {
            $user->active_company_id = $companyId;
            $dirty = true;
        }

        if (!$user->canAccessCompany($companyId)) {
            $accesses = $user->company_accesses ?? [];
            $accesses[] = ['company_id' => $companyId, 'role' => 'superadmin'];
            $user->company_accesses = $accesses;
            $dirty = true;
        }

        if ($dirty) {
            $user->save();
        }
    }

    /**
     * Derive a human-readable name from the local part of the email.
     * "sh.tursunova" -> "Sh. Tursunova", "a.amangeldiyev" -> "A. Amangeldiyev".
     */
    private function humanName(string $email): string
    {
        $local = Str::before($email, '@');

        $parts = array_filter(
            preg_split('/[._-]+/', $local) ?: [],
            static fn (string $p): bool => $p !== ''
        );

        $words = [];
        $lastIndex = count($parts) - 1;

        foreach (array_values($parts) as $i => $part) {
            $cap = Str::ucfirst($part);
            // Treat short leading tokens (initials / prefixes) as initials with a
            // trailing dot, e.g. "sh." / "a.".
            $words[] = ($i < $lastIndex && Str::length($part) <= 2)
                ? $cap . '.'
                : $cap;
        }

        return implode(' ', $words);
    }

    /**
     * Print the generated credentials exactly once, in a clearly delimited block
     * so deploy-engineer can capture email -> password pairs from stdout.
     *
     * @param list<array{email: string, password: ?string, created: bool}> $results
     */
    private function printCredentials(array $results): void
    {
        $out = $this->command?->getOutput();

        if ($out === null) {
            return;
        }

        $out->writeln('');
        $out->writeln('=== GENERATED SUPERADMIN CREDENTIALS ===');
        $out->writeln('Bound to system company: Vizion (role: superadmin)');
        $out->writeln('Capture the password values below NOW — they are shown only once.');
        $out->writeln('-----------------------------------------------------------------');

        foreach ($results as $row) {
            if ($row['created']) {
                $out->writeln(sprintf('  %s', $row['email']));
                $out->writeln(sprintf('    password: %s', $row['password']));
            } else {
                $out->writeln(sprintf('  %s', $row['email']));
                $out->writeln('    already exists, password unchanged');
            }
        }

        $out->writeln('=================================================================');
        $out->writeln('');
    }
}
