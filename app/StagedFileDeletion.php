<?php

declare(strict_types=1);

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string> $paths
 * @property string|null $journal_path
 * @property int $attempts
 */
class StagedFileDeletion extends Model
{
    protected $fillable = ['paths', 'journal_path', 'attempts', 'last_attempt_at', 'last_error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['paths' => 'array', 'attempts' => 'integer', 'last_attempt_at' => 'datetime'];
    }
}
