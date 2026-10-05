<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Export extends Model
{
    protected $fillable = ['publication_id', 'version', 'publication_revision', 'status', 'path', 'checksum', 'finding_count', 'validation', 'error', 'created_by'];

    protected function casts(): array
    {
        return ['validation' => 'array'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
