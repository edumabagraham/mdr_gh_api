<?php

declare(strict_types=1);

namespace App\Access;

/**
 * The permission matrix, in one place.
 *
 * Note what `admin` does not have: any access to patient data. That is
 * separation of duties, not an oversight. The person who administers accounts
 * has no clinical reason to read the registry, and keeping the two apart is
 * far easier to defend to an ethics committee than explaining why an IT
 * administrator could browse patient records. Someone who genuinely needs both
 * gets two accounts.
 */
final class Permission
{
    public const INVITE_USERS = 'users.invite';

    public const CHANGE_ROLES = 'users.change_role';

    public const VIEW_AUDIT = 'audit.view';

    public const READ_PATIENTS = 'patients.read';

    public const REGISTER_PATIENTS = 'patients.register';

    public const RECORD_ASSESSMENTS = 'assessments.record';

    public const ADMINISTER_QUESTIONNAIRES = 'questionnaires.administer';

    public const CORRECT_DATA = 'data.correct';

    public const EXPORT_DATA = 'data.export';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::INVITE_USERS,
            self::CHANGE_ROLES,
            self::VIEW_AUDIT,
            self::READ_PATIENTS,
            self::REGISTER_PATIENTS,
            self::RECORD_ASSESSMENTS,
            self::ADMINISTER_QUESTIONNAIRES,
            self::CORRECT_DATA,
            self::EXPORT_DATA,
        ];
    }

    /**
     * Section 3 of the slice 0 spec, transcribed. Changing access means
     * changing this table and nothing else.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        return [
            Role::ADMIN => [
                self::INVITE_USERS,
                self::CHANGE_ROLES,
                self::VIEW_AUDIT,
            ],
            Role::CLINICIAN => [
                self::READ_PATIENTS,
                self::REGISTER_PATIENTS,
                self::RECORD_ASSESSMENTS,
                self::ADMINISTER_QUESTIONNAIRES,
                self::CORRECT_DATA,
            ],
            Role::RESEARCH_ASSISTANT => [
                self::READ_PATIENTS,
                self::REGISTER_PATIENTS,
                self::ADMINISTER_QUESTIONNAIRES,
            ],
            Role::DATA_MANAGER => [
                self::VIEW_AUDIT,
                self::READ_PATIENTS,
                self::CORRECT_DATA,
                self::EXPORT_DATA,
            ],
        ];
    }

    /** @return list<string> */
    public static function for(?string $role): array
    {
        return self::matrix()[$role] ?? [];
    }
}
