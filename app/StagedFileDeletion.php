<?php

declare(strict_types=1);

namespace App;

use Illuminate\Database\Eloquent\Model;

/** @property list<string> $paths */
class StagedFileDeletion extends Model
{
    protected $fillable = ['paths'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['paths' => 'array'];
    }
}
