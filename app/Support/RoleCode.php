<?php

namespace App\Support;

/**
 * Canonical role codes.
 *
 * This is the single place role identifiers are spelled. Nothing else in the
 * application should contain a literal 'doctor' / 'supplier' / 'super_admin'
 * string for the purpose of an identity decision.
 *
 * Implemented as a constants class rather than a PHP enum on purpose:
 * composer.json declares `php: ^8.0.2`, and native enums require 8.1. Using a
 * class keeps the declared platform floor honest. It can become an enum in the
 * same commit as a deliberate PHP version bump.
 */
final class RoleCode
{
    /** Full administrative identity. Never self-assignable. */
    public const SUPER_ADMIN = 'super_admin';

    /** Clinic-side buyer. */
    public const DOCTOR = 'doctor';

    /** Vendor / selling side. */
    public const SUPPLIER = 'supplier';

    /**
     * Every role the application ships with, in a deterministic order.
     *
     * `assignable_at_registration` is the identity-level guard that replaces the
     * Stage 1 config whitelist: a self-registering user may only ever claim a
     * role flagged true here, and that flag lives in the database so it cannot
     * be influenced by a request payload.
     *
     * @return array<int, array{code:string,name:string,description:string,assignable_at_registration:bool}>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => self::SUPER_ADMIN,
                'name' => 'Super Admin',
                'description' => 'Full administrative access. Assigned out-of-band only.',
                'assignable_at_registration' => false,
            ],
            [
                'code' => self::DOCTOR,
                'name' => 'Doctor',
                'description' => 'Clinic-side buyer.',
                'assignable_at_registration' => true,
            ],
            [
                'code' => self::SUPPLIER,
                'name' => 'Supplier',
                'description' => 'Vendor / selling side.',
                'assignable_at_registration' => true,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_column(self::definitions(), 'code');
    }

    /** @return array<int, string> */
    public static function selfAssignable(): array
    {
        return array_column(
            array_filter(self::definitions(), fn (array $r) => $r['assignable_at_registration']),
            'code'
        );
    }

    /** @return array<int, string> */
    public static function privileged(): array
    {
        return array_values(array_diff(self::all(), self::selfAssignable()));
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && in_array($code, self::all(), true);
    }
}
