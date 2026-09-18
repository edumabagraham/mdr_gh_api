<?php

declare(strict_types=1);

namespace App\Access;

use RuntimeException;

/**
 * A registry with zero administrators needs database surgery to recover, so
 * the last one is not removable through the application.
 */
class LastAdminException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'This is the only active administrator. Appoint another administrator before deactivating this one.'
        );
    }
}
