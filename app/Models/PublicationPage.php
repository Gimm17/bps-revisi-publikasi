<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationPage extends Model
{
    protected $fillable = ['publication_id', 'physical_page', 'status', 'image_path', 'thumbnail_path', 'image_checksum', 'dpi', 'attempts', 'page_type', 'printed_page', 'result', 'error', 'completed_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'completed_at' => 'datetime'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
