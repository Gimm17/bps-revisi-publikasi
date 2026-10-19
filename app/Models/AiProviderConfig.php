<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Contracts\AiEndpoint;
use Illuminate\Database\Eloquent\Model;

class AiProviderConfig extends Model implements AiEndpoint
{
    protected $fillable = ['base_url', 'api_key', 'model', 'tested_at', 'vision_tested_at', 'created_by'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'tested_at' => 'datetime', 'vision_tested_at' => 'datetime'];
    }

    protected $hidden = ['api_key'];

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
        return 'provider:legacy:'.hash('sha256', strtolower((string) parse_url($this->base_url, PHP_URL_HOST)).'|'.$this->api_key);
    }

    public function agentId(): ?int
    {
        return null;
    }
}
