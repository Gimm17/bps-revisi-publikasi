<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Contracts\AiEndpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AiAgent extends Model implements AiEndpoint
{
    use HasFactory;

    public const MAX_PRIMARY_SLOTS = 15;

    public const BACKUP_SLOT = 16;

    protected $fillable = [
        'slot',
        'name',
        'base_url',
        'api_key',
        'model',
        'enabled',
        'status',
        'test_message',
        'vision_tested_at',
        'config_hash',
        'max_concurrency',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
            'max_concurrency' => 'integer',
            'vision_tested_at' => 'datetime',
        ];
    }

    public static function computeConfigHash(string $baseUrl, string $model, string $apiKey): string
    {
        return hash('sha256', rtrim($baseUrl, '/').'|'.trim($model).'|'.trim($apiKey));
    }

    public function baseUrl(): string
    {
        return $this->base_url;
    }

    public function apiKey(): string
    {
        return $this->api_key;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function capacityKey(): string
    {
        return 'agent:'.$this->id.':'.hash('sha256', strtolower((string) parse_url($this->base_url, PHP_URL_HOST)).'|'.$this->api_key);
    }

    public function agentId(): ?int
    {
        return $this->id;
    }

    public function isReady(): bool
    {
        return $this->enabled
            && $this->status === 'ready'
            && $this->vision_tested_at !== null
            && $this->config_hash === self::computeConfigHash($this->base_url, $this->model, $this->api_key);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where('status', 'ready')
            ->whereNotNull('vision_tested_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publications(): BelongsToMany
    {
        return $this->belongsToMany(Publication::class, 'publication_ai_agents')
            ->withPivot('config_hash_at_start')
            ->withTimestamps();
    }
}
