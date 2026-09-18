<?php

declare(strict_types=1);

namespace App\Access;

/**
 * The four roles, and nothing else.
 *
 * Resist adding a fifth until reality demands it: unpicking fifteen roles two
 * years from now is far harder than adding one today.
 *
 * Specialty is deliberately not a role. Registrars, house officers and
 * specialist nurses all need access, and restricting the system to consultants
 * guarantees password sharing — which is worse than granting access, because
 * it destroys the attribution in every record those accounts touch.
 */
final class Role
{
    public const ADMIN = 'admin';

    public const CLINICIAN = 'clinician';

    public const RESEARCH_ASSISTANT = 'research_assistant';

    public const DATA_MANAGER = 'data_manager';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ADMIN, self::CLINICIAN, self::RESEARCH_ASSISTANT, self::DATA_MANAGER];
    }
}
