<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Finding extends Model
{
    protected $fillable = ['publication_id', 'check_run_id', 'fingerprint', 'rule_code', 'section', 'severity', 'source', 'confidence', 'observed', 'expected', 'recommendation', 'evidences', 'review_status', 'review_note', 'version', 'deleted'];

    protected function casts(): array
    {
        return ['evidences' => 'array', 'deleted' => 'boolean', 'confidence' => 'float'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function checkRun(): BelongsTo
    {
        return $this->belongsTo(PublicationCheckRun::class, 'check_run_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(FindingAssessment::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FindingRevision::class)->latest();
    }
}
