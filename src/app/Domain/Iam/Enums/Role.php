<?php

declare(strict_types=1);

namespace App\Domain\Iam\Enums;

/**
 * The six fixed RBAC roles of MGCRM.
 *
 * The string values are the spatie/laravel-permission role names seeded by
 * RolePermissionSeeder. Business meaning is documented in config/crm.php.
 * Default visibility scope per role is resolved by VisibilityScope::forRole().
 */
enum Role: string
{
    case Admin = 'admin';
    case Director = 'director';
    case Lawyer = 'lawyer';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Cfo = 'cfo';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
