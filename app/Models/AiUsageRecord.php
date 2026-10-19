<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageRecord extends Model
{
    protected $fillable = ['publication_id', 'page_id', 'ai_agent_id', 'model', 'request_id', 'usage', 'duration_ms', 'status'];

    protected function casts(): array
    {
        return ['usage' => 'array'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }
}
