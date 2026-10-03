<?php

declare(strict_types=1);

namespace App;

final class UploadinyTokenAbility
{
    public const PROJECTS_READ = 'projects:read';

    public const UPLOADS_WRITE = 'uploads:write';

    public const FEEDBACK_READ = 'feedback:read';

    /** @return list<string> */
    public static function phone(): array
    {
        return [self::PROJECTS_READ, self::UPLOADS_WRITE];
    }

    /** @return list<string> */
    public static function agent(): array
    {
        return [self::PROJECTS_READ, self::FEEDBACK_READ];
    }
}
