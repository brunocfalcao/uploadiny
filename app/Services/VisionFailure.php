<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class VisionFailure extends RuntimeException
{
    public function __construct(public readonly string $category, string $message, int $status = 0)
    {
        parent::__construct($message, $status);
    }
}
