<?php

namespace App\Enums;

/**
 * Laratrust role slugs used throughout the application.
 *
 * Provides type-safe, autocomplete-friendly access to role names
 * instead of using raw strings like 'admin', 'employer', etc.
 */
enum UserRoleSlug: string
{
    case Admin = 'admin';
    case Engineer = 'engineer';
    case Contractor = 'contractor';
    case Employer = 'employer';
    case Supplier = 'supplier';

    /** Display name for this role. */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'مدیر',
            self::Engineer => 'مهندس',
            self::Contractor => 'پیمانکار',
            self::Employer => 'کارفرما',
            self::Supplier => 'تامین‌کننده',
        };
    }

    /** Returns true for roles considered "expert" (engineer or contractor). */
    public function isExpert(): bool
    {
        return in_array($this, [self::Engineer, self::Contractor]);
    }
}
