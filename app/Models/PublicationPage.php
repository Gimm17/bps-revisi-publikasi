<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PublicationPage extends Model
{
    protected $fillable = ['publication_id', 'ai_agent_id', 'physical_page', 'status', 'image_path', 'thumbnail_path', 'image_checksum', 'dpi', 'attempts', 'page_type', 'printed_page', 'result', 'text_layer', 'text_layer_status', 'error', 'completed_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'completed_at' => 'datetime'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(PageVerification::class, 'publication_page_id');
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(PageVerification::class, 'publication_page_id')->latestOfMany();
    }
}
