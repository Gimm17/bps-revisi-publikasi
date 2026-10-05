<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FindingRevision extends Model
{
    protected $fillable = ['finding_id', 'user_id', 'before', 'after', 'reason'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
