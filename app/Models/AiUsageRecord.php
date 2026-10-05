<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageRecord extends Model
{
    protected $fillable = ['publication_id', 'page_id', 'model', 'request_id', 'usage', 'duration_ms', 'status'];

    protected function casts(): array
    {
        return ['usage' => 'array'];
    }
}
