<?php

declare(strict_types=1);

namespace App\Access;

/**
 * invited → active ⇄ suspended → departed
 *
 * User rows are never deleted. Every assessment, registration and audit entry
 * attributes to a user, and a deleted row turns that attribution into null —
 * which in a clinical registry means no longer being able to say who recorded
 * a value. Departure is a status change.
 */
final class AccountStatus
{
    public const ACTIVE = 'active';

    /** Temporarily blocked: inactivity, leave, a lapsed credential. Reversible. */
    public const SUSPENDED = 'suspended';

    /** No longer at the hospital. Reversible only by explicit admin action. */
    public const DEPARTED = 'departed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACTIVE, self::SUSPENDED, self::DEPARTED];
    }
}
