<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FusionCandidate extends Model
{
    protected $fillable = [
        'publication_id',
        'publication_page_id',
        'physical_page',
        'engine',
        'rule_code',
        'severity',
        'observed',
        'expected',
        'recommendation',
        'evidences',
        'match_group',
        'category',
        'verdict',
        'verdict_reason',
    ];

    protected $casts = [
        'evidences' => 'array',
        'physical_page' => 'integer',
    ];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(PublicationPage::class, 'publication_page_id');
    }
}
