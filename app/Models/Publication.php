<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Publication extends Model
{
    protected $fillable = ['owner_id', 'title', 'region', 'year', 'kind', 'notes', 'pdf_path', 'pdf_name', 'pdf_checksum', 'template_path', 'template_name', 'template_checksum', 'page_count', 'status', 'manifest', 'context', 'ai_config_id', 'rule_version', 'prompt_version', 'error', 'started_at', 'completed_at', 'locked_at', 'revision'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'context' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    use HasUuids;

    public function checkRuns(): HasMany
    {
        return $this->hasMany(PublicationCheckRun::class)->latest();
    }

    public function pages(): HasMany
    {
        return $this->hasMany(PublicationPage::class)->orderBy('physical_page');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(Export::class)->orderByDesc('version');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function aiConfig(): BelongsTo
    {
        return $this->belongsTo(AiProviderConfig::class, 'ai_config_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereHas('assignees', fn ($a) => $a->where('users.id', $user->id)));
    }
}
